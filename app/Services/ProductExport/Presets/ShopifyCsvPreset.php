<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;
use Illuminate\Support\Str;

/**
 * Shopify CSV — строгий формат для импорта товаров в Shopify.
 * Использует chunk-подход для больших каталогов.
 */
class ShopifyCsvPreset extends AbstractPreset
{
    public function key(): string
    {
        return 'shopify';
    }

    public function name(): string
    {
        return 'Shopify (CSV)';
    }

    public function description(): string
    {
        return 'CSV-файл для импорта каталога в Shopify. Включает Handle, описание, бренд, цены, остатки, картинки.';
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
        return 'green';
    }

    public function icon(): string
    {
        return 'LuShoppingBag';
    }

    protected function getHeaders(): array
    {
        return [
            'Handle', 'Title', 'Body (HTML)', 'Vendor', 'Product Category',
            'Type', 'Tags', 'Published', 'Variant SKU', 'Variant Grams',
            'Variant Inventory Qty', 'Variant Price', 'Variant Compare At Price',
            'Variant Barcode', 'Image Src', 'Image Position', 'Image Alt Text',
            'SEO Title', 'SEO Description', 'Status',
        ];
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        // BOM for UTF-8
        fwrite($stream, "\xEF\xBB\xBF");

        $headers = $this->getHeaders();
        fputcsv($stream, $headers);

        $this->eachChunk($export, function ($items) use ($stream, $headers) {
            foreach ($items as $item) {
                $handle = Str::slug($item['name'].'-'.($item['sku'] ?: $item['id']));

                $tags = collect($item['attributes'])
                    ->map(fn ($a) => "{$a['name']}:{$a['value']}")
                    ->implode(', ');

                $row = [
                    $handle, $item['name'], $item['description'] ?? '',
                    $item['brand_name'] ?? '', $item['category_path'] ?? '',
                    $item['category_name'] ?? '', $tags, 'TRUE',
                    $item['sku'] ?? '', '',
                    (string) $item['stock'], (string) $item['price'],
                    $item['base_price'] > $item['price'] ? (string) $item['base_price'] : '',
                    $item['barcode'] ?? '', $item['main_image'] ?? '',
                    $item['main_image'] ? '1' : '', $item['name'],
                    $item['meta_title'] ?? '', $item['meta_description'] ?? '',
                    $item['stock'] > 0 ? 'active' : 'draft',
                ];
                fputcsv($stream, $row);

                // Дополнительные картинки
                foreach ($item['additional_images'] as $i => $imgUrl) {
                    $imgRow = array_fill(0, count($headers), '');
                    $imgRow[0] = $handle;
                    $imgRow[array_search('Image Src', $headers)] = $imgUrl;
                    $imgRow[array_search('Image Position', $headers)] = (string) ($i + 2);
                    $imgRow[array_search('Image Alt Text', $headers)] = $item['name'].' — фото '.($i + 2);
                    fputcsv($stream, $imgRow);
                }
            }
        });
    }
}
