<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Система налогообложения юрлица партнёра — в разрезе, важном для продаж.
 *
 * Варианты разложены не по кодексу, а по вопросу «будет ли покупатель принимать
 * входящий НДС к вычету». Кто считает НДС с вычетами (ОСНО или УСН по общей
 * ставке), тому нужен товар с НДС, и он уйдёт к поставщику, у которого такой
 * товар есть. Плательщику пониженных 5 и 7 % вычеты недоступны, освобождённому
 * НДС поставщика безразличен. Отсюда группы {@see group()}.
 */
enum TaxRegime: string
{
    use HasLabeledOptions;

    case OSNO = 'osno';
    case USN_VAT_22 = 'usn_vat_22';
    case USN_VAT_7 = 'usn_vat_7';
    case USN_VAT_5 = 'usn_vat_5';
    case USN_EXEMPT = 'usn_exempt';
    case PATENT = 'patent';
    case AUSN = 'ausn';
    case FOREIGN = 'foreign';
    /** Только для плана: текущий режим у юрлица есть всегда, его надо выяснить. */
    case UNDECIDED = 'undecided';

    /** Платит НДС и принимает входящий к вычету — нужен товар с НДС. */
    public const GROUP_DEDUCTIBLE = 'deductible';

    /** Платит НДС по пониженной ставке, вычетов нет. */
    public const GROUP_REDUCED = 'reduced';

    /** НДС не платит. */
    public const GROUP_EXEMPT = 'exempt';

    /** Не плательщик российского НДС: покупает на экспорт. */
    public const GROUP_FOREIGN = 'foreign';

    public const GROUPS = [
        self::GROUP_DEDUCTIBLE,
        self::GROUP_REDUCED,
        self::GROUP_EXEMPT,
        self::GROUP_FOREIGN,
    ];

    public function label(): string
    {
        return match ($this) {
            self::OSNO => 'ОСНО — НДС 22 % с вычетами',
            self::USN_VAT_22 => 'УСН с НДС 22 % с вычетами',
            self::USN_VAT_7 => 'УСН с НДС 7 % без вычетов',
            self::USN_VAT_5 => 'УСН с НДС 5 % без вычетов',
            self::USN_EXEMPT => 'УСН без НДС (освобождение)',
            self::PATENT => 'Патент (ПСН) без НДС',
            self::AUSN => 'АУСН без НДС',
            self::FOREIGN => 'Нерезидент РФ (Казахстан, Беларусь и др.)',
            self::UNDECIDED => 'Партнёр ещё не решил',
        };
    }

    /**
     * Короткая подпись для бейджей и таблиц.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OSNO => 'ОСНО',
            self::USN_VAT_22 => 'УСН + НДС 22 %',
            self::USN_VAT_7 => 'УСН + НДС 7 %',
            self::USN_VAT_5 => 'УСН + НДС 5 %',
            self::USN_EXEMPT => 'УСН без НДС',
            self::PATENT => 'Патент',
            self::AUSN => 'АУСН',
            self::FOREIGN => 'Нерезидент',
            self::UNDECIDED => 'Не решил',
        };
    }

    public function group(): ?string
    {
        return match ($this) {
            self::OSNO, self::USN_VAT_22 => self::GROUP_DEDUCTIBLE,
            self::USN_VAT_5, self::USN_VAT_7 => self::GROUP_REDUCED,
            self::USN_EXEMPT, self::PATENT, self::AUSN => self::GROUP_EXEMPT,
            self::FOREIGN => self::GROUP_FOREIGN,
            self::UNDECIDED => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $regime): bool => $regime->group() === $group,
        ));
    }

    /**
     * Подпись для клиента в опросе на сайте: без «вычетов» и прочего языка отдела.
     */
    public function clientLabel(): string
    {
        return match ($this) {
            self::OSNO => 'ОСНО (общая система), НДС 22 %',
            self::USN_VAT_22 => 'УСН, НДС 22 %',
            self::USN_VAT_7 => 'УСН, НДС 7 %',
            self::USN_VAT_5 => 'УСН, НДС 5 %',
            self::USN_EXEMPT => 'УСН без НДС',
            self::PATENT => 'Патент',
            self::AUSN => 'АУСН',
            self::FOREIGN => 'Компания не из России',
            self::UNDECIDED => 'Ещё не решили',
        };
    }

    /**
     * Варианты для опроса клиента — от частого к редкому: у нас в основном ИП на УСН.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function clientOptions(bool $withUndecided): array
    {
        $order = [
            self::USN_EXEMPT, self::USN_VAT_5, self::USN_VAT_7, self::USN_VAT_22,
            self::OSNO, self::PATENT, self::AUSN, self::FOREIGN,
        ];

        if ($withUndecided) {
            $order[] = self::UNDECIDED;
        }

        return array_map(
            static fn (self $regime): array => ['value' => $regime->value, 'label' => $regime->clientLabel()],
            $order,
        );
    }

    /**
     * Варианты для «сейчас» — без «ещё не решил».
     *
     * @return list<array{value: string, label: string}>
     */
    public static function currentOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            static fn (array $option): bool => $option['value'] !== self::UNDECIDED->value,
        ));
    }
}
