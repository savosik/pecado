<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * Tilda Publishing CSV — формат для импорта товаров в Tilda-магазин.
 *
 * Колонки: Brand, SKU, Category, Title, Description, Text,
 * Photo, Price, Price Old, Editions (JSON), Quantity, External ID.
 */
class TildaCsvPreset extends AbstractPreset
{
    public function key(): string { return 'tilda'; }
    public function name(): string { return 'Tilda Publishing (CSV)'; }
    public function description(): string { return 'CSV-файл для импорта товаров в интернет-магазин на платформе Tilda.'; }
    public function fileExtension(): string { return 'csv'; }
    public function mimeType(): string { return 'text/csv; charset=utf-8'; }
    public function color(): string { return 'yellow'; }
    public function icon(): string { return 'LuPenTool'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        $data = $this->fetchRichData($export);

        // BOM
        fwrite($stream, "\xEF\xBB\xBF");

        $headers = [
            'Brand',
            'SKU',
            'Category',
            'Title',
            'Description',
            'Text',
            'Photo',
            'Price',
            'Price Old',
            'Quantity',
            'External ID',
            'Parent UID',
            'Mark',
        ];

        // Характеристики как дополнительные колонки
        $maxAttrs = $data->max(fn ($item) => count($item['attributes']));
        for ($i = 1; $i <= $maxAttrs; $i++) {
            $headers[] = "Characteristic {$i} Title";
            $headers[] = "Characteristic {$i} Value";
        }

        fputcsv($stream, $headers, ';');

        foreach ($data as $item) {
            $allImages = collect([$item['main_image']])
                ->merge($item['additional_images'])
                ->filter()
                ->implode(';');

            $marks = [];
            if ($item['is_new']) $marks[] = 'new';
            if ($item['is_bestseller']) $marks[] = 'bestseller';

            $row = [
                $item['brand_name'] ?? '',                             // Brand
                $item['sku'] ?? '',                                    // SKU
                $item['category_path'] ?? '',                          // Category
                $item['name'],                                         // Title
                $item['short_description'] ?? '',                      // Description
                $item['description'] ?? '',                            // Text
                $allImages,                                            // Photo
                (string) $item['price'],                               // Price
                $item['base_price'] > $item['price']
                    ? (string) $item['base_price'] : '',               // Price Old
                (string) $item['stock'],                               // Quantity
                $item['external_id'] ?? (string) $item['id'],         // External ID
                '',                                                    // Parent UID
                implode(',', $marks),                                  // Mark
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
