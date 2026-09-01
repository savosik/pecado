<?php

namespace App\Support\Settlements;

use Carbon\CarbonImmutable;

/**
 * Разбор «объекта расчётов» из регистра взаиморасчётов 1С.
 *
 * 1С кладёт в `settlement_entries.settlement_object_name` человеческое имя
 * документа, за который идёт расчёт: «Реализация товаров и услуг 29УТ-007601
 * от 21.08.2026 11:03:42». Это единственный способ узнать, за какую именно
 * реализацию пришли деньги: `settlement_object_uuid` указывает на запись
 * регистра, а не на документ, и с `shipments.uuid` не совпадает — у той же
 * реализации UUID документа `d38adcbf-…`, а объекта расчётов `d38adcc0-…`.
 *
 * Поэтому разбор идёт по имени и только по нему. Номер находится примерно у
 * 85 % строк; остальным показываем имя как есть, без ссылки — соврать ссылкой
 * на чужой документ хуже, чем оставить строку текстом.
 *
 * Разбор живёт в PHP, а не в SQL: `SUBSTRING_INDEX` есть в MySQL, но не в
 * SQLite, на котором идут тесты, — такой запрос падал бы только на проде.
 */
final class SettlementObject
{
    /** Префиксы имён 1С → вид документа и подпись для интерфейса. */
    private const PREFIXES = [
        'Реализация товаров и услуг' => ['shipment', 'Реализация'],
        'Реализация услуг' => ['shipment', 'Реализация услуг'],
        'Реализация' => ['shipment', 'Реализация'],
        'Заказ клиента' => ['order', 'Заказ'],
        'Поступление безналичных ДС' => ['payment', 'Платёж без разнесения'],
        'Поступление наличных ДС' => ['payment', 'Платёж без разнесения'],
        'Первичный документ' => ['initial', 'Первичный документ'],
    ];

    /**
     * @return array{kind: ?string, kind_label: string, number: ?string, date: ?string}
     */
    public static function parse(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['kind' => null, 'kind_label' => 'Документ', 'number' => null, 'date' => null];
        }

        [$kind, $label] = self::classify($name);

        // Имя всегда вида «<название документа> <номер> от <дата> <время>».
        // Отсутствие « от » означает, что 1С прислала что-то другое, — тогда
        // номер не выдумываем.
        $head = $name;
        $date = null;

        if (preg_match('/^(.*?) от (\d{2}\.\d{2}\.\d{4})/u', $name, $matches) === 1) {
            $head = $matches[1];
            $date = $matches[2];
        }

        $number = null;

        if (preg_match('/(\S+)$/u', trim($head), $matches) === 1) {
            $candidate = $matches[1];

            // Номер обязан содержать цифру: у документа без номера последним
            // словом окажется часть названия, и она поехала бы в колонку
            // «номер» как настоящий реквизит.
            if (preg_match('/\d/u', $candidate) === 1 && $candidate !== $head) {
                $number = $candidate;
            }
        }

        return [
            'kind' => $kind,
            'kind_label' => $label,
            'number' => $number,
            'date' => $date,
        ];
    }

    /** Дата документа объекта расчётов, если 1С её указала. */
    public static function documentDate(?string $name): ?CarbonImmutable
    {
        $date = self::parse($name)['date'];

        return $date !== null ? CarbonImmutable::createFromFormat('d.m.Y', $date)->startOfDay() : null;
    }

    /** @return array{0: ?string, 1: string} */
    private static function classify(string $name): array
    {
        foreach (self::PREFIXES as $prefix => $meta) {
            if (str_starts_with($name, $prefix)) {
                return $meta;
            }
        }

        return [null, 'Документ'];
    }
}
