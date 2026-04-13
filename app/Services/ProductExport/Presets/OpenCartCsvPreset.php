<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * OpenCart CSV — формат для модуля Export/Import Tool.
 *
 * Колонки: product_id, name(ru), categories, sku, model, price, quantity,
 * image, additional_images, description(ru), meta_title(ru), manufacturer, status.
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
        $data = $this->fetchRichData($export);

        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        $headers = [
            'product_id',
            'name(ru)',
            'categories',
            'sku',
            'model',
            'price',
            'quantity',
            'status',
            'image',
            'additional_images',
            'description(ru)',
            'meta_title(ru)',
            'meta_description(ru)',
            'manufacturer',
            'upc',
            'weight',
        ];

        // Атрибуты
        $maxAttrs = $data->max(fn ($item) => count($item['attributes']));
        for ($i = 1; $i <= $maxAttrs; $i++) {
            $headers[] = "attribute_name_{$i}";
            $headers[] = "attribute_value_{$i}";
        }

        fputcsv($stream, $headers);

        foreach ($data as $item) {
            $additionalImages = implode(',', $item['additional_images']);

            $row = [
                $item['id'],                                           // product_id
                $item['name'],                                         // name(ru)
                $item['category_path'] ?? '',                          // categories
                $item['sku'] ?? '',                                    // sku
                $item['model_code'] ?? $item['sku'] ?? '',             // model
                (string) $item['price'],                               // price
                (string) $item['stock'],                               // quantity
                $item['stock'] > 0 ? '1' : '0',                       // status
                $item['main_image'] ?? '',                             // image
                $additionalImages,                                     // additional_images
                $item['description'] ?? '',                            // description(ru)
                $item['meta_title'] ?? '',                             // meta_title(ru)
                $item['meta_description'] ?? '',                       // meta_description(ru)
                $item['brand_name'] ?? '',                             // manufacturer
                $item['barcode'] ?? '',                                // upc
                '',                                                    // weight
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
    }
}
