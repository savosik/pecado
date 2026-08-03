<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Состояние письма в журнале.
 *
 * 'failed' существует отдельно от 'queued' по принципиальной причине: письмо не должно
 * молча исчезать. Менеджер, который считает, что отправил коммерческое предложение,
 * а оно не ушло, — это потерянная сделка, а не техническая мелочь.
 */
enum EmailStatus: string
{
    use HasLabeledOptions;

    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::QUEUED => 'В очереди',
            self::SENT => 'Отправлено',
            self::FAILED => 'Ошибка',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::QUEUED => 'blue',
            self::SENT => 'green',
            self::FAILED => 'red',
        };
    }

    /**
     * Можно ли ещё править: отправленное письмо неизменяемо, это журнал.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::FAILED;
    }

    /**
     * Варианты для фронта вместе с цветом бейджа.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function optionsWithColor(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }
}
