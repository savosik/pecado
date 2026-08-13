<?php

namespace App\Support\Logistics;

/**
 * Приведение адреса из таблицы логиста к строке, которую понимает DaData.
 *
 * Менеджер пишет в колонку «Адрес» не адрес, а поручение: «ЗА СЧЕТ КЛИЕНТА: до
 * пункта выдачи Яндекс.Маркет г. Воронеж (Московский пр-т, 11)». DaData на такую
 * строку не отвечает вовсе — из 318 адресов первого прогона разобрались 80.
 * После вычистки поручений разбирается втрое больше, а заодно перестают
 * двоиться адреса, отличающиеся только припиской «за наш счёт».
 */
final class AddressCleaner
{
    /** Условия доставки: к адресу отношения не имеют. */
    private const TERMS = '/за\s+сч[её]т\s+клиента|за\s+наш\s+сч[её]т|до\s+адреса|до\s+дверей|самовывоз|по\s+звонку|обязательно|срочно/iu';

    /**
     * Хвост с получателем: после него адрес уже не продолжается.
     *
     * «г.Ростов-на-Дону, ул.Доватора 142И. ПОЛУЧАТЕЛЬ: ИП Гринь В.» — всё после
     * «ПОЛУЧАТЕЛЬ» geocoder-у только мешает, а половина строк таблицы такова.
     */
    private const RECIPIENT_TAIL = '/\s*[,.;]?\s*\b(получател[ья]|грузополучател[ья]|контактное\s+лицо|конт\.?\s*лицо|тел|телефон|моб|e-?mail|инн|прием\s+товара|приём\s+товара|до\s+магазина)\b.*$/iu';

    /** Приписки перед адресом: «Адрес доставки:», «Забрать можно по адресу:». */
    private const PREFIXES = '/^\s*(адрес\s+доставк\w*|адрес\s+терминала|адрес|забрать\s+можно\s+по\s+адресу|по\s+адресу|доставка\s+в|доставка)\s*:?\s*/iu';

    /** Площадки и пункты выдачи: название бренда мешает геокодеру. */
    private const BRANDS = '/до\s+пункта\s+выдачи|пункт[а]?\s+выдачи|\bпвз\b|яндекс[\.\s]*маркет|яндекс[\.\s]*лавка|яндекс[\.\s]*доставка|\bям\b|вайлдберриз|wildberries|\bвб\b|\bфбо\b|\bфбс\b|озон|ozon|до\s+терминала|терминал[а]?/iu';

    /**
     * Организационный шум: «Обособленное подразделение ООО …», «ТЦ», «Адрес:».
     *
     * «АО» здесь — это административный округ Москвы («Москва г АО, Энтузиастов
     * ш»), из-за которого DaData не находит улицу; акционерным обществом в
     * колонке адреса оно не бывает.
     */
    private const NOISE = '/обособленное\s+подразделение|\bооо\b|\bип\b|\bзао\b|\bоао\b|\bао\b|\bбц\b|\bтц\b|\bтрц\b|\bффц\b|\bскл(ад)?\b|адрес\s*:/iu';

    /** Записи, которые адресом не являются: пометки логисту вместо точки доставки. */
    private const NOT_AN_ADDRESS = '/накладн|отправка|оформлен|уточнить|перезвонить|по\s+согласованию|как\s+обычно|см\.\s|тот\s+же/iu';

    /** Детали внутри здания: DaData их не разбирает, но клиенту они нужны. */
    private const DETAILS = '/\b(офис|оф|каб|кабинет|этаж|эт|пав|павильон|секция|бокс|подъезд|кпп|склад|ворота)\.?\s*№?\s*([\w\-\/]+)/iu';

