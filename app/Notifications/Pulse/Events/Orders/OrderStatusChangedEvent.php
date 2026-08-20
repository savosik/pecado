<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Смена статуса заказа.
 *
 * Самое частое событие домена. До пульта клиенту писали при переходе в статусы
 * из зашитого в конфиг whitelist; теперь этот список — условие системного
 * правила, и его можно менять в интерфейсе, а не релизом.
 */
class OrderStatusChangedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.status_changed';
    }

    public function label(): string
    {
        return 'Смена статуса заказа';
    }

    public function description(): string
    {
        return 'Заказ перешёл в новый статус — из 1С или после правки в админке';
    }

    public function fields(): array
    {
        return [
            'status' => new FieldSpec('status', 'Новый статус', FieldSpec::TYPE_ENUM, self::statusOptions()),
            'previous_status' => new FieldSpec('previous_status', 'Прежний статус', FieldSpec::TYPE_ENUM, self::statusOptions()),
            'order_type' => new FieldSpec('order_type', 'Тип заказа', FieldSpec::TYPE_ENUM, self::typeOptions()),
            'total' => new FieldSpec('total', 'Сумма заказа', FieldSpec::TYPE_MONEY),
            'from_erp' => new FieldSpec('from_erp', 'Изменение пришло из 1С', FieldSpec::TYPE_BOOL,
                hint: 'Отличает статус, присланный учётной системой, от правки руками в админке'),
        ];
    }

    protected function ownTags(array $data): array
    {
        $tags = [];

        if (filled($data['status'] ?? null)) {
            $tags[] = 'статус:'.$data['status'];
        }

        return $tags;
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.orders.status-changed';
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: {{status_label}}';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return array_map(
            static fn (OrderStatus $case) => ['value' => $case->value, 'label' => $case->label()],
            OrderStatus::cases(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return array_map(
            static fn (OrderType $case) => ['value' => $case->value, 'label' => $case->label()],
            OrderType::cases(),
        );
    }
}
