<?php

namespace App\Support\Search;

final class DocumentNumber
{
    /**
     * Нормализация номера документа: lowercase + удаление пробелов и дефисов.
     *
     * `29УТ-003413` → `29ут003413`
     * `  29 УТ - 003413  ` → `29ут003413`
     */
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($value)));
    }

    /**
     * Эвристика «строка похожа на штрихкод» — 8/12/13/14 цифр подряд.
     */
    public static function isLikelyBarcode(string $value): bool
    {
        return preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $value) === 1;
    }

    /**
     * Эвристика «строка похожа на ИНН» — 10 или 12 цифр подряд.
     */
    public static function isLikelyTaxId(string $value): bool
    {
        return preg_match('/^\d{10}$|^\d{12}$/', $value) === 1;
    }
}