    /**
     * Строка для геокодера: без поручений, брендов и лишней пунктуации.
     */
    public static function tidy(string $address): string
    {
        $text = str_replace(["\u{00A0}", "\n", "\r", "\t", '«', '»', '"', '•'], ' ', $address);
        $text = (string) preg_replace(self::RECIPIENT_TAIL, ' ', $text);
        $text = (string) preg_replace(self::PREFIXES, ' ', $text);
        $text = (string) preg_replace(self::TERMS, ' ', $text);
        $text = (string) preg_replace(self::BRANDS, ' ', $text);
        $text = (string) preg_replace(self::NOISE, ' ', $text);

        // В скобках чаще всего лежит сам адрес: «…г. Воронеж (Московский пр-т, 11)».
        $text = (string) preg_replace('/\s*\(([^)]*)\)/u', ' $1 ', $text);
        $text = (string) preg_replace('/\bм\.\s*[А-ЯЁ][а-яё]+/u', ' ', $text);      // станция метро
        $text = (string) preg_replace('/[.,;:!]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text), ' -–—');
    }

    /**
     * Запросы к геокодеру в порядке убывания надёжности.
     *
     * Хвост строки — самое мусорное место: там дописывают «пав 15», «КПП 6»,
     * «этаж 1», и из-за них DaData не находит дом. Поэтому после полной строки
     * пробуем её же без последних кусков — до трёх заходов, дальше начинается
     * гадание.
     *
     * @return list<string>
     */
    public static function queryVariants(string $address): array
    {
        $tidy = self::tidy($address);

        if ($tidy === '') {
            return [];
        }

        $variants = [$tidy];

        $withoutDetails = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::DETAILS, ' ', $tidy)));

        if ($withoutDetails !== '' && $withoutDetails !== $tidy) {
            $variants[] = $withoutDetails;
        }

        $base = $withoutDetails !== '' ? $withoutDetails : $tidy;

        // Мусор бывает и в начале — названием склада или арендатора: «Обособленное
        // подразделение Маркет Операции ФФЦ Супер Склад 140126 Московская обл…».
        // Индекс и слово-маркер региона отмечают, где начинается собственно адрес.
        if (preg_match('/\b\d{6}\b.*/u', $base, $matches)) {
            $variants[] = trim($matches[0]);
        }

        if (preg_match('/\b(?:г|обл|область|край|респ|республика|мо)\b.*/iu', $base, $matches) && mb_strlen($matches[0]) >= 10) {
            $variants[] = trim($matches[0]);
        }

        $words = explode(' ', $base);

        for ($drop = 1; $drop <= 2 && count($words) - $drop >= 3; $drop++) {
            $variants[] = implode(' ', array_slice($words, 0, count($words) - $drop));
        }

        return array_values(array_unique(array_filter($variants, fn (string $variant): bool => $variant !== '')));
    }

    /**
     * Похоже ли содержимое колонки на адрес, а не на пометку логисту.
     *
     * В колонку «Адрес» пишут и «склад-склад Отправка уже оформлена, накладные на
     * сдэк есть», и просто «Минск». В кабинете клиента такие записи бесполезны:
     * доставку по ним не оформить, а список адресов они засоряют.
     *
     * @param  bool  $hasCarrier  отгрузка на терминал перевозчика — там достаточно города
     */
    public static function looksLikeAddress(string $address, bool $hasCarrier = false): bool
    {
        if (preg_match(self::NOT_AN_ADDRESS, $address)) {
            return false;
        }

        $tidy = self::tidy($address);

        if (mb_strlen($tidy) < 5) {
            return false;
        }

        // Номер дома — единственный надёжный признак адреса. Терминалу его хватает
        // и без номера: «терминал в г.Самара» — это понятная точка отгрузки.
        return preg_match('/\d/u', $tidy) === 1 || $hasCarrier;
    }

    /**
     * Детали внутри здания одной строкой: «офис 7, каб 6».
     *
     * DaData отдаёт дом, но теряет кабинет, а курьеру ехать именно в кабинет —
     * поэтому детали приписываются к разобранному адресу обратно.
     */
    public static function details(string $address): string
    {
        if (! preg_match_all(self::DETAILS, self::tidy($address), $matches, PREG_SET_ORDER)) {
            return '';
        }

        $parts = [];

        foreach ($matches as $match) {
            $label = mb_strtolower($match[1]);
            $value = trim($match[2]);

            if ($value === '' || ! preg_match('/\d/u', $value)) {
                continue;
            }

            $parts[$label.' '.$value] = true;
        }

        return implode(', ', array_keys($parts));
    }

    /**
     * Ключ, по которому два написания считаются одним адресом.
     *
     * Сокращения дома, улицы и корпуса раскрываются в ничто: «ул. Иркутская, д.
     * 11, к. 1» и «Иркутская 11 корп 1» — один адрес, и второй раз заводить его
     * в кабинет незачем.
     */
    public static function key(string $address): string
    {
        $text = mb_strtolower(self::tidy($address));
        $text = str_replace('ё', 'е', $text);
        $text = (string) preg_replace('/\b(д|дом|ул|улица|к|корп|корпус|стр|строение|влд|владение|г|город|обл|область|пр-кт|проспект|пр-д|проезд|пер|переулок|ш|шоссе|наб|набережная|мкр|микрорайон|пос|поселок|с|село|дер|деревня|р-н|район|тер|территория)\b\.?/u', ' ', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
