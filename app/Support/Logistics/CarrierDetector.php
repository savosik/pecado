<?php

namespace App\Support\Logistics;

/**
 * Опознание отгрузки на терминал транспортной компании.
 *
 * Половина строк таблицы — это не адрес клиента, а перевалка: «терминал в
 * г.Самара», тип доставки «ТК Деловые линии». Такой адрес принадлежит
 * перевозчику, и в кабинете его нужно подписывать именем ТК, иначе клиент
 * увидит у себя чужой склад без объяснений.
 */
final class CarrierDetector
{
    /** Перевозчики по куску названия: ключ ищется как подстрока. */
    private const CARRIERS = [
        'деловые лин' => 'Деловые линии',
        'сдэк' => 'СДЭК',
        'сдек' => 'СДЭК',
        'cdek' => 'СДЭК',
        'желдор' => 'ЖелДорЭкспедиция',
        'байкал' => 'Байкал Сервис',
        'боксбери' => 'Боксбери',
        'возовоз' => 'Возовоз',
        'энергия' => 'Энергия',
        'магистраль' => 'Магистраль',
        'стеил' => 'Стеил',
        'кашалот' => 'Кашалот',
        'почта росс' => 'Почта России',
    ];

    /**
     * Перевозчики-аббревиатуры: ищутся только целым словом.
     *
     * «КИТ» внутри «Никитин», «ДЛ» внутри любого слова и «ПЭК» внутри «спэкшн»
     * превратили бы адрес клиента в терминал, поэтому здесь нужны границы слова —
     * а вот к кускам названий выше их применять нельзя: «деловые лин» оборвётся
     * на «линии».
     */
    private const ABBREVIATIONS = [
        'дл' => 'Деловые линии',
        'пэк' => 'ПЭК',
        'жде' => 'ЖелДорЭкспедиция',
        'dpd' => 'DPD',
        'дпд' => 'DPD',
        'нрг' => 'НРГ',
        'nrg' => 'НРГ',
        'кит' => 'КИТ',
    ];

    /**
     * Название перевозчика, если отгрузка идёт на его терминал.
     *
     * Тип доставки — источник надёжнее адреса: «ТК Деловые линии» стоит в нём
     * почти всегда, тогда как в адресе перевозчика может не быть вовсе.
     */
    public static function detect(string $delivery, string $address): ?string
    {
        $deliveryText = self::normalize($delivery);
        $addressText = self::normalize($address);

        foreach (self::CARRIERS as $needle => $name) {
            if (str_contains($deliveryText, $needle) || str_contains($addressText, $needle)) {
                return $name;
            }
        }

        foreach (self::ABBREVIATIONS as $needle => $name) {
            if (self::mentions($deliveryText, $needle) || self::mentions($addressText, $needle)) {
                return $name;
            }
        }

        // Терминал без названия ТК: «терминал г. Вологда» — перевозчик неизвестен,
        // но адресом клиента это точно не является.
        if (str_contains($deliveryText, 'терминал') || str_contains($addressText, 'терминал')) {
            return 'ТК';
        }

        return null;
    }

    /**
     * Самовывоз со склада: адреса клиента в такой строке нет вообще.
     */
    public static function isSelfPickup(string $delivery, string $address): bool
    {
        return str_contains(self::normalize($delivery), 'самовывоз')
            || str_contains(self::normalize($address), 'самовывоз');
    }

    /**
     * Совпадение по границе слова: без этого «кит» ловится в «Никитин», а «дл» —
     * в любом слове с этими буквами подряд.
     */
    private static function mentions(string $text, string $needle): bool
    {
        return (bool) preg_match('/(?<![а-яa-z])'.preg_quote($needle, '/').'(?![а-яa-z])/u', $text);
    }

    private static function normalize(string $value): string
    {
        $text = mb_strtolower(trim($value));
        $text = str_replace(["\u{00A0}", 'ё'], [' ', 'е'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
