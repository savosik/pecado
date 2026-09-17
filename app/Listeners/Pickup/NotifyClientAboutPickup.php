<?php

namespace App\Listeners\Pickup;

use App\Enums\DeliveryMethod;
use App\Enums\OrderFulfilmentStage as Stage;
use App\Events\Pickup\GoodsIssueHandedOver;
use App\Events\Pickup\GoodsIssueReadyChanged;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\Pickup\PickupHandover;
use App\Services\Crm\Mail\MailStream;
use App\Services\Pickup\OrderFulfilmentResolver;
use App\Services\Warehouse\WarehouseSchedule;
use App\Support\Notifications\Occasion;
use Carbon\CarbonInterface;

/**
 * Письма клиенту о самовывозе (pick-12): «собран, ждёт выдачи» и «выдан курьеру».
 *
 * Домен сообщает «случилось X», адресатов определяет матрица уведомлений. Письмо — про заказ
 * целиком: пока собрана только часть ордеров заказа, клиента не дёргаем — курьера слать рано.
 */
class NotifyClientAboutPickup
{
    public function __construct(
        private readonly MailStream $mailStream,
        private readonly OrderFulfilmentResolver $resolver,
        private readonly WarehouseSchedule $schedule,
    ) {}

    public function ready(GoodsIssueReadyChanged $event): void
    {
        if (! $event->ready || ! config('pickup.enabled')) {
            return;
        }

        $moment = $event->goodsIssue->status_changed_at ?? now();

        foreach ($this->pickupOrdersAt($event->goodsIssue, Stage::READY) as [$order, $view]) {
            $number = $order->erp_number ?: $order->number;
            $deadline = $this->schedule->closesAt(now());
            $until = $deadline && $deadline->isFuture()
                ? 'сегодня до '.$deadline->format('H:i')
                : 'с '.($this->schedule->nextOpening(now())?->format('d.m H:i') ?? 'открытия склада');

            $this->mailStream->captureQuietly(new Occasion(
                key: 'orders.ready_for_pickup',
                clientUserId: $order->user_id,
                companyId: $order->company_id,
                subject: $order,
                data: [
                    'order_number' => $number,
                    'packages' => $view['packages_total'],
                    // Собран → откатился в сборку → собран снова: это новый повод, а не повтор.
                    'origin_suffix' => $this->suffix($moment),
                ],
                view: [
                    'title' => "Заказ {$number} собран и ждёт выдачи",
                    'body' => sprintf(
                        'Заказ %s собран, мест: %d. Выдаём %s по адресу: %s. Выпустите в кабинете пропуск и перешлите его курьеру — на складе он покажет QR-код.',
                        $number,
                        $view['packages_total'],
                        $until,
                        config('warehouse.pickup_address'),
                    ),
                    'url' => url('/cabinet/pickup'),
                    'entity_label' => "Заказ {$number}",
                ],
            ));
        }
    }

    public function handedOver(GoodsIssueHandedOver $event): void
    {
        $handover = $event->handover;
        if (! config('pickup.enabled') || $handover->method === PickupHandover::METHOD_BACKFILL) {
            return;
        }

        $goodsIssue = $handover->goodsIssue;
        if ($goodsIssue === null) {
            return;
        }

        foreach ($this->pickupOrdersAt($goodsIssue, Stage::HANDED_OVER) as [$order]) {
            $number = $order->erp_number ?: $order->number;
            $when = $handover->issued_at->timezone($this->schedule->timezone())->format('d.m.Y в H:i');

            $this->mailStream->captureQuietly(new Occasion(
                key: 'orders.handed_over',
                clientUserId: $order->user_id,
                companyId: $order->company_id,
                subject: $order,
                data: ['order_number' => $number, 'origin_suffix' => $this->suffix($handover->issued_at)],
                view: [
                    'title' => "Заказ {$number} выдан на складе",
                    'body' => sprintf('Заказ %s выдан %s%s.', $number, $when, $handover->recipient_name ? ', получил: '.$handover->recipient_name : ''),
                    'url' => url(route('cabinet.orders.show', $order, false)),
                    'entity_label' => "Заказ {$number}",
                ],
            ));
        }
    }

    /**
     * Самовывозные заказы ордера, которые целиком дошли до нужной стадии.
     *
     * @return array<int, array{0: Order, 1: array<string, mixed>}>
     */
    private function pickupOrdersAt(GoodsIssue $goodsIssue, Stage $stage): array
    {
        $orders = $goodsIssue->relatedOrders()->filter(fn (Order $o) => $o->delivery_method === DeliveryMethod::PICKUP && $o->user_id !== null);
        $views = $this->resolver->forOrders($orders);

        return $orders
            ->filter(fn (Order $o) => ($views[$o->id]['stage'] ?? null) === $stage->value)
            ->map(fn (Order $o) => [$o, $views[$o->id]])
            ->values()
            ->all();
    }

    private function suffix(CarbonInterface $moment): string
    {
        return $moment->format('YmdHi');
    }
}
