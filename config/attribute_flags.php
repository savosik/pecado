<?php

/*
 * Флаги видимости атрибутов. Источник правды для признаков:
 *  - is_variant_forming  — вариантообразующий (участвует в сборке вариантов товара)
 *  - show_on_site        — показывать в карточке товара на сайте
 *  - is_filterable       — показывать в фильтре каталога
 *  - show_in_export      — включать в выгрузки (маркетплейсы и т.п.)
 *
 * Используется:
 *  - app/Services/Product/AttributeFlagsAssigner.php   — общая логика применения
 *  - app/Console/Commands/AttributesAssignFlags.php    — ручной запуск (`php artisan attributes:assign-flags`)
 *
 * Атрибуты определяются по slug. Отсутствующие в списках получают значения defaults.
 * Значения перетирают текущие в БД, поэтому конфиг должен быть полным источником правды.
 *
 * Специальные правила (по договорённости):
 *  - klassifikaciia-dlia-otcetnosti — не видна нигде (все 4 флага false);
 *  - importer — виден только в выгрузке (остальные три флага false).
 */

return [
    // Значения по умолчанию для атрибутов, не перечисленных ниже
    'defaults' => [
        'is_variant_forming' => false,
        'show_on_site' => true,
        'is_filterable' => false,
        'show_in_export' => true,
    ],

    // Вариантообразующие (по ним собираются варианты одного товара)
    'variant_forming' => [
        'osnovnoi-cvet',
        'razmer',
        'obieem-ml',
        'aromat',
        'vkus',
    ],

    // Показывать в фильтре каталога (select + ключевые boolean-функции)
    'filterable' => [
        // Select — популярные
        'osnovnoi-cvet',
        'razmer',
        'pol',
        'naznacenie',
        'osnovnoi-material',
        'aromat',
        'vkus',
        'forma',
        'effekt',
        'tip-parfiumerii',
        'vid-tkani',
        'strana-proisxozdeniia',
        'vid-trusov',
        'anatomiia',
        'tip-upravleniia',
        'poverxnost',
        'interfeis',
        'dekor',
        'uroven-vlagozashhity',
        'tip-upakovki',

        // Boolean — популярные фичи
        's-vibraciei',
        's-nagrevom',
        's-rotaciei',
        's-dozatorom',
        's-nasadkami',
        'upravlenie-s-prilozeniia',
        'na-radioupravlenii',
        'na-prisoske',
        'na-kreplenii',
        'stimuliaciia-klitora',
        'stimuliaciia-prostaty',
        'stimuliaciia-tocki-g',
        'so-stimuliaciei-anusa',
        's-analnoi-stimuliaciei',
        'dlia-par',
        'fantaziinyi',
        'svetiashhiisia',
        'naduvnoi',
        'c-feromonami',
        'dvustoronnii',
        'gibkii',
    ],

    // НЕ показывать в карточке товара (остальные видим по defaults)
    'hidden_in_card' => [
        // Служебные / логистика / маркировка
        'klassifikaciia-dlia-otcetnosti',
        'importer',
        'principal',
        'adres-principala',
        'adres-proizovoditelia',
        'fabrika',
        'markirovannyi-tovar',
        'pecatat-kod-markirovki-v-dvux-ekzempliarax',
        'dopolnitelnaia-informaciia-dlia-etiketki',
        'rekomendacii-po-upakovke',
        'pricina-likvidacii',
        'nomer-tex-reglamenta-standarta-na-produkciiu',
        'bolsoi-razmer',
        'podxodit-dlia-marketpleisov',
        'korrekciia-razmerov',
        'data-proizvodstva',
    ],

    // НЕ включать в выгрузку (остальные выгружаются по defaults)
    'hidden_in_export' => [
        'klassifikaciia-dlia-otcetnosti',
        'pricina-likvidacii',
        'korrekciia-razmerov',
        'bolsoi-razmer',
        'podxodit-dlia-marketpleisov',
    ],
];
