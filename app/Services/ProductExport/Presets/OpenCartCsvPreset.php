<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * OpenCart CSV — формат для модуля Export/Import Tool.
 *
 * Использует chunk-подход для обработки больших каталогов.
 */
class OpenCartCsvPreset extends AbstractPreset
{
    public function key(): string { return 'opencart'; }
    public function name(): string { return 'OpenCart (CSV)'; }
    public function description(): string { return 'CSV-файл для импорта товаров в OpenCart через модуль Export/Import Tool.'; }
    public function fileExtension(): string { return 'csv'; }
    public function mimeType(): string { return 'text/csv; charset=utf-8'; }
    public function color(): string { return 'cyan'; }
    public function icon(): string { return 'LuShoppingCart'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        $headersWritten = false;

        $this->eachChunk($export, function ($items) use ($stream, &$headersWritten) {
            // Определяем максимальное количество атрибутов в чанке
            $maxAttrs = $items->max(fn ($item) => count($item['attributes']));

            if (!$headersWritten) {
                $headers = [
                    'product_id', 'name(ru)', 'categories', 'sku', 'model',
                    'price', 'quantity', 'status', 'image', 'additional_images',
                    'description(ru)', 'meta_title(ru)', 'meta_description(ru)',
                    'manufacturer', 'upc', 'weight',
                ];
                for ($i = 1; $i <= $maxAttrs; $i++) {
                    $headers[] = "attribute_name_{$i}";
                    $headers[] = "attribute_value_{$i}";
                }
                fputcsv($stream, $headers);
                $headersWritten = true;
            }

            foreach ($items as $item) {
                $additionalImages = implode(',', $item['additional_images']);

                $row = [
                    $item['id'],
                    $item['name'],
                    $item['category_path'] ?? '',
                    $item['sku'] ?? '',
                    $item['model_code'] ?? $item['sku'] ?? '',
                    (string) $item['price'],
                    (string) $item['stock'],
                    $item['stock'] > 0 ? '1' : '0',
                    $item['main_image'] ?? '',
                    $additionalImages,
                    $item['description'] ?? '',
                    $item['meta_title'] ?? '',
                    $item['meta_description'] ?? '',
                    $item['brand_name'] ?? '',
                    $item['barcode'] ?? '',
                    '',
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
