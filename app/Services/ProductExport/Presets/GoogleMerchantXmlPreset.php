<?php

namespace App\Services\ProductExport\Presets;

use App\Models\ProductExport;

/**
 * Google Merchant Center / Facebook Commerce XML Feed.
 *
 * Формат: RSS 2.0 с пространством имён g: (Google Shopping).
 * Подходит для Google Ads, Facebook/Instagram Commerce.
 */
class GoogleMerchantXmlPreset extends AbstractPreset
{
    public function key(): string { return 'google_merchant'; }
    public function name(): string { return 'Google Merchant / Facebook (XML)'; }
    public function description(): string { return 'XML-фид для Google Merchant Center, Google Ads и каталогов Facebook/Instagram.'; }
    public function fileExtension(): string { return 'xml'; }
    public function mimeType(): string { return 'application/xml; charset=utf-8'; }
    public function color(): string { return 'red'; }
    public function icon(): string { return 'LuSearch'; }

    public function writeToStream($stream, ProductExport $export): void
    {
        $data = $this->fetchRichData($export);

        $xml = new \XMLWriter();
        $xml->openURI('php://output');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');

        $xml->startElement('channel');
        $xml->writeElement('title', config('app.name', 'Pecado') . ' — Product Feed');
        $xml->writeElement('link', config('app.url'));
        $xml->writeElement('description', 'Product catalog feed');

        foreach ($data as $item) {
            $xml->startElement('item');

            $xml->writeElement('g:id', (string) $item['id']);
            $xml->writeElement('g:title', $item['name']);

            if ($item['description']) {
                $xml->startElement('g:description');
                $xml->writeCdata(mb_substr(strip_tags($item['description']), 0, 5000));
                $xml->endElement();
            }

            if ($item['url']) {
                $xml->writeElement('g:link', $item['url']);
            }

            if ($item['main_image']) {
                $xml->writeElement('g:image_link', $item['main_image']);
            }

            foreach ($item['additional_images'] as $imgUrl) {
                $xml->writeElement('g:additional_image_link', $imgUrl);
            }

            $xml->writeElement('g:availability', $item['stock'] > 0 ? 'in_stock' : 'out_of_stock');

            $xml->writeElement('g:price', number_format($item['price'], 2, '.', '') . ' RUB');

            if ($item['base_price'] > $item['price']) {
                $xml->writeElement('g:sale_price', number_format($item['price'], 2, '.', '') . ' RUB');
                $xml->writeElement('g:price', number_format($item['base_price'], 2, '.', '') . ' RUB');
            }

            if ($item['brand_name']) {
                $xml->writeElement('g:brand', $item['brand_name']);
            }

            if ($item['sku']) {
                $xml->writeElement('g:mpn', $item['sku']);
            }

            if (!empty($item['barcodes'])) {
                $xml->writeElement('g:gtin', $item['barcodes'][0]);
            } elseif ($item['barcode']) {
                $xml->writeElement('g:gtin', $item['barcode']);
            } else {
                $xml->writeElement('g:identifier_exists', 'false');
            }

            $xml->writeElement('g:condition', 'new');

            if ($item['category_path']) {
                $xml->writeElement('g:product_type', $item['category_path']);
            }

            // Атрибуты через custom labels
            $attrIndex = 0;
            foreach ($item['attributes'] as $attr) {
                if ($attrIndex >= 5) break; // Google allows max 5 custom labels
                $xml->writeElement("g:custom_label_{$attrIndex}", "{$attr['name']}: {$attr['value']}");
                $attrIndex++;
            }

            $xml->endElement(); // item
        }

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();
        $xml->flush();
    }
}
