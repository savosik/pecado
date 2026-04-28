<?php

namespace App\Support\Search;

final class QueryRouter
{
    public const TYPE_TEXT = 'text';

    public const TYPE_BARCODE = 'barcode';

    public const TYPE_TAX_ID = 'tax_id';

    public const TYPE_UUID = 'uuid';

    public const TYPE_DOCUMENT_NUMBER = 'document_number';

    public const TYPE_SKU_LIKE = 'sku_like';

    /**
     * Классификатор пользовательского запроса.
     *
     * Эвристики (приоритет сверху вниз):
     *  1. UUID — фрагмент `/^[0-9a-f-]{8,36}$/i`, при условии что строка содержит `-`
     *     или букву a-f (иначе это чистая цифровая строка — её отдадим штрихкоду / ИНН).
     *  2. Штрихкод — 8/12/13/14 цифр.
     *  3. ИНН — 10 или 12 цифр (приоритет ниже штрихкода: 12 цифр трактуем как штрихкод).
     *  4. Номер документа — содержит дефис + кириллицу (`29УТ-003413`).
     *  5. SKU-like — только латиница/цифры/дефис, без пробелов, с хотя бы одной буквой.
     *  6. Текст — всё остальное.
     */
    public static function classify(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return self::TYPE_TEXT;
        }

        if (
            preg_match('/^[0-9a-f-]{8,36}$/i', $value) === 1
            && preg_match('/[a-f-]/i', $value) === 1
        ) {
            return self::TYPE_UUID;
        }

        if (DocumentNumber::isLikelyBarcode($value)) {
            return self::TYPE_BARCODE;
        }

        if (DocumentNumber::isLikelyTaxId($value)) {
            return self::TYPE_TAX_ID;
        }

        if (
            str_contains($value, '-')
            && preg_match('/[а-яё]/iu', $value) === 1
            && preg_match('/\d/', $value) === 1
        ) {
            return self::TYPE_DOCUMENT_NUMBER;
        }

        if (
            preg_match('/^[a-z0-9-]+$/i', $value) === 1
            && preg_match('/[a-z]/i', $value) === 1
        ) {
            return self::TYPE_SKU_LIKE;
        }

        return self::TYPE_TEXT;
    }
}
