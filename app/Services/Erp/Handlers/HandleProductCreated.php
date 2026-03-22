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
            if (!empty($modelData['uuid'])) {
                $productModel = ProductModel::updateOrCreate(
                    ['external_id' => $modelData['uuid']],
                    ['name' => $modelData['name'] ?? $modelData['uuid']]
                );
                $modelId = $productModel->id;
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
            if (!empty($barcodes)) {
                // Удаляем устаревшие штрих-коды, добавляем новые
                $product->barcodes()->delete();
                foreach ($barcodes as $barcodeValue) {
                    $barcodeValue = trim((string) $barcodeValue);
                    if ($barcodeValue !== '') {
                        ProductBarcode::create([
                            'product_id' => $product->id,
                            'barcode'    => $barcodeValue,
                        ]);
                    }
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

                    $slug = Str::slug($propertyLabel) ?: 'attr-' . Str::slug($propertyUuid);

                    // Найти или создать атрибут по external_id (property_uuid)
                    $attribute = \App\Models\Attribute::updateOrCreate(
                        ['external_id' => $propertyUuid],
                        [
                            'name' => $propertyLabel,
                            'slug' => $slug,
                            'type' => $siteType,
                        ]
                    );

                    $processedAttributeIds[] = $attribute->id;

                    // Найти или создать значение атрибута (если есть value_uuid)
                    $attributeValueId = null;
                    if ($valueUuid) {
                        $attrValue = \App\Models\AttributeValue::updateOrCreate(
                            ['external_id' => $valueUuid],
                            [
                                'attribute_id' => $attribute->id,
                                'value'        => $valueLabel ?? $valueUuid,
                            ]
                        );
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
            $brand = Brand::updateOrCreate(
                ['external_id' => $uuid],
                ['name' => $name, 'slug' => $slug]
            );

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
}
