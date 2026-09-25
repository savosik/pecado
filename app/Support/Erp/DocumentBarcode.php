<?php

namespace App\Support\Erp;

/**
 * Штрихкод печатной формы документа 1С ↔ GUID документа (pick-08).
 *
 * Типовая подсистема «Штрихкодирование печатных форм» (УТ 11, КА 2, ERP): на форме печатается
 * Code128 с десятичным числом, полученным из GUID ссылки документа — дефисы убираются, 32 hex-знака
 * читаются как одно число. Контрольного символа сверх штатного Code128 нет. Обратно: десятичное →
 * hex → дополнить нулями слева до 32 знаков → разбить 8-4-4-4-12.
 *
 * Число до 39 знаков не помещается в int, поэтому арифметика — на строках, без gmp/bcmath
 * (расширения в контейнере не гарантированы).
 */
final class DocumentBarcode
{
    private const MAX_DIGITS = 39; // 2^128 − 1 = 340282366920938463463374607431768211455

    /** Десятичный штрихкод → GUID в нижнем регистре; null — строка не похожа на штрихкод документа. */
    public static function toGuid(string $barcode): ?string
    {
        $digits = preg_replace('/\s+/', '', $barcode) ?? '';
        if ($digits === '' || ! ctype_digit($digits) || strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        $hex = self::decToHex($digits);
        if (strlen($hex) > 32) {
            return null;
        }

        $hex = str_pad($hex, 32, '0', STR_PAD_LEFT);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** GUID → десятичный штрихкод, как его печатает 1С. */
    public static function fromGuid(string $guid): ?string
    {
        $hex = strtolower(str_replace('-', '', trim($guid)));
        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            return null;
        }

        return self::hexToDec($hex);
    }

    private static function decToHex(string $dec): string
    {
        $dec = ltrim($dec, '0');
        if ($dec === '') {
            return '0';
        }

        $hex = '';
        while ($dec !== '') {
            $remainder = 0;
            $quotient = '';
            foreach (str_split($dec) as $digit) {
                $current = $remainder * 10 + (int) $digit;
                $quotient .= intdiv($current, 16);
                $remainder = $current % 16;
            }
            $hex = dechex($remainder).$hex;
            $dec = ltrim($quotient, '0');
        }

        return $hex;
    }

    private static function hexToDec(string $hex): string
    {
        $dec = '0';
        foreach (str_split($hex) as $char) {
            $carry = hexdec($char);
            $next = '';
            foreach (array_reverse(str_split($dec)) as $digit) {
                $value = (int) $digit * 16 + $carry;
                $next = ($value % 10).$next;
                $carry = intdiv($value, 10);
            }
            $dec = ltrim(($carry > 0 ? $carry : '').$next, '0') ?: '0';
        }

        return $dec;
    }
}
