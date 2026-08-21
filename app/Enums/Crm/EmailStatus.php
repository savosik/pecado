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

    /**
     * Собрано системой, но ни одно правило его не поймало.
     *
     * Отдельно от черновика намеренно: иначе рабочая папка менеджера
     * забилась бы поводами, которые никого не интересуют.
     */
    case UNMATCHED = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::QUEUED => 'В очереди',
            self::SENT => 'Отправлено',
            self::FAILED => 'Ошибка',
            self::UNMATCHED => 'Мимо фильтров',
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
            self::UNMATCHED => 'orange',
        };
    }

    /**
     * Можно ли ещё править: отправленное письмо неизменяемо, это журнал.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::FAILED || $this === self::UNMATCHED;
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
