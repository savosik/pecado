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

    /**
     * Собрано и записано без отправки — точка отсчёта.
     *
     * Финансовый обход помнит состояние клиента по своему последнему письму.
     * При включении обхода на живых данных «возникла просрочка» по долгам
     * месячной давности — не новость, и слать её нельзя; но и пропустить
     * нельзя, иначе рост и погашение этой просрочки не заметить никогда.
     * Такое письмо — память сканера, а не почта: чистка «без получателя»
     * его не трогает, менеджер при желании отправит руками.
     */
    case RECORDED = 'recorded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::QUEUED => 'В очереди',
            self::SENT => 'Отправлено',
            self::FAILED => 'Ошибка',
            // Слово «фильтры» ушло вместе с движком правил: адресата теперь
            // задаёт настройка партнёра, и если письмо здесь — значит адресат
            // указан, но раскрыть его не удалось.
            self::UNMATCHED => 'Без получателя',
            self::RECORDED => 'Зафиксировано',
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
            self::RECORDED => 'gray',
        };
    }

    /**
     * Можно ли ещё править: отправленное письмо неизменяемо, это журнал.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::FAILED || $this === self::UNMATCHED || $this === self::RECORDED;
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
