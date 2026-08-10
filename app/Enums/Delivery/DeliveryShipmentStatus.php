<?php

namespace App\Enums\Delivery;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Внутренний жизненный цикл отправки.
 *
 * Отдельно от ApiShipStatus намеренно: до передачи заявки перевозчику никакого
 * статуса у него ещё нет, а складу уже нужно отличать черновик от посчитанного груза.
 * После передачи значения ведёт ApiShipStatus::toShipmentStatus().
 */
enum DeliveryShipmentStatus: string
{
    use HasLabeledOptions;

    case DRAFT = 'draft';
    case CALCULATED = 'calculated';
    case SUBMITTING = 'submitting';
    case SUBMITTED = 'submitted';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::CALCULATED => 'Тариф выбран',
            self::SUBMITTING => 'Передаётся в ТК',
            self::SUBMITTED => 'Заявка принята',
            self::IN_TRANSIT => 'В пути',
            self::DELIVERED => 'Доставлена',
            self::CANCELLED => 'Отменена',
            self::FAILED => 'Ошибка передачи',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CALCULATED => 'cyan',
            self::SUBMITTING => 'yellow',
            self::SUBMITTED => 'blue',
            self::IN_TRANSIT => 'teal',
            self::DELIVERED => 'green',
            self::CANCELLED => 'orange',
            self::FAILED => 'red',
        };
    }

    /**
     * Отправку ещё можно править и пересчитывать.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::CALCULATED, self::FAILED], true);
    }

    /**
     * Заявка живёт у перевозчика: статус приходит вебхуками и сверкой.
     */
    public function isActiveAtProvider(): bool
    {
        return in_array($this, [self::SUBMITTING, self::SUBMITTED, self::IN_TRANSIT], true);
    }

    /**
     * Отправка занимает реализации — включить их в другую отправку нельзя.
     *
     * Отменённая и провалившаяся отправки реализации освобождают: груз собирают заново.
     */
    public function holdsDocuments(): bool
    {
        return ! in_array($this, [self::CANCELLED, self::FAILED], true);
    }
}
