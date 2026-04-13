<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * WooCommerce CSV — формат встроенного импортера WooCommerce.
 * Использует chunk-подход для больших каталогов.
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
        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        $headersWritten = false;

        $this->eachChunk($export, function ($items) use ($stream, &$headersWritten) {
            $maxAttrs = $items->max(fn ($item) => count($item['attributes']));

            if (!$headersWritten) {
                $headers = [
                    'ID', 'Type', 'SKU', 'Name', 'Published', 'Featured',
                    'Short description', 'Description', 'Sale price', 'Regular price',
                    'Stock', 'In stock?', 'Categories', 'Tags', 'Images',
                    'Meta: _seo_title', 'Meta: _seo_description', 'External ID', 'Brands',
                ];
                for ($i = 1; $i <= $maxAttrs; $i++) {
                    $headers[] = "Attribute {$i} name";
                    $headers[] = "Attribute {$i} value(s)";
                }
                fputcsv($stream, $headers);
                $headersWritten = true;
            }

            foreach ($items as $item) {
                $allImages = collect([$item['main_image']])
                    ->merge($item['additional_images'])
                    ->filter()
                    ->implode(', ');

                $row = [
                    $item['id'], 'simple', $item['sku'] ?? '', $item['name'],
                    '1', $item['is_bestseller'] ? '1' : '0',
                    $item['short_description'] ?? '', $item['description'] ?? '',
                    (string) $item['price'], (string) $item['base_price'],
                    (string) $item['stock'], $item['stock'] > 0 ? '1' : '0',
                    $item['category_path'] ?? '', '', $allImages,
                    $item['meta_title'] ?? '', $item['meta_description'] ?? '',
                    $item['external_id'] ?? '', $item['brand_name'] ?? '',
                ];

                foreach ($item['attributes'] as $attr) {
                    $row[] = $attr['name'];
                    $row[] = $attr['value'] . ($attr['unit'] ? " {$attr['unit']}" : '');
                }
                $remaining = ($maxAttrs - count($item['attributes'])) * 2;
                for ($i = 0; $i < $remaining; $i++) {
                    $row[] = '';
                }

                fputcsv($stream, $row);
            }
        });
    }
}
