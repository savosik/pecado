<?php

namespace App\Services\Erp\Handlers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductModel;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * US-13: Обработка события product.created из 1С.
 * Создаёт или обновляет товар с привязкой к категории, бренду, модели,
 * синхронизирует штрих-коды и атрибуты.
 * Идемпотентен: повторная обработка обновляет существующий товар.
 */
class HandleProductCreated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;

        if (! $uuid || ! $name) {
            Log::warning('product.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);

            return;
        }

        retry([100, 250], function () use ($payload): void {
            $this->processPayload($payload);
        }, function (int $attempt, Throwable $e) use ($uuid): int {
            Log::warning('product.created: deadlock, повторная попытка обработки', [
                'product_uuid' => $uuid,
                'attempt' => $attempt + 1,
                'error' => $e->getMessage(),
            ]);

            return $attempt === 1 ? 100 : 250;
        }, function (Throwable $e): bool {
            return $this->shouldRetryOnDeadlock($e);
        });
    }

    protected function runInTransaction(callable $callback): void
    {
        DB::transaction($callback);
    }

    protected function processPayload(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $code = $payload['code'] ?? null;
        $sku = $payload['sku'] ?? null;
        $categoryUuid = $payload['category_uuid'] ?? null;
        $brandData = $payload['brand'] ?? null;
        $description = $payload['description'] ?? null;
        $descriptionHtml = $payload['description_html'] ?? null;
        $barcodes = $payload['barcodes'] ?? [];
        $modelData = $payload['model'] ?? null;
        $attributes = $payload['attributes'] ?? [];
        $attributesInPayload = array_key_exists('attributes', $payload);
        $hidden = (bool) ($payload['hidden'] ?? false);
        $isMarked = (bool) ($payload['is_marked'] ?? false);
        $weightGross = $this->normalizeNumeric($payload['weight_gross'] ?? null);
        $weightNet = $this->normalizeNumeric($payload['weight_net'] ?? null);
        $width = $this->normalizeNumeric($payload['width'] ?? null);
        $height = $this->normalizeNumeric($payload['height'] ?? null);
        $depth = $this->normalizeNumeric($payload['depth'] ?? null);
        $hsCode = $this->normalizeString($payload['hs_code'] ?? null, 20);
        $abcXyz = $this->normalizeString($payload['abc_xyz'] ?? null, 5);
        $turnover = $this->normalizeNumeric($payload['turnover'] ?? null);

        $category = null;

        // retry() перезапускает всю транзакцию целиком, что сохраняет идемпотентность
        // и позволяет безопасно пережить кратковременный deadlock при массовой выгрузке.
        $this->runInTransaction(function () use (
            $uuid, $name, $code, $sku, $categoryUuid, $brandData,
            $description, $descriptionHtml, $barcodes, $modelData, $attributes, $attributesInPayload,
            $hidden, $isMarked, $weightGross, $weightNet, $width, $height, $depth,
            $hsCode, $abcXyz, $turnover, &$category
        ) {
            // --- Категория ---
            $categoryId = null;
            if ($categoryUuid) {
                $category = Category::where('uuid', $categoryUuid)->first();
                if ($category) {
                    $categoryId = $category->id;
                } else {
                    Log::warning('product.created: категория не найдена', [
                        'product_uuid' => $uuid,
                        'category_uuid' => $categoryUuid,
                    ]);
                }
            }

            // --- Бренд (v4: объект {uuid, name} | v3: строка | null) ---
            $brandId = null;
            if ($brandData) {
                $brandId = $this->resolveBrandId($brandData);
            }

            // --- Модель товара ---
            $modelId = null;
            if (! empty($modelData)) {
                $modelId = $this->resolveModelId($modelData);
            }

            // --- Upsert товара ---
            // withoutGlobalScopes: HiddenScope фильтрует hidden=true, без него мы не найдём
            // скрытый товар и создадим дубликат с тем же external_id → ошибка 1062.
            $existing = Product::withoutGlobalScopes()->where('external_id', $uuid)->first();
            $basePrice = $existing?->base_price ?? 0;

            $product = Product::withoutGlobalScopes()->updateOrCreate(
                ['external_id' => $uuid],
                [
                    'name' => $name,
                    'code' => $code,
                    'sku' => $sku,
                    'description' => $description,
                    'description_html' => $descriptionHtml,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'model_id' => $modelId,
                    'hidden' => $hidden,
                    'is_marked' => $isMarked,
                    'weight_gross' => $weightGross,
                    'weight_net' => $weightNet,
                    'width' => $width,
                    'height' => $height,
                    'depth' => $depth,
                    'hs_code' => $hsCode,
                    'abc_xyz' => $abcXyz,
                    'turnover' => $turnover,
                    // Цена не перезаписывается здесь — она управляется через price.updated (US-02)
                    'base_price' => $basePrice,
                ]
            );

            // --- Штрих-коды ---
            // Upsert: вставляем новые, удаляем отсутствующие — избегаем deadlock при параллельных воркерах
            if (! empty($barcodes)) {
                $barcodeValues = collect($barcodes)
                    ->map(fn ($v) => trim((string) $v))
                    ->filter(fn ($v) => $v !== '')
                    ->values();

                if ($barcodeValues->isNotEmpty()) {
                    // Upsert всех штрихкодов (insert or ignore)
                    $now = now();
                    $rows = $barcodeValues->map(fn ($b) => [
                        'product_id' => $product->id,
                        'barcode' => $b,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->toArray();
                    DB::table('product_barcodes')->upsert($rows, ['product_id', 'barcode'], ['updated_at']);

                    // Удаляем штрихкоды которых больше нет в списке
                    $product->barcodes()->whereNotIn('barcode', $barcodeValues)->delete();
                }
            }

            // --- Атрибуты (v13: full-replace) ---
            // Формат: [{ property_uuid, property_label, value_type, value_uuid, value_label }].
            // attributes в payload отсутствует → не трогаем (идемпотентность повторной обработки).
            // attributes передан (включая []) → синхронизируем состав: записи в
            // product_attribute_values, отсутствующие в массиве, удаляются.
            $processedAttributeIds = [];

            if ($attributesInPayload && is_array($attributes)) {

                foreach ($attributes as $attrData) {
                    if (! is_array($attrData)) {
                        continue;
                    }

                    $propertyUuid = $attrData['property_uuid'] ?? null;
                    $propertyLabel = $attrData['property_label'] ?? null;
                    $valueType = $attrData['value_type'] ?? 'string';
                    $valueUuid = $attrData['value_uuid'] ?? null;
                    $valueLabel = $attrData['value_label'] ?? null;

                    if (! $propertyUuid || ! $propertyLabel) {
                        continue;
                    }

                    // Пустой атрибут (нет ни value_uuid, ни value_label) — не пишем pivot.
                    // Атрибут НЕ добавляется в $processedAttributeIds: full-replace cleanup
                    // ниже удалит существующую запись для (product_id, attribute_id), если была.
                    if (! $valueUuid && ($valueLabel === null || $valueLabel === '')) {
                        continue;
                    }

                    // Маппинг value_type из 1С в тип атрибута на сайте
                    $siteType = match ($valueType) {
                        'number' => 'number',
                        'boolean' => 'boolean',
                        'reference' => 'select',
                        'date-time' => 'date-time',
                        default => 'string',
                    };

                    // Найти или создать атрибут по external_id (property_uuid)
                    // 1С — мастер-каталог. При конфликте slug с данными из sex-opt.ru
                    // перезаписываем external_id на значение из 1С.
                    $attribute = \App\Models\Attribute::where('external_id', $propertyUuid)->first();

                    if (! $attribute) {
                        $baseSlug = Str::slug($propertyLabel) ?: 'attr-'.Str::slug($propertyUuid);

                        // Ищем по slug — возможно сущность пришла из sex-opt.ru с другим external_id
                        $attribute = \App\Models\Attribute::where('slug', $baseSlug)->first();

                        if ($attribute) {
                            // Перезаписываем external_id на значение из 1С (1С — мастер)
                            $attribute->update([
                                'external_id' => $propertyUuid,
                                'name' => $propertyLabel,
                                'type' => $siteType,
                            ]);
                        } else {
                            $attribute = \App\Models\Attribute::create([
                                'external_id' => $propertyUuid,
                                'name' => $propertyLabel,
                                'slug' => $baseSlug,
                                'type' => $siteType,
                                'is_active' => \App\Models\Attribute::hasCyrillicName($propertyLabel),
                            ]);
                        }
                    } else {
                        $attribute->update([
                            'name' => $propertyLabel,
                            'type' => $siteType,
                        ]);
                    }

                    $processedAttributeIds[] = $attribute->id;

                    // Найти или создать значение атрибута (если есть value_uuid)
                    $attributeValueId = null;
                    if ($valueUuid) {
                        $valueStr = $valueLabel ?? $valueUuid;
                        $attrValue = \App\Models\AttributeValue::where('external_id', $valueUuid)->first();

                        if ($attrValue) {
                            $duplicate = \App\Models\AttributeValue::where('attribute_id', $attribute->id)
                                ->where('value', $valueStr)
                                ->where('id', '!=', $attrValue->id)
                                ->first();

                            if ($duplicate) {
                                // Избегаем Integrity constraint violation на (attribute_id, value)
                                // Передаем external_id дубликату
                                $attrValue->update(['external_id' => null]);
                                $duplicate->update(['external_id' => $valueUuid]);
                                $attrValue = $duplicate;
                            } else {
                                $attrValue->update([
                                    'attribute_id' => $attribute->id,
                                    'value' => $valueStr,
                                ]);
                            }
                        } else {
                            $attrValue = \App\Models\AttributeValue::where('attribute_id', $attribute->id)
                                ->where('value', $valueStr)
                                ->first();

                            if ($attrValue) {
                                $attrValue->update(['external_id' => $valueUuid]);
                            } else {
                                // Атомарный upsert — защита от гонки при параллельных воркерах
                                $attrValue = \App\Models\AttributeValue::updateOrCreate(
                                    ['attribute_id' => $attribute->id, 'value' => $valueStr],
                                    ['external_id' => $valueUuid]
                                );
                            }
                        }
                        $attributeValueId = $attrValue->id;
                    }

                    // Записываем значение атрибута для товара.
                    // text_value пишем только для строковых и select-атрибутов — иначе boolean даёт "1".
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
                            $pivotData['datetime_value'] = \Carbon\Carbon::parse($valueLabel)->toDateTimeString();
                        } catch (\Exception $e) {
                            Log::warning('Неверный формат даты атрибута', ['value' => $valueLabel]);
                        }
                    } else {
                        $pivotData['text_value'] = (string) ($valueLabel ?? '');
                    }

                    \App\Models\ProductAttributeValue::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'attribute_id' => $attribute->id,
                        ],
                        $pivotData
                    );
                }
            }

            // Full-replace: если поле attributes передано, чистим связи, которых больше нет.
            // Пустой массив → удаляются все атрибуты товара.
            if ($attributesInPayload) {
                $stale = \App\Models\ProductAttributeValue::where('product_id', $product->id);
                if (! empty($processedAttributeIds)) {
                    $stale->whereNotIn('attribute_id', $processedAttributeIds);
                }
                $stale->delete();
            }

            // --- Привязка атрибутов к категории товара ---
            // Атомарный insertOrIgnore вместо syncWithoutDetaching: при 6 воркерах
            // non-atomic SELECT+INSERT ловит Duplicate entry на уникальном индексе.
            if ($categoryId && ! empty($processedAttributeIds)) {
                $now = now();
                $rows = array_map(fn ($aid) => [
                    'attribute_id' => $aid,
                    'category_id' => $categoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $processedAttributeIds);
                DB::table('attribute_category')->insertOrIgnore($rows);
            }

            Log::info('product.created: товар создан/обновлён', [
                'uuid' => $uuid,
                'name' => $name,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'model_id' => $modelId,
                'barcodes' => count($barcodes),
                'attributes' => count($attributes),
            ]);
        });
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

    protected function shouldRetryOnDeadlock(Throwable $e): bool
    {
        /** @var ConcurrencyErrorDetector $detector */
        $detector = app(ConcurrencyErrorDetector::class);

        if ($detector->causedByConcurrencyError($e)) {
            return true;
        }

        // MySQL error 1062 (Duplicate entry) — гонка между параллельными воркерами
        // на уникальных индексах (brands.slug, product_models.external_id,
        // attribute_values.(attribute_id,value), attributes.slug и т.п.).
        // При повторе транзакции SELECT найдёт строку, вставленную выигравшим воркером.
        if ($e instanceof \Illuminate\Database\QueryException
            && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return true;
        }

        return false;
    }

    /**
     * Поиск/создание бренда.
     * v4: объект {uuid, name} → updateOrCreate по external_id
     * v3 (обратная совместимость): строка → поиск по name
     */
    private function resolveBrandId(mixed $brandData): ?int
    {
        // v4: объект {uuid, name}
        if (is_array($brandData)) {
            $uuid = $brandData['uuid'] ?? null;
            $name = $brandData['name'] ?? null;

            if (! $uuid || ! $name) {
                return null;
            }

            $slug = Str::slug($name) ?: 'brand-'.Str::slug($uuid);

            // 1С — мастер. При конфликте slug перезаписываем external_id.
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

        // v3: строка (обратная совместимость)
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

        // 1. Ищем по external_id (uuid)
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

        // 2. Fallback: ищем по name — возможно модель уже создана с другим external_id
        if ($name) {
            $productModel = ProductModel::where('name', $name)->first();

            if ($productModel) {
                $productModel->update(array_filter([
                    'external_id' => $uuid,
                    'code' => $code,
                ]));

                return $productModel->id;
            }
        }

        // 3. Атомарный upsert — защита от гонки при параллельных воркерах
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
