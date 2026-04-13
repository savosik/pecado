<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * WooCommerce CSV — формат встроенного импортера WooCommerce.
 *
 * Колонки: SKU, Name, Published, Short description, Description,
 * Regular price, Sale price, Stock, Categories, Images, Attribute 1 name, etc.
 */
class WooCommerceCsvPreset extends AbstractPreset
{
    public function key(): string { return 'woocommerce'; }
    public function name(): string { return 'WooCommerce / WordPress (CSV)'; }
    public function description(): string { return 'CSV-файл для встроенного импортера WooCommerce. Товары, цены, остатки, картинки и атрибуты.'; }
    public function fileExtension(): string { return 'csv'; }
    public function mimeType(): string { return 'text/csv; charset=utf-8'; }
    public function color(): string { return 'purple'; }
    public function icon(): string { return 'LuGlobe'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        $data = $this->fetchRichData($export);

        // Определяем максимальное количество атрибутов
        $maxAttrs = $data->max(fn ($item) => count($item['attributes']));
        $maxImages = $data->max(fn ($item) => count($item['additional_images']));

        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        // Заголовки
        $headers = [
            'ID', 'Type', 'SKU', 'Name', 'Published', 'Featured',
            'Short description', 'Description',
            'Sale price', 'Regular price',
            'Stock', 'In stock?',
            'Categories', 'Tags', 'Images',
            'Meta: _seo_title', 'Meta: _seo_description',
            'External ID', 'Brands',
        ];

        // Атрибутные колонки
        for ($i = 1; $i <= $maxAttrs; $i++) {
            $headers[] = "Attribute {$i} name";
            $headers[] = "Attribute {$i} value(s)";
        }

        fputcsv($stream, $headers);

        // Данные
        foreach ($data as $item) {
            // Все картинки через запятую (WooCommerce формат)
            $allImages = collect([$item['main_image']])
                ->merge($item['additional_images'])
                ->filter()
                ->implode(', ');

            $row = [
                $item['id'],                                      // ID
                'simple',                                         // Type
                $item['sku'] ?? '',                                // SKU
                $item['name'],                                    // Name
                '1',                                              // Published
                $item['is_bestseller'] ? '1' : '0',               // Featured
                $item['short_description'] ?? '',                  // Short description
                $item['description'] ?? '',                        // Description
                (string) $item['price'],                          // Sale price
                (string) $item['base_price'],                     // Regular price
                (string) $item['stock'],                          // Stock
                $item['stock'] > 0 ? '1' : '0',                  // In stock?
                $item['category_path'] ?? '',                     // Categories
                '',                                               // Tags
                $allImages,                                       // Images
                $item['meta_title'] ?? '',                        // Meta: _seo_title
                $item['meta_description'] ?? '',                  // Meta: _seo_description
                $item['external_id'] ?? '',                       // External ID
                $item['brand_name'] ?? '',                        // Brands
            ];

            // Атрибуты
            foreach ($item['attributes'] as $attr) {
                $row[] = $attr['name'];
                $row[] = $attr['value'] . ($attr['unit'] ? " {$attr['unit']}" : '');
            }
            // Заполнить оставшиеся слоты пустыми
            $remaining = ($maxAttrs - count($item['attributes'])) * 2;
            for ($i = 0; $i < $remaining; $i++) {
                $row[] = '';
            }

            fputcsv($stream, $row);
        }
    }
}
