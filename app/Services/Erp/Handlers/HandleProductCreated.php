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
        $brandName    = $payload['brand']         ?? null;
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
            $uuid, $name, $code, $sku, $categoryUuid, $brandName,
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

            // --- Бренд (найти по имени или создать с авто-slug) ---
            $brandId = null;
            if ($brandName) {
                $brand = Brand::where('name', $brandName)->first();
                if (!$brand) {
                    // Генерируем уникальный slug для бренда
                    $baseSlug = Str::slug($brandName) ?: 'brand-' . Str::uuid();
                    $slug = $baseSlug;
                    $counter = 1;
                    while (Brand::where('slug', $slug)->exists()) {
                        $slug = $baseSlug . '-' . $counter++;
                    }
                    $brand = Brand::create(['name' => $brandName, 'slug' => $slug]);
                }
                $brandId = $brand->id;
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

            // --- Атрибуты (текстовые значения из 1С) ---
            // Атрибуты хранятся как text_value в product_attribute_values.
            // Ищем или создаём атрибут по slug (имя атрибута), записываем text_value.
            if (!empty($attributes) && is_array($attributes)) {
                // Удаляем все старые значения атрибутов товара
                $product->attributeValues()->delete();

                foreach ($attributes as $attrName => $attrValue) {
                    if ($attrValue === null || $attrValue === '') {
                        continue;
                    }

                    $slug = Str::slug($attrName);
                    if (!$slug) {
                        continue;
                    }

                    $attribute = \App\Models\Attribute::firstOrCreate(
                        ['slug' => $slug],
                        ['name' => $attrName, 'type' => 'string']
                    );

                    \App\Models\ProductAttributeValue::updateOrCreate(
                        [
                            'product_id'   => $product->id,
                            'attribute_id' => $attribute->id,
                        ],
                        ['text_value' => (string) $attrValue]
                    );
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
}
