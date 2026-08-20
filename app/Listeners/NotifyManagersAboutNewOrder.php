<?php

namespace App\Listeners;

use App\Enums\OrderType;
use App\Events\OrdersPlaced;
use App\Models\Order;
use App\Notifications\Orders\NewOrderForManagerNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationPulse;
use App\Services\Notifications\Pulse\PulseMode;
use App\Support\Notifications\OrderManagerRouting;
use Illuminate\Support\Facades\Notification;

/**
 * Уведомление менеджерам о новом оформлении.
 *
 * Слушает покупку, а не документ: расщепление корзины по типам даёт до пяти
 * заказов, и пять писем об одной покупке — шум, в котором теряется само событие.
 * Письмо уходит по основному документу; остальные менеджер видит в 1С и в CRM,
 * они связаны одной корзиной.
 */
class NotifyManagersAboutNewOrder
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const PULSE_EVENT = 'orders.created';

    public function handle(OrdersPlaced $event): void
    {
        $primary = $this->primaryOrder($event->orders);

        if (! $primary) {
            return;
        }

        // Сигнал пульту идёт всегда: в теневом режиме он только считает
        // получателей для сверки со старой маршрутизацией.
        $this->signalPulse($primary, $event->orders);

        // Событие переведено на пульт — здесь молчим. Один флаг на обе стороны,
        // поэтому двойного письма быть не может.
        if (PulseMode::handles(self::PULSE_EVENT)) {
            return;
        }

        if (! config('notifications.mail.features.manager_new_order')) {
            return;
        }

        $recipients = OrderManagerRouting::recipients($primary);

        if (empty($recipients)) {
            return;
        }

        // Каждому — своё письмо: список в одном `to` показал бы получателям
        // адреса друг друга, а резервных адресов может быть несколько.
        foreach ($recipients as $recipient) {
            Notification::route('mail', $recipient)
                ->notify(new NewOrderForManagerNotification($primary));
        }
    }

    /**
     * Основной документ оформления.
     *
     * Обычный заказ, иначе предзаказ, иначе первый попавшийся: промо и уценка
     * без основного заказа существовать могут, но встречаются редко, и письмо
     * лучше отправить по ним, чем не отправить вовсе.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    /**
     * Сообщить пульту об оформлении покупки.
     *
     * Сигнал один на покупку, а не на документ — по той же причине, по которой
     * листенер слушает OrdersPlaced: пять писем об одной покупке это шум.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function signalPulse(Order $primary, $orders): void
    {
        $number = $primary->erp_number ?: $primary->number;

        app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: self::PULSE_EVENT,
            clientUserId: $primary->user_id,
            companyId: $primary->company_id,
            subject: $primary,
            data: [
                'order_number' => $number,
                'order_type' => $primary->type?->value,
                'orders_count' => $orders->count(),
                'total' => (float) $orders->sum('total'),
                'items_count' => (int) $primary->items->count(),
                'channel' => $primary->fromErp ? 'erp' : 'site',
                'has_preorder' => $orders->contains(fn (Order $o) => $o->type === OrderType::PREORDER),
                'is_first_order' => Order::query()->where('user_id', $primary->user_id)->count() <= $orders->count(),
            ],
            view: [
                'title' => sprintf('Заказ %s принят', $number),
                'body' => sprintf('Оформлен заказ %s на сумму %s ₽.', $number, number_format((float) $orders->sum('total'), 2, ',', ' ')),
                'url' => url(route('cabinet.orders.show', $primary, false)),
                'entity_label' => "Заказ {$number}",
            ],
        ));
    }

    private function primaryOrder($orders): ?Order
    {
        foreach ([OrderType::ORDER, OrderType::PREORDER] as $type) {
            if ($order = $orders->first(fn (Order $o) => $o->type === $type)) {
                return $order;
            }
        }

        return $orders->first();
    }
}
