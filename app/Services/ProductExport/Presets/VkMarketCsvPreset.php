<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * ВКонтакте Магазин CSV — chunk-based.
 */
class VkMarketCsvPreset extends AbstractPreset
{
    public function key(): string
    {
        return 'vk';
    }

    public function name(): string
    {
        return 'Магазин ВКонтакте (CSV)';
    }

    public function description(): string
    {
        return 'CSV-файл для пакетной загрузки товаров в Магазин ВКонтакте (паблики и группы).';
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
        return 'blue';
    }

    public function icon(): string
    {
        return 'LuMessageCircle';
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        fwrite($stream, "\xEF\xBB\xBF");

        $headersWritten = false;

        $this->eachChunk($export, function ($items) use ($stream, &$headersWritten) {
            $maxAttrs = $items->max(fn ($item) => count($item['attributes']));

            if (! $headersWritten) {
                $headers = [
                    'Название', 'Описание', 'Фото (ссылки через запятую)', 'Категория',
                    'Цена', 'Старая цена', 'Артикул', 'В наличии', 'Бренд',
                    'Вес брутто, кг', 'Вес нетто, кг',
                    'Ширина, м', 'Высота, м', 'Глубина, м', 'Код ТН ВЭД',
                ];
                for ($i = 1; $i <= $maxAttrs; $i++) {
                    $headers[] = "Свойство {$i} название";
                    $headers[] = "Свойство {$i} значение";
                }
                fputcsv($stream, $headers, ';');
                $headersWritten = true;
            }

            foreach ($items as $item) {
                $allImages = collect([$item['main_image']])
                    ->merge($item['additional_images'])->filter()->implode(', ');

                $row = [
                    $item['name'], $item['description_plain'] ?? '', $allImages,
                    $item['category_name'] ?? '', (string) $item['price'],
                    $item['base_price'] > $item['price'] ? (string) $item['base_price'] : '',
                    $item['sku'] ?? '', $item['stock'] > 0 ? 'Да' : 'Нет',
                    $item['brand_name'] ?? '',
                    $item['weight_gross'] !== null ? (string) $item['weight_gross'] : '',
                    $item['weight_net'] !== null ? (string) $item['weight_net'] : '',
                    $item['width'] !== null ? (string) $item['width'] : '',
                    $item['height'] !== null ? (string) $item['height'] : '',
                    $item['depth'] !== null ? (string) $item['depth'] : '',
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
