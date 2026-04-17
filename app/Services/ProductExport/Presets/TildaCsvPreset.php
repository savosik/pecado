<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * Tilda Publishing CSV — chunk-based.
 */
class TildaCsvPreset extends AbstractPreset
{
    public function key(): string
    {
        return 'tilda';
    }

    public function name(): string
    {
        return 'Tilda Publishing (CSV)';
    }

    public function description(): string
    {
        return 'CSV-файл для импорта товаров в интернет-магазин на платформе Tilda.';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function color(): string
    {
        return 'yellow';
    }

    public function icon(): string
    {
        return 'LuPenTool';
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        fwrite($stream, "\xEF\xBB\xBF");

        $headersWritten = false;

        $this->eachChunk($export, function ($items) use ($stream, &$headersWritten) {
            $maxAttrs = $items->max(fn ($item) => count($item['attributes']));

            if (! $headersWritten) {
                $headers = [
                    'Brand', 'SKU', 'Category', 'Title', 'Description', 'Text',
                    'Photo', 'Price', 'Price Old', 'Quantity', 'External ID',
                    'Parent UID', 'Mark',
                ];
                for ($i = 1; $i <= $maxAttrs; $i++) {
                    $headers[] = "Characteristic {$i} Title";
                    $headers[] = "Characteristic {$i} Value";
                }
                fputcsv($stream, $headers, ';');
                $headersWritten = true;
            }

            foreach ($items as $item) {
                $allImages = collect([$item['main_image']])
                    ->merge($item['additional_images'])->filter()->implode(';');

                $marks = [];
                if ($item['is_new']) {
                    $marks[] = 'new';
                }
                if ($item['is_bestseller']) {
                    $marks[] = 'bestseller';
                }

                $row = [
                    $item['brand_name'] ?? '', $item['sku'] ?? '',
                    $item['category_path'] ?? '', $item['name'],
                    $item['short_description'] ?? '', $item['description'] ?? '',
                    $allImages, (string) $item['price'],
                    $item['base_price'] > $item['price'] ? (string) $item['base_price'] : '',
                    (string) $item['stock'],
                    $item['external_id'] ?? (string) $item['id'],
                    '', implode(',', $marks),
                ];

                foreach ($item['attributes'] as $attr) {
                    $row[] = $attr['name'];
                    $row[] = $attr['value'].($attr['unit'] ? " {$attr['unit']}" : '');
                }
                $remaining = ($maxAttrs - count($item['attributes'])) * 2;
                for ($i = 0; $i < $remaining; $i++) {
                    $row[] = '';
                }

                fputcsv($stream, $row, ';');
            }
        });
    }
}
