<?php

namespace App\Services\Erp\Handlers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $uuid         = $payload['uuid']          ?? null;
        $name         = $payload['name']          ?? null;
        $code         = $payload['code']          ?? null;
        $sku          = $payload['sku']           ?? null;
        $categoryUuid = $payload['category_uuid'] ?? null;
        $brandData    = $payload['brand']         ?? null;
        $description  = $payload['description']   ?? null;
        $barcodes     = $payload['barcodes']      ?? [];
        $modelData    = $payload['model']         ?? null;
        $attributes   = $payload['attributes']    ?? [];

        if (!$uuid || !$name) {
            Log::warning('product.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);
            return;
        }

        DB::transaction(function () use (
            $uuid, $name, $code, $sku, $categoryUuid, $brandData,
            $description, $barcodes, $modelData, $attributes
        ) {
            // --- Категория ---
            $categoryId = null;
            if ($categoryUuid) {
                $category = Category::where('uuid', $categoryUuid)->first();
                if ($category) {
                    $categoryId = $category->id;
                } else {
                    Log::warning('product.created: категория не найдена', [
                        'product_uuid'  => $uuid,
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
            if (!empty($modelData)) {
                $modelId = $this->resolveModelId($modelData);
            }

            // --- Upsert товара ---
            // Сохраняем существующую base_price — она управляется через price.updated (US-02)
            $existing   = Product::where('external_id', $uuid)->first();
            $basePrice  = $existing?->base_price ?? 0;

            $product = Product::updateOrCreate(
                ['external_id' => $uuid],
                [
                    'name'        => $name,
                    'code'        => $code,
                    'sku'         => $sku,
                    'description' => $description,
                    'category_id' => $categoryId,
                    'brand_id'    => $brandId,
                    'model_id'    => $modelId,
                    // Цена не перезаписывается здесь — она управляется через price.updated (US-02)
                    'base_price'  => $basePrice,
                ]
            );

            // --- Штрих-коды ---
            // Upsert: вставляем новые, удаляем отсутствующие — избегаем deadlock при параллельных воркерах
            if (!empty($barcodes)) {
                $barcodeValues = collect($barcodes)
                    ->map(fn($v) => trim((string) $v))
                    ->filter(fn($v) => $v !== '')
                    ->values();

                if ($barcodeValues->isNotEmpty()) {
                    // Upsert всех штрихкодов (insert or ignore)
                    $now = now();
                    $rows = $barcodeValues->map(fn($b) => [
                        'product_id' => $product->id,
                        'barcode'    => $b,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->toArray();
                    DB::table('product_barcodes')->upsert($rows, ['product_id', 'barcode'], ['updated_at']);

                    // Удаляем штрихкоды которых больше нет в списке
                    $product->barcodes()->whereNotIn('barcode', $barcodeValues)->delete();
                }
            }


            // --- Атрибуты (v4: мерж, не полная замена) ---
            // Формат: [{ property_uuid, property_label, value_type, value_uuid, value_label }]
            $processedAttributeIds = [];

            if (!empty($attributes) && is_array($attributes)) {

                foreach ($attributes as $attrData) {
                    if (!is_array($attrData)) {
                        continue;
                    }

                    $propertyUuid  = $attrData['property_uuid']  ?? null;
                    $propertyLabel = $attrData['property_label'] ?? null;
                    $valueType     = $attrData['value_type']     ?? 'string';
                    $valueUuid     = $attrData['value_uuid']     ?? null;
                    $valueLabel    = $attrData['value_label']    ?? null;

                    if (!$propertyUuid || !$propertyLabel) {
                        continue;
                    }

                    // Маппинг value_type из 1С в тип атрибута на сайте
                    $siteType = match ($valueType) {
                        'number'    => 'number',
                        'boolean'   => 'boolean',
                        'reference' => 'select',
                        default     => 'string',
                    };

                    // Найти или создать атрибут по external_id (property_uuid)
                    // 1С — мастер-каталог. При конфликте slug с данными из sex-opt.ru
                    // перезаписываем external_id на значение из 1С.
                    $attribute = \App\Models\Attribute::where('external_id', $propertyUuid)->first();

                    if (!$attribute) {
                        $baseSlug = Str::slug($propertyLabel) ?: 'attr-' . Str::slug($propertyUuid);

                        // Ищем по slug — возможно сущность пришла из sex-opt.ru с другим external_id
                        $attribute = \App\Models\Attribute::where('slug', $baseSlug)->first();

                        if ($attribute) {
                            // Перезаписываем external_id на значение из 1С (1С — мастер)
                            $attribute->update([
                                'external_id' => $propertyUuid,
                                'name'        => $propertyLabel,
                                'type'        => $siteType,
                            ]);
                        } else {
                            $attribute = \App\Models\Attribute::create([
                                'external_id' => $propertyUuid,
                                'name'        => $propertyLabel,
                                'slug'        => $baseSlug,
                                'type'        => $siteType,
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
                                    'value'        => $valueStr,
                                ]);
                            }
                        } else {
                            $attrValue = \App\Models\AttributeValue::where('attribute_id', $attribute->id)
                                ->where('value', $valueStr)
                                ->first();

                            if ($attrValue) {
                                $attrValue->update(['external_id' => $valueUuid]);
                            } else {
                                $attrValue = \App\Models\AttributeValue::create([
                                    'external_id'  => $valueUuid,
                                    'attribute_id' => $attribute->id,
                                    'value'        => $valueStr,
                                ]);
                            }
                        }
                        $attributeValueId = $attrValue->id;
                    }

                    // Записываем значение атрибута для товара
                    $pivotData = [
                        'attribute_value_id' => $attributeValueId,
                        'text_value'         => (string) ($valueLabel ?? ''),
                    ];

                    // Для числовых и булевых типов заполняем соответствующие поля
                    if ($siteType === 'number' && is_numeric($valueLabel)) {
                        $pivotData['number_value'] = (float) $valueLabel;
                    } elseif ($siteType === 'boolean') {
                        $pivotData['boolean_value'] = filter_var($valueLabel, FILTER_VALIDATE_BOOLEAN);
                    }

                    \App\Models\ProductAttributeValue::updateOrCreate(
                        [
                            'product_id'   => $product->id,
                            'attribute_id' => $attribute->id,
                        ],
                        $pivotData
                    );
                }
            }

            // --- Привязка атрибутов к категории товара ---
            if ($categoryId && !empty($processedAttributeIds)) {
                $category = $category ?? Category::find($categoryId);
                if ($category) {
                    $category->attributes()->syncWithoutDetaching($processedAttributeIds);
                }
            }

            Log::info('product.created: товар создан/обновлён', [
                'uuid'        => $uuid,
                'name'        => $name,
                'category_id' => $categoryId,
                'brand_id'    => $brandId,
                'model_id'    => $modelId,
                'barcodes'    => count($barcodes),
                'attributes'  => count($attributes),
            ]);
        });
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

            if (!$uuid || !$name) {
                return null;
            }

            $slug = Str::slug($name) ?: 'brand-' . Str::slug($uuid);

            // 1С — мастер. При конфликте slug перезаписываем external_id.
            $brand = Brand::where('external_id', $uuid)->first();

            if (!$brand) {
                // Ищем по slug — возможно бренд пришёл из sex-opt.ru с другим external_id
                $brand = Brand::where('slug', $slug)->first();

                if ($brand) {
                    $brand->update([
                        'external_id' => $uuid,
                        'name'        => $name,
                    ]);
                } else {
                    $brand = Brand::create([
                        'external_id' => $uuid,
                        'name'        => $name,
                        'slug'        => $slug,
                    ]);
                }
            } else {
                $brand->update(['name' => $name]);
            }

            return $brand->id;
        }

        // v3: строка (обратная совместимость)
        if (is_string($brandData) && $brandData !== '') {
            $brand = Brand::where('name', $brandData)->first();
            if (!$brand) {
                $baseSlug = Str::slug($brandData) ?: 'brand-' . Str::uuid();
                $slug = $baseSlug;
                $counter = 1;
                while (Brand::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
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

        if (!$uuid && !$name) {
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
                    'code'        => $code,
                ]));

                return $productModel->id;
            }
        }

        // 3. Создаём новую модель
        $productModel = ProductModel::create(array_filter([
            'external_id' => $uuid,
            'name'        => $name ?? $uuid,
            'code'        => $code,
        ]));

        return $productModel->id;
    }
}
