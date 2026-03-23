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
 * US-13 v3: Обработка события product.updated из 1С.
 * Частичное обновление — обновляет только поля, переданные в payload.
 * Не затрагивает поля, отсутствующие в сообщении.
 * base_price не обновляется (используйте price.updated).
 */
class HandleProductUpdated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('product.updated: отсутствует обязательное поле uuid', [
                'payload' => $payload,
            ]);
            return;
        }

        $product = Product::where('external_id', $uuid)->first();

        if (!$product) {
            Log::warning('product.updated: товар не найден', ['uuid' => $uuid]);
            return;
        }

        DB::transaction(function () use ($product, $payload, $uuid) {
            $updateData = [];

            // --- Название ---
            if (array_key_exists('name', $payload)) {
                $updateData['name'] = $payload['name'];
            }

            // --- Код ---
            if (array_key_exists('code', $payload)) {
                $updateData['code'] = $payload['code'];
            }

            // --- Артикул ---
            if (array_key_exists('sku', $payload)) {
                $updateData['sku'] = $payload['sku'];
            }

            // --- Описание ---
            if (array_key_exists('description', $payload)) {
                $updateData['description'] = $payload['description'];
            }

            // --- Категория ---
            if (array_key_exists('category_uuid', $payload)) {
                $categoryUuid = $payload['category_uuid'];
                if ($categoryUuid) {
                    $category = Category::where('uuid', $categoryUuid)->first();
                    $updateData['category_id'] = $category?->id;
                } else {
                    $updateData['category_id'] = null;
                }
            }

            // --- Бренд (v4: объект {uuid, name} | v3: строка | null) ---
            if (array_key_exists('brand', $payload)) {
                $brandData = $payload['brand'];
                if ($brandData) {
                    $updateData['brand_id'] = $this->resolveBrandId($brandData);
                } else {
                    $updateData['brand_id'] = null;
                }
            }

            // --- Модель товара ---
            if (array_key_exists('model', $payload)) {
                $modelData = $payload['model'];
                if (!empty($modelData['uuid'])) {
                    $productModel = ProductModel::updateOrCreate(
                        ['external_id' => $modelData['uuid']],
                        ['name' => $modelData['name'] ?? $modelData['uuid']]
                    );
                    $updateData['model_id'] = $productModel->id;
                } else {
                    $updateData['model_id'] = null;
                }
            }

            // Применяем обновление полей (без base_price!)
            if (!empty($updateData)) {
                $product->update($updateData);
            }

            // --- Штрих-коды (полная замена, только если поле передано) ---
            if (array_key_exists('barcodes', $payload)) {
                $barcodes = $payload['barcodes'] ?? [];
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
            $processedAttributeIds = [];

            if (array_key_exists('attributes', $payload)) {
                $attributes = $payload['attributes'] ?? [];

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

                    $pivotData = [
                        'attribute_value_id' => $attributeValueId,
                        'text_value'         => (string) ($valueLabel ?? ''),
                    ];

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
            if (!empty($processedAttributeIds) && $product->category_id) {
                $category = Category::find($product->category_id);
                if ($category) {
                    $category->attributes()->syncWithoutDetaching($processedAttributeIds);
                }
            }

            Log::info('product.updated: товар обновлён (частичное)', [
                'uuid'           => $uuid,
                'updated_fields' => array_keys($updateData),
                'has_barcodes'   => array_key_exists('barcodes', $payload),
                'has_attributes' => array_key_exists('attributes', $payload),
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
}
