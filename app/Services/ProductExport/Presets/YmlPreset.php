<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * Yandex.Market Language (YML) — стандартный XML-фид.
 * Подходит для: 1С-Битрикс, InSales, Prom.ua, Яндекс.Маркет, OpenCart (через модули).
 *
 * Спецификация: https://yandex.ru/support/merchants/yml/
 *
 * Использует chunk-подход для обработки больших каталогов (~10k+ товаров)
 * без переполнения памяти. XMLWriter пишет в память и периодически
 * сбрасывается в файловый поток.
 */
class YmlPreset extends AbstractPreset
{
    public function key(): string
    {
        return 'yml';
    }

    public function name(): string
    {
        return 'Yandex.Market / 1С-Битрикс (YML)';
    }

    public function description(): string
    {
        return 'XML-фид для Яндекс.Маркет, 1С-Битрикс, InSales, Prom.ua и других CMS, поддерживающих YML-формат.';
    }

    public function fileExtension(): string
    {
        return 'xml';
    }

    public function mimeType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    public function color(): string
    {
        return 'orange';
    }

    public function icon(): string
    {
        return 'LuFileCode';
    }

    public function writeToStream($stream, ProductExport $export): void
    {
        $categories = $this->fetchCategories();

        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', now()->format('Y-m-d H:i'));

        // <shop>
        $xml->startElement('shop');
        $xml->writeElement('name', config('app.name', 'Pecado'));
        $xml->writeElement('company', config('app.name', 'Pecado'));
        $xml->writeElement('url', config('app.url'));

        // <currencies>
        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', 'RUR');
        $xml->writeAttribute('rate', '1');
        $xml->endElement(); // currency
        $xml->endElement(); // currencies

        // <categories>
        $xml->startElement('categories');
        foreach ($categories as $cat) {
            $xml->startElement('category');
            $xml->writeAttribute('id', (string) $cat->id);
            if ($cat->parent_id) {
                $xml->writeAttribute('parentId', (string) $cat->parent_id);
            }
            $xml->text($cat->name);
            $xml->endElement();
        }
        $xml->endElement(); // categories

        // Сбросить заголовочную часть в поток
        fwrite($stream, $xml->flush(true));

        // <offers>
        $xml->startElement('offers');
        fwrite($stream, $xml->flush(true));

        // Обработка товаров чанками
        $this->eachChunk($export, function ($items) use ($stream, $xml) {
            foreach ($items as $item) {
                $xml->startElement('offer');
                $xml->writeAttribute('id', (string) $item['id']);
                $xml->writeAttribute('available', $item['stock'] > 0 ? 'true' : 'false');

                $xml->writeElement('name', $item['name']);

                if ($item['url']) {
                    $xml->writeElement('url', $item['url']);
                }

                $xml->writeElement('price', (string) $item['price']);
                $xml->writeElement('currencyId', 'RUR');

                if ($item['category_id']) {
                    $xml->writeElement('categoryId', (string) $item['category_id']);
                }

                // Картинки
                if ($item['main_image']) {
                    $xml->writeElement('picture', $item['main_image']);
                }
                foreach ($item['additional_images'] as $imgUrl) {
                    $xml->writeElement('picture', $imgUrl);
                }

                if ($item['brand_name']) {
                    $xml->writeElement('vendor', $item['brand_name']);
                }

                if ($item['sku']) {
                    $xml->writeElement('vendorCode', $item['sku']);
                }

                if (! empty($item['barcodes'])) {
                    foreach ($item['barcodes'] as $bc) {
                        $xml->writeElement('barcode', $bc);
                    }
                } elseif ($item['barcode']) {
                    $xml->writeElement('barcode', $item['barcode']);
                }

                if ($item['description']) {
                    $xml->startElement('description');
                    $xml->writeCdata($item['description']);
                    $xml->endElement();
                }

                if ($item['model_name']) {
                    $xml->writeElement('model', $item['model_name']);
                }

                // Количество на складе
                $xml->writeElement('count', (string) $item['stock']);

                // Все атрибуты как <param>
                foreach ($item['attributes'] as $attr) {
                    $xml->startElement('param');
                    $xml->writeAttribute('name', $attr['name']);
                    if ($attr['unit']) {
                        $xml->writeAttribute('unit', $attr['unit']);
                    }
                    $xml->text($attr['value']);
                    $xml->endElement();
                }

                $xml->endElement(); // offer

                // Сбрасываем буфер каждого товара в поток
                fwrite($stream, $xml->flush(true));
            }
        });

        // Закрываем </offers>, </shop>, </yml_catalog>
        $xml->endElement(); // offers
        $xml->endElement(); // shop
        $xml->endElement(); // yml_catalog
        $xml->endDocument();

        fwrite($stream, $xml->flush(true));
    }
}
