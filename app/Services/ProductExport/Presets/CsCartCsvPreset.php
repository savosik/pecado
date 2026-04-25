<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * CS-Cart CSV — chunk-based.
 */
class CsCartCsvPreset extends AbstractPreset
{
    public function key(): string
    {
        return 'cscart';
    }

    public function name(): string
    {
        return 'CS-Cart (CSV)';
    }

    public function description(): string
    {
        return 'CSV-файл для штатного импорта товаров в CS-Cart 4. Товары, цены, остатки, картинки и характеристики.';
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
        return 'teal';
    }

    public function icon(): string
    {
        return 'LuStore';
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        fwrite($stream, "\xEF\xBB\xBF");

        $headersWritten = false;

        $this->eachChunk($export, function ($items) use ($stream, &$headersWritten) {
            $maxAttrs = $items->max(fn ($item) => count($item['attributes']));

            if (! $headersWritten) {
                $headers = [
                    'Product code', 'Language', 'Product name', 'Category',
                    'Price', 'List price', 'Quantity', 'Status', 'Store',
                    'Images', 'Detailed description', 'Short description',
                    'Meta keywords', 'Meta description', 'Search words',
                    'Page title', 'Brand/Manufacturer',
                    'Weight (kg)', 'Weight gross (kg)',
                    'Box length (m)', 'Box width (m)', 'Box height (m)', 'HS code',
                ];
                for ($i = 1; $i <= $maxAttrs; $i++) {
                    $headers[] = "Feature {$i} name";
                    $headers[] = "Feature {$i} value";
                }
                fputcsv($stream, $headers, ';');
                $headersWritten = true;
            }

            foreach ($items as $item) {
                $allImages = collect([$item['main_image']])
                    ->merge($item['additional_images'])->filter()->implode('///');

                $row = [
                    $item['sku'] ?? (string) $item['id'], 'ru', $item['name'],
                    str_replace(' > ', '///', $item['category_path'] ?? ''),
                    (string) $item['price'],
                    $item['base_price'] > $item['price'] ? (string) $item['base_price'] : '',
                    (string) $item['stock'],
                    $item['stock'] > 0 ? 'A' : 'D',
                    config('app.name', 'Pecado'),
                    $allImages, $item['description'] ?? '',
                    $item['short_description'] ?? '', '',
                    $item['meta_description'] ?? '', $item['name'],
                    $item['meta_title'] ?? '', $item['brand_name'] ?? '',
                    $item['weight_net'] !== null ? (string) $item['weight_net'] : '',
                    $item['weight_gross'] !== null ? (string) $item['weight_gross'] : '',
                    $item['depth'] !== null ? (string) $item['depth'] : '',
                    $item['width'] !== null ? (string) $item['width'] : '',
                    $item['height'] !== null ? (string) $item['height'] : '',
                    $item['hs_code'] ?? '',
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
