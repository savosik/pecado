<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;
use Illuminate\Support\Str;

/**
 * Shopify CSV — строгий формат для импорта товаров в Shopify.
 *
 * Обязательные колонки: Handle, Title, Body (HTML), Vendor, Type,
 * Variant SKU, Variant Price, Variant Inventory Qty, Image Src, и т.д.
 */
class ShopifyCsvPreset extends AbstractPreset
{
    public function key(): string { return 'shopify'; }
    public function name(): string { return 'Shopify (CSV)'; }
    public function description(): string { return 'CSV-файл для импорта каталога в Shopify. Включает Handle, описание, бренд, цены, остатки, картинки.'; }
    public function fileExtension(): string { return 'csv'; }
    public function mimeType(): string { return 'text/csv; charset=utf-8'; }
    public function color(): string { return 'green'; }
    public function icon(): string { return 'LuShoppingBag'; }

    protected function getHeaders(): array
    {
        return [
            'Handle',
            'Title',
            'Body (HTML)',
            'Vendor',
            'Product Category',
            'Type',
            'Tags',
            'Published',
            'Variant SKU',
            'Variant Grams',
            'Variant Inventory Qty',
            'Variant Price',
            'Variant Compare At Price',
            'Variant Barcode',
            'Image Src',
            'Image Position',
            'Image Alt Text',
            'SEO Title',
            'SEO Description',
            'Status',
        ];
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        $data = $this->fetchRichData($export);

        // BOM for UTF-8
        fwrite($stream, "\xEF\xBB\xBF");

        // Header row
        fputcsv($stream, $this->getHeaders());

        foreach ($data as $item) {
            $handle = Str::slug($item['name'] . '-' . ($item['sku'] ?: $item['id']));

            // Теги из атрибутов
            $tags = collect($item['attributes'])
                ->map(fn ($a) => "{$a['name']}:{$a['value']}")
                ->implode(', ');

            // Основная строка товара
            $row = [
                $handle,                                          // Handle
                $item['name'],                                    // Title
                $item['description'] ?? '',                       // Body (HTML)
                $item['brand_name'] ?? '',                        // Vendor
                $item['category_path'] ?? '',                     // Product Category
                $item['category_name'] ?? '',                     // Type
                $tags,                                            // Tags
                'TRUE',                                           // Published
                $item['sku'] ?? '',                                // Variant SKU
                '',                                               // Variant Grams
                (string) $item['stock'],                          // Variant Inventory Qty
                (string) $item['price'],                          // Variant Price
                $item['base_price'] > $item['price']
                    ? (string) $item['base_price'] : '',          // Variant Compare At Price
                $item['barcode'] ?? '',                           // Variant Barcode
                $item['main_image'] ?? '',                        // Image Src
                $item['main_image'] ? '1' : '',                   // Image Position
                $item['name'],                                    // Image Alt Text
                $item['meta_title'] ?? '',                        // SEO Title
                $item['meta_description'] ?? '',                  // SEO Description
                $item['stock'] > 0 ? 'active' : 'draft',         // Status
            ];

            fputcsv($stream, $row);

            // Дополнительные картинки — каждая в отдельной строке
            foreach ($item['additional_images'] as $i => $imgUrl) {
                $imgRow = array_fill(0, count($this->getHeaders()), '');
                $imgRow[0] = $handle;                              // Handle
                $imgRow[array_search('Image Src', $this->getHeaders())] = $imgUrl;
                $imgRow[array_search('Image Position', $this->getHeaders())] = (string) ($i + 2);
                $imgRow[array_search('Image Alt Text', $this->getHeaders())] = $item['name'] . ' — фото ' . ($i + 2);
                fputcsv($stream, $imgRow);
            }
        }
    }
}
