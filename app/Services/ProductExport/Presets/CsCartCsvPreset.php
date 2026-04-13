<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * CS-Cart CSV — штатный формат массового импорта CS-Cart.
 *
 * Колонки: Product code, Language, Product name, Category, Price, List price,
 * Quantity, Images, Detailed description, SEO name, Features.
 */
class CsCartCsvPreset extends AbstractPreset
{
    public function key(): string { return 'cscart'; }
    public function name(): string { return 'CS-Cart (CSV)'; }
    public function description(): string { return 'CSV-файл для штатного импорта товаров в CS-Cart 4. Товары, цены, остатки, картинки и характеристики.'; }
    public function fileExtension(): string { return 'csv'; }
    public function mimeType(): string { return 'text/csv; charset=utf-8'; }
    public function color(): string { return 'teal'; }
    public function icon(): string { return 'LuStore'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        $data = $this->fetchRichData($export);

        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        $headers = [
            'Product code',
            'Language',
            'Product name',
            'Category',
            'Price',
            'List price',
            'Quantity',
            'Status',
            'Store',
            'Images',
            'Detailed description',
            'Short description',
            'Meta keywords',
            'Meta description',
            'Search words',
            'Page title',
            'Brand/Manufacturer',
        ];

        // Характеристики (Features) CS-Cart — каждая в отдельной колонке
        $maxAttrs = $data->max(fn ($item) => count($item['attributes']));
        for ($i = 1; $i <= $maxAttrs; $i++) {
            $headers[] = "Feature {$i} name";
            $headers[] = "Feature {$i} value";
        }

        fputcsv($stream, $headers, ';');

        foreach ($data as $item) {
            // CS-Cart: основное и доп. изображения разделены через ///
            $allImages = collect([$item['main_image']])
                ->merge($item['additional_images'])
                ->filter()
                ->implode('///');

            $row = [
                $item['sku'] ?? (string) $item['id'],                  // Product code
                'ru',                                                  // Language
                $item['name'],                                         // Product name
                str_replace(' > ', '///', $item['category_path'] ?? ''), // Category (CS-Cart uses ///)
                (string) $item['price'],                               // Price
                $item['base_price'] > $item['price']
                    ? (string) $item['base_price'] : '',               // List price
                (string) $item['stock'],                               // Quantity
                $item['stock'] > 0 ? 'A' : 'D',                       // Status (A=active, D=disabled)
                config('app.name', 'Pecado'),                          // Store
                $allImages,                                            // Images
                $item['description'] ?? '',                            // Detailed description
                $item['short_description'] ?? '',                      // Short description
                '',                                                    // Meta keywords
                $item['meta_description'] ?? '',                       // Meta description
                $item['name'],                                         // Search words
                $item['meta_title'] ?? '',                             // Page title
                $item['brand_name'] ?? '',                             // Brand/Manufacturer
            ];

            foreach ($item['attributes'] as $attr) {
                $row[] = $attr['name'];
                $row[] = $attr['value'] . ($attr['unit'] ? " {$attr['unit']}" : '');
            }
            $remaining = ($maxAttrs - count($item['attributes'])) * 2;
            for ($i = 0; $i < $remaining; $i++) {
                $row[] = '';
            }

            fputcsv($stream, $row, ';');
        }
    }
}
