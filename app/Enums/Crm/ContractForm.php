<?php

namespace App\Enums\Crm;

/**
 * В каком виде существует подписанный экземпляр: колонка «скан / оригинал / ЭДО».
 *
 * Не путать со статусом: «отправлен по ЭДО» и «подписан по ЭДО» — разные
 * статусы одной формы. Форма отвечает на вопрос «где искать бумагу»,
 * статус — «дошли ли до подписи».
 */
enum ContractForm: string
{
    case EDO = 'edo';
    case SCAN = 'scan';
    case ORIGINAL = 'original';

    public function label(): string
    {
        return match ($this) {
            self::EDO => 'ЭДО',
            self::SCAN => 'Скан',
            self::ORIGINAL => 'Оригинал',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EDO => 'cyan',
            self::SCAN => 'yellow',
            self::ORIGINAL => 'green',
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
