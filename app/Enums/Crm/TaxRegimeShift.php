<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Что меняется у юрлица между текущим режимом и планом на следующий год.
 *
 * Правило живёт дважды: в PHP ({@see between()}) — для бейджа, в SQL
 * ({@see sqlExpression()}) — для отборов в списках. Совпадение на всех
 * сочетаниях режимов закреплено тестом `ContractorTaxRegimeTest`.
 */
enum TaxRegimeShift: string
{
    use HasLabeledOptions;

    /** Будет принимать НДС к вычету, а сейчас нет — главный риск ухода к НДС-поставщикам. */
    case TO_DEDUCTIBLE = 'to_deductible';

    /** Был освобождён, начнёт платить 5 или 7 % — цена для него вырастет. */
    case TO_REDUCED = 'to_reduced';

    case UNDECIDED = 'undecided';

    /** Режим меняется, но НДС для покупателя не растёт (например, уходит с ОСНО на УСН). */
    case OTHER = 'other';

    case SAME = 'same';

    public function label(): string
    {
        return match ($this) {
            self::TO_DEDUCTIBLE => 'Переходит на НДС с вычетами',
            self::TO_REDUCED => 'Начинает платить НДС 5–7 %',
            self::UNDECIDED => 'Ещё не решил',
            self::OTHER => 'Меняет режим, НДС не растёт',
            self::SAME => 'Без изменений',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TO_DEDUCTIBLE => 'red',
            self::TO_REDUCED => 'orange',
            self::UNDECIDED => 'yellow',
            self::OTHER => 'blue',
            self::SAME => 'gray',
        };
    }

    public function isRisk(): bool
    {
        return $this === self::TO_DEDUCTIBLE || $this === self::TO_REDUCED;
    }

    public static function between(?TaxRegime $current, ?TaxRegime $planned): ?self
    {
        if ($current === null || $planned === null) {
            return null;
        }

        if ($planned === TaxRegime::UNDECIDED) {
            return self::UNDECIDED;
        }

        $from = $current->group();
        $to = $planned->group();

        if ($to === TaxRegime::GROUP_DEDUCTIBLE && $from !== TaxRegime::GROUP_DEDUCTIBLE) {
            return self::TO_DEDUCTIBLE;
        }

        if ($to === TaxRegime::GROUP_REDUCED && $from === TaxRegime::GROUP_EXEMPT) {
            return self::TO_REDUCED;
        }

        return $from === $to ? self::SAME : self::OTHER;
    }

    /**
     * SQL-выражение с тем же результатом, что {@see between()}.
     *
     * Значения подставляются литералами, а не биндингами: это коды енума из
     * кода, пользовательского ввода в выражении нет, а порядок биндингов
     * внутри CASE легко перепутать при следующей правке.
     */
    public static function sqlExpression(string $current, string $planned): string
    {
        $in = static function (string $column, string $group): string {
            $values = array_map(
                static fn (TaxRegime $regime): string => "'".$regime->value."'",
                TaxRegime::inGroup($group),
            );

            return $column.' IN ('.implode(', ', $values).')';
        };

        $sameGroup = implode(' OR ', array_map(
            static fn (string $group): string => '('.$in($planned, $group).' AND '.$in($current, $group).')',
            TaxRegime::GROUPS,
        ));

        return 'CASE'
            ." WHEN {$current} IS NULL OR {$planned} IS NULL THEN NULL"
            ." WHEN {$planned} = '".TaxRegime::UNDECIDED->value."' THEN '".self::UNDECIDED->value."'"
            .' WHEN '.$in($planned, TaxRegime::GROUP_DEDUCTIBLE)
            .' AND NOT '.$in($current, TaxRegime::GROUP_DEDUCTIBLE)
            ." THEN '".self::TO_DEDUCTIBLE->value."'"
            .' WHEN '.$in($planned, TaxRegime::GROUP_REDUCED)
            .' AND '.$in($current, TaxRegime::GROUP_EXEMPT)
            ." THEN '".self::TO_REDUCED->value."'"
            ." WHEN {$sameGroup} THEN '".self::SAME->value."'"
            ." ELSE '".self::OTHER->value."' END";
    }
}
