<?php

namespace App\Services\Erp\Handlers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductModel;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * US-13 v3: Обработка события product.updated из 1С.
 * Частичное обновление — обновляет только поля, переданные в payload.
 * Не затрагивает поля, отсутствующие в сообщении.
 * base_price не обновляется (используйте price.updated).
 *
 * v13: атрибуты синхронизируются по full-replace (см. docs-erp/rules/catalog.md):
 *   - поле attributes отсутствует → состав атрибутов товара не трогаем
 *   - attributes: []            → удалить все атрибуты товара
 *   - attributes: [...]         → полная замена: записи в product_attribute_values,
 *                                 отсутствующие в массиве, удаляются
 *
 * Под 6 параллельными воркерами erp_in.catalog оборачиваем транзакцию в retry()
 * для обработки deadlock/1062 на общих справочных таблицах (attributes, attribute_values,
 * attribute_category).
 */
class HandleProductUpdated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('product.updated: отсутствует обязательное поле uuid', [
                'payload' => $payload,
            ]);

            return;
        }

        retry([100, 250, 500, 1000], function () use ($payload): void {
            $this->processPayload($payload);
        }, function (int $attempt, Throwable $e) use ($uuid): int {
            Log::warning('product.updated: deadlock, повторная попытка обработки', [
                'product_uuid' => $uuid,
                'attempt' => $attempt + 1,
                'error' => $e->getMessage(),
            ]);

            return match ($attempt) {
                1 => 100,
                2 => 250,
                3 => 500,
                default => 1000,
            };
        }, function (Throwable $e): bool {
            return $this->shouldRetryOnDeadlock($e);
        });
    }

    protected function processPayload(array $payload): void
    {
        $uuid = $payload['uuid'];

        $product = Product::withoutGlobalScopes()->where('external_id', $uuid)->first();

        if (! $product) {
            Log::warning('product.updated: товар не найден', ['uuid' => $uuid]);

            return;
        }

        DB::transaction(function () use ($product, $payload, $uuid) {
            $updateData = [];

            if (array_key_exists('name', $payload)) {
                $updateData['name'] = $payload['name'];
            }

            if (array_key_exists('code', $payload)) {
                $updateData['code'] = $payload['code'];
            }

            if (array_key_exists('sku', $payload)) {
                $updateData['sku'] = $payload['sku'];
            }

            if (array_key_exists('description', $payload)) {
                $updateData['description'] = $payload['description'];
            }

            if (array_key_exists('description_html', $payload)) {
                $updateData['description_html'] = $payload['description_html'];
            }

            if (array_key_exists('category_uuid', $payload)) {
                $categoryUuid = $payload['category_uuid'];
                if ($categoryUuid) {
                    $category = Category::where('uuid', $categoryUuid)->first();
                    $updateData['category_id'] = $category?->id;
                } else {
                    $updateData['category_id'] = null;
                }
            }

            if (array_key_exists('brand', $payload)) {
                $brandData = $payload['brand'];
                if ($brandData) {
                    $updateData['brand_id'] = $this->resolveBrandId($brandData);
                } else {
                    $updateData['brand_id'] = null;
                }
            }

            if (array_key_exists('model', $payload)) {
                $ignoreIncoming = (bool) config('erp.product_models.ignore_incoming', false);
                $preserveExisting = (bool) config('erp.product_models.preserve_existing', true);
                if ($ignoreIncoming) {
                    // Заглушка: модель из 1С не принимаем — model_id не трогаем.
                    Log::info('product.updated: модель из 1С проигнорирована (ignore_incoming)', [
                        'product_uuid' => $uuid,
                        'current_model_id' => $product->model_id,
                    ]);
                } elseif ($preserveExisting && $product->model_id !== null) {
                    Log::info('product.updated: model_id сохранён (preserve_existing)', [
                        'product_uuid' => $uuid,
                        'current_model_id' => $product->model_id,
                    ]);
                } else {
                    $modelData = $payload['model'];
                    if (! empty($modelData)) {
                        $updateData['model_id'] = $this->resolveModelId($modelData);
                    } else {
                        $updateData['model_id'] = null;
                    }
                }
            }

            if (array_key_exists('hidden', $payload)) {
                $updateData['hidden'] = (bool) $payload['hidden'];
            }

            if (array_key_exists('is_marked', $payload)) {
                $updateData['is_marked'] = (bool) $payload['is_marked'];
            }

            foreach (['weight_gross', 'weight_net', 'width', 'height', 'depth', 'turnover'] as $numericField) {
                if (array_key_exists($numericField, $payload)) {
                    $updateData[$numericField] = $this->normalizeNumeric($payload[$numericField]);
                }
            }

            if (array_key_exists('hs_code', $payload)) {
                $updateData['hs_code'] = $this->normalizeString($payload['hs_code'], 20);
            }

            if (array_key_exists('abc_xyz', $payload)) {
                $updateData['abc_xyz'] = $this->normalizeString($payload['abc_xyz'], 5);
            }

            // v13.10: аудит-метки 1С (опционально). array_key_exists, чтобы передача null
            // явно очищала поле, а отсутствие ключа сохраняло текущее значение.
            // TZ-нормализация — в App\Casts\ErpDatetime.
            if (array_key_exists('erp_created_at', $payload)) {
                $updateData['erp_created_at'] = $payload['erp_created_at'];
            }
            if (array_key_exists('erp_updated_at', $payload)) {
                $updateData['erp_updated_at'] = $payload['erp_updated_at'];
            }

            if (! empty($updateData)) {
                $product->update($updateData);
            }

            // --- Штрих-коды (полная замена, только если поле передано) ---
            // insertOrIgnore вместо upsert: ON DUPLICATE KEY UPDATE даёт gap-locks
            // на уникальном индексе (product_id, barcode) и при 6 параллельных воркерах
            // регулярно ловит deadlock. INSERT IGNORE при дубликате не берёт gap-lock.
            if (array_key_exists('barcodes', $payload)) {
                $barcodes = $payload['barcodes'] ?? [];
                $barcodeValues = collect($barcodes)
                    ->map(fn ($v) => trim((string) $v))
                    ->filter(fn ($v) => $v !== '')
                    ->unique()
                    ->sort()
                    ->values();

                if ($barcodeValues->isNotEmpty()) {
                    $now = now();
                    $rows = $barcodeValues->map(fn ($b) => [
                        'product_id' => $product->id,
                        'barcode' => $b,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->toArray();
                    DB::table('product_barcodes')->insertOrIgnore($rows);
                    $product->barcodes()->whereNotIn('barcode', $barcodeValues)->delete();
                } else {
                    $product->barcodes()->delete();
                }
            }

            // --- Атрибуты (v13: full-replace) ---
            $processedAttributeIds = [];
            $attributesInPayload = array_key_exists('attributes', $payload);

            if ($attributesInPayload) {
                $attributes = $payload['attributes'] ?? [];

                if (! is_array($attributes)) {
                    $attributes = [];
                }

                foreach ($attributes as $attrData) {
                    $attributeId = $this->syncAttribute($product, $attrData);
                    if ($attributeId !== null) {
                        $processedAttributeIds[] = $attributeId;
                    }
                }

                // Full-replace: удаляем связи товара с атрибутами, которых нет в payload.
                // Пустой массив → удаляются все атрибуты товара.
                $stale = ProductAttributeValue::where('product_id', $product->id);
                if (! empty($processedAttributeIds)) {
                    $stale->whereNotIn('attribute_id', $processedAttributeIds);
                }
                $stale->delete();
            }

            // --- Привязка атрибутов к категории товара ---
            // Атомарный insertOrIgnore вместо syncWithoutDetaching: при 6 воркерах
            // non-atomic SELECT+INSERT ловит Duplicate entry на уникальном индексе.
            if (! empty($processedAttributeIds) && $product->category_id) {
                $now = now();
                $rows = array_map(fn ($aid) => [
                    'attribute_id' => $aid,
                    'category_id' => $product->category_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $processedAttributeIds);
                DB::table('attribute_category')->insertOrIgnore($rows);
            }

            Log::info('product.updated: товар обновлён (частичное)', [
                'uuid' => $uuid,
                'updated_fields' => array_keys($updateData),
                'has_barcodes' => array_key_exists('barcodes', $payload),
                'has_attributes' => $attributesInPayload,
                'attributes_count' => count($processedAttributeIds),
            ]);
        });
    }

    /**
     * Синхронизация одного атрибута товара. Возвращает attribute_id, либо null если
     * запись пропущена (некорректный элемент payload).
     */
    protected function syncAttribute(Product $product, mixed $attrData): ?int
    {
        if (! is_array($attrData)) {
            return null;
        }

        $propertyUuid = $attrData['property_uuid'] ?? null;
        $propertyLabel = $attrData['property_label'] ?? null;
        $valueType = $attrData['value_type'] ?? 'string';
        $valueUuid = $attrData['value_uuid'] ?? null;
        $valueLabel = $attrData['value_label'] ?? null;

        if (! $propertyUuid || ! $propertyLabel) {
            return null;
        }

        // Пустой атрибут (нет ни value_uuid, ни value_label) — не пишем pivot.
        // Возврат null исключает атрибут из $processedAttributeIds → full-replace
        // cleanup в вызывающем коде уберёт устаревшую запись, если она была.
        if (! $valueUuid && ($valueLabel === null || $valueLabel === '')) {
            return null;
        }

        $siteType = match ($valueType) {
            'number' => 'number',
            'boolean' => 'boolean',
            'reference' => 'select',
            'date-time' => 'date-time',
            default => 'string',
        };

        // 1С — мастер-каталог. При конфликте slug с данными из sex-opt.ru
        // перезаписываем external_id на значение из 1С.
        $attribute = Attribute::where('external_id', $propertyUuid)->first();

        if (! $attribute) {
            $baseSlug = Str::slug($propertyLabel) ?: 'attr-'.Str::slug($propertyUuid);
            $attribute = Attribute::where('slug', $baseSlug)->first();

            if ($attribute) {
                $attribute->update([
                    'external_id' => $propertyUuid,
                    'name' => $propertyLabel,
                    'type' => $siteType,
                ]);
            } else {
                $attribute = Attribute::create([
                    'external_id' => $propertyUuid,
                    'name' => $propertyLabel,
                    'slug' => $baseSlug,
                    'type' => $siteType,
                    'is_active' => Attribute::hasCyrillicName($propertyLabel),
                ]);
            }
        } else {
            $attribute->update([
                'name' => $propertyLabel,
                'type' => $siteType,
            ]);
        }

        $attributeValueId = null;
        if ($valueUuid) {
            $attributeValueId = $this->resolveAttributeValueId($attribute->id, $valueUuid, $valueLabel);
        }

        // text_value пишем только для строковых/select-атрибутов — boolean иначе даёт "1".
        $pivotData = [
            'attribute_value_id' => $attributeValueId,
            'text_value' => null,
            'number_value' => null,
            'boolean_value' => null,
            'datetime_value' => null,
        ];

        if ($siteType === 'number' && is_numeric($valueLabel)) {
            $pivotData['number_value'] = (float) $valueLabel;
        } elseif ($siteType === 'boolean') {
            $pivotData['boolean_value'] = filter_var($valueLabel, FILTER_VALIDATE_BOOLEAN);
        } elseif ($siteType === 'date-time' && $valueLabel) {
            try {
                $parsed = \Carbon\Carbon::parse($valueLabel);
                // 1С шлёт стабы 0001-01-01 / 1900-01-01 для незаполненных дат —
                // отбрасываем, чтобы не засорять каталог и не ловить out-of-range.
                if ($parsed->year < 2000) {
                    Log::warning('Атрибут date-time: значение похоже на стаб 1С, пишем NULL', [
                        'product_id' => $product->id,
                        'property_uuid' => $propertyUuid,
                        'value' => $valueLabel,
                    ]);
                } else {
                    $pivotData['datetime_value'] = $parsed->toDateTimeString();
                }
            } catch (\Exception $e) {
                Log::warning('Неверный формат даты атрибута', [
                    'product_id' => $product->id,
                    'property_uuid' => $propertyUuid,
                    'value' => $valueLabel,
                ]);
            }
        } else {
            $pivotData['text_value'] = (string) ($valueLabel ?? '');
        }

        ProductAttributeValue::updateOrCreate(
            [
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
            ],
            $pivotData
        );

        return $attribute->id;
    }

    /**
     * Поиск/создание значения атрибута с защитой от гонки по уникальному
     * индексу (attribute_id, value_hash).
     */
    protected function resolveAttributeValueId(int $attributeId, string $valueUuid, mixed $valueLabel): ?int
    {
        $valueStr = (string) ($valueLabel ?? $valueUuid);
        $valueHash = AttributeValue::hashValue($valueStr);

        $attrValue = AttributeValue::where('external_id', $valueUuid)->first();

        if ($attrValue) {
            $duplicate = AttributeValue::where('attribute_id', $attributeId)
                ->where('value_hash', $valueHash)
                ->where('id', '!=', $attrValue->id)
                ->first();

            if ($duplicate) {
                $attrValue->update(['external_id' => null]);
                $duplicate->update(['external_id' => $valueUuid]);

                return $duplicate->id;
            }

            $attrValue->update([
                'attribute_id' => $attributeId,
                'value' => $valueStr,
            ]);

            return $attrValue->id;
        }

        $attrValue = AttributeValue::where('attribute_id', $attributeId)
            ->where('value_hash', $valueHash)
            ->first();

        if ($attrValue) {
            $attrValue->update(['external_id' => $valueUuid]);

            return $attrValue->id;
        }

        // Атомарный upsert — защита от гонки при параллельных воркерах
        $attrValue = AttributeValue::updateOrCreate(
            ['attribute_id' => $attributeId, 'value_hash' => $valueHash],
            ['value' => $valueStr, 'external_id' => $valueUuid]
        );

        return $attrValue->id;
    }

    protected function shouldRetryOnDeadlock(Throwable $e): bool
    {
        /** @var ConcurrencyErrorDetector $detector */
        $detector = app(ConcurrencyErrorDetector::class);

        if ($detector->causedByConcurrencyError($e)) {
            return true;
        }

        if ($e instanceof QueryException
            && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return true;
        }

        return false;
    }

    /**
     * Привести числовое поле payload к float или null.
     * Принимает int/float/numeric-string. Отрицательные значения и не-числа → null.
     */
    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float < 0 ? null : $float;
    }

    /**
     * Привести строковое поле payload к строке заданной максимальной длины или null.
     */
    private function normalizeString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Поиск/создание бренда.
     * v4: объект {uuid, name} → updateOrCreate по external_id
     * v3 (обратная совместимость): строка → поиск по name
     */
    private function resolveBrandId(mixed $brandData): ?int
    {
        if (is_array($brandData)) {
            $uuid = $brandData['uuid'] ?? null;
            $name = $brandData['name'] ?? null;

            if (! $uuid || ! $name) {
                return null;
            }

            $slug = Str::slug($name) ?: 'brand-'.Str::slug($uuid);

            $brand = Brand::where('external_id', $uuid)->first();

            if ($brand) {
                $brand->update(['name' => $name]);
            } else {
                // Атомарный upsert по slug — защита от гонки и дедупликация с sex-opt.ru
                $brand = Brand::updateOrCreate(
                    ['slug' => $slug],
                    ['external_id' => $uuid, 'name' => $name]
                );
            }

            return $brand->id;
        }

        if (is_string($brandData) && $brandData !== '') {
            $brand = Brand::where('name', $brandData)->first();
            if (! $brand) {
                $baseSlug = Str::slug($brandData) ?: 'brand-'.Str::uuid();
                $slug = $baseSlug;
                $counter = 1;
                while (Brand::where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$counter++;
                }
                $brand = Brand::create(['name' => $brandData, 'slug' => $slug]);
            }

            return $brand->id;
        }

        return null;
    }

    /**
     * Поиск/создание модели товара.
     * Каскадная дедупликация: external_id → name → создание.
     * 1С — мастер-каталог: при конфликте name перезаписываем external_id.
     */
    private function resolveModelId(array $modelData): ?int
    {
        $uuid = $modelData['uuid'] ?? null;
        $name = $modelData['name'] ?? null;
        $code = $modelData['code'] ?? null;

        if (! $uuid && ! $name) {
            return null;
        }

        if ($uuid) {
            $productModel = ProductModel::where('external_id', $uuid)->first();

            if ($productModel) {
                $productModel->update(array_filter([
                    'name' => $name,
                    'code' => $code,
                ]));

                return $productModel->id;
            }
        }

        if ($name) {
            $productModel = ProductModel::where('name', $name)->first();

            if ($productModel) {
                $isManual = $productModel->external_id === null
                    && (bool) config('erp.product_models.preserve_existing', true);

                if (! $isManual) {
                    $productModel->update(array_filter([
                        'external_id' => $uuid,
                        'code' => $code,
                    ]));
                }

                return $productModel->id;
            }
        }

        if ($uuid) {
            $productModel = ProductModel::updateOrCreate(
                ['external_id' => $uuid],
                array_filter(['name' => $name ?? $uuid, 'code' => $code])
            );
        } else {
            $productModel = ProductModel::create(array_filter([
                'name' => $name,
                'code' => $code,
            ]));
        }

        return $productModel->id;
    }
}
