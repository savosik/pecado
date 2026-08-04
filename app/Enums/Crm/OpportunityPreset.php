<?php

namespace App\Enums\Crm;

/**
 * Пресет списка возможностей — какой вопрос менеджер задаёт базе.
 *
 * Пресет отбирает кандидатов, ранжирование — общее для всех: одна и та же
 * взвешенная оценка по сигналам (недобор плана, просроченный цикл, средний чек,
 * падение, класс ABC). Так «отстают от плана» и «спящие» сортируются по одной
 * логике, и менеджер не гадает, почему в двух списках один клиент выше другого.
 */
enum OpportunityPreset: string
{
    case PLAN_LAG = 'plan_lag';
    case SLEEPING = 'sleeping';
    case NOT_BUYING = 'not_buying';
    case DECLINING = 'declining';
    case NEVER_BOUGHT = 'never_bought';

    public function label(): string
    {
        return match ($this) {
            self::PLAN_LAG => 'Отстают от плана',
            self::SLEEPING => 'Спящие с высоким чеком',
            self::NOT_BUYING => 'Не берут бренд или категорию',
            self::DECLINING => 'Просели против прошлого месяца',
            self::NEVER_BOUGHT => 'Ни разу не покупали',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PLAN_LAG => 'Клиенты с планом на месяц, до которого не хватает выручки.',
            self::SLEEPING => 'Не отгружались дольше своего обычного цикла, при этом чек выше медианы по базе.',
            self::NOT_BUYING => 'Покупают у нас, но выбранный бренд или категорию не берут.',
            self::DECLINING => 'Отгрузили за месяц заметно меньше, чем за прошлый.',
            self::NEVER_BOUGHT => 'Закреплены за менеджером, но ни одной отгрузки за всё время.',
        };
    }

    /**
     * Нужен ли пресету выбор измерения (бренд/категория/товар).
     *
     * Только «не берут X» параметризуется: остальные вопросы задаются самой базе,
     * а не срезу каталога.
     */
    public function needsDimension(): bool
    {
        return $this === self::NOT_BUYING;
    }

    /**
     * @return list<array{value: string, label: string, description: string, needs_dimension: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'needs_dimension' => $case->needsDimension(),
            ],
            self::cases(),
        );
    }
}
