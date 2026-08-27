<?php

namespace App\Support;

/**
 * Единый вид телефона в справочнике.
 *
 * Люди записывают номера как попало: «8 (926) 353-80-95», «+7-913-393-91-08»,
 * «7 977 652 0740». Поиск это переживает — он идёт по `phone_digits`, — а глаз
 * менеджера и выгрузка в телефон нет: в списке из ста строк разнобой читается
 * как разные форматы разных источников, и доверие к данным падает.
 *
 * Поэтому номер приводится к «+7 926 353-80-95». Восьмёрка становится семёркой,
 * как и в {@see \App\Models\Contact::digitsOf()}: это один и тот же номер.
 * Неполный или незнакомый номер возвращается как есть — придумывать цифры хуже,
 * чем оставить строку, по которой менеджер поймёт, что надо уточнить.
 */
final class PhoneFormatter
{
    /**
     * Разбивка национальной части по коду страны.
     *
     * Ключи — коды стран; PHP приводит числовые строковые ключи к int.
     *
     * @var array<int, list<int>>
     */
    private const GROUPS = [
        '7' => [3, 3, 2, 2],     // Россия, Казахстан: +7 926 353-80-95
        '375' => [2, 3, 2, 2],   // Беларусь: +375 33 651-45-35
        '380' => [2, 3, 2, 2],   // Украина: +380 50 347-14-64
        '998' => [2, 3, 2, 2],   // Узбекистан: +998 88 333-57-75
        '992' => [3, 2, 2, 2],   // Таджикистан: +992 918 43-16-55
        '996' => [3, 3, 3],      // Киргизия: +996 555 153-014
        '993' => [2, 2, 2, 2],   // Туркменистан: +993 61 16-44-39
        '420' => [3, 3, 3],      // Чехия: +420 723 653-081
    ];

    public static function format(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return $raw;
        }

        // «8 926 …» — российская запись семёрки; «926 …» без кода — тоже Россия.
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7'.$digits;
        }

        foreach (self::GROUPS as $code => $groups) {
            $code = (string) $code;

            if (! str_starts_with($digits, $code)) {
                continue;
            }

            $national = substr($digits, strlen($code));

            if (strlen($national) !== array_sum($groups)) {
                // Неполный номер: не дорисовываем, отдаём как записали.
                return $raw;
            }

            return '+'.$code.' '.self::join($national, $groups);
        }

        return $raw;
    }

    /**
     * «9263538095» + [3,3,2,2] → «926 353-80-95»: первые две группы через пробел,
     * дальше через дефис — так пишут на визитках, так и читается быстрее всего.
     *
     * @param  list<int>  $groups
     */
    private static function join(string $national, array $groups): string
    {
        $parts = [];
        $offset = 0;

        foreach ($groups as $size) {
            $parts[] = substr($national, $offset, $size);
            $offset += $size;
        }

        $head = array_shift($parts);

        return $head.' '.implode('-', $parts);
    }
}
