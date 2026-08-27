<?php

namespace App\Enums\Crm;

/**
 * Вариант оплаты по договору — колонка «Вариант оплаты» таблицы менеджеров.
 *
 * Это условие договора, а не порядок расчётов соглашения из 1С
 * ({@see \App\Models\Agreement::SETTLEMENT_PROCEDURE_LABELS}): соглашение
 * говорит, как 1С считает долг, а договор — о чём договорились на бумаге.
 * Смешивать их нельзя: у одного партнёра может быть договор с отсрочкой
 * и соглашение «по заказам».
 */
enum ContractPaymentTerms: string
{
    case PREPAYMENT = 'prepayment';
    case DEFERRAL = 'deferral';
    case CONSIGNMENT = 'consignment';

    public function label(): string
    {
        return match ($this) {
            self::PREPAYMENT => 'Предоплата',
            self::DEFERRAL => 'Отсрочка',
            self::CONSIGNMENT => 'Реализация',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PREPAYMENT => 'blue',
            self::DEFERRAL => 'purple',
            self::CONSIGNMENT => 'teal',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }
}
