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
        $deadline = $this->schedule->closesAt(now());
        $until = $deadline && $deadline->isFuture()
            ? 'сегодня до '.$deadline->format('H:i')
            : 'с '.($this->schedule->nextOpening(now())?->format('d.m H:i') ?? 'открытия склада');

        // Один расходный ордер может покрывать несколько заказов клиента (а с объединением отгрузок —
        // будет покрывать чаще): письмо одно на клиента и комплект, а не по письму на заказ.
        $byClient = collect($this->pickupOrdersAt($event->goodsIssue, Stage::READY))->groupBy(fn (array $pair) => $pair[0]->user_id);

        foreach ($byClient as $pairs) {
            /** @var Order $lead */
            $lead = $pairs->first()[0];
            $numbers = $pairs->map(fn (array $pair) => $pair[0]->erp_number ?: $pair[0]->number)->sort()->values();
            $packages = (int) $event->goodsIssue->packages_count;
            $label = $numbers->count() === 1 ? 'Заказ '.$numbers[0] : 'Заказы '.$numbers->join(', ');

            $this->mailStream->captureQuietly(new Occasion(
                key: 'orders.ready_for_pickup',
                clientUserId: $lead->user_id,
                companyId: $lead->company_id,
                subject: $lead,
                data: [
                    'order_number' => $numbers->join(', '),
                    'packages' => $packages,
                    // Собран → откатился в сборку → собран снова: это новый повод, а не повтор.
                    'origin_suffix' => $this->suffix($moment),
                ],
                view: [
                    'title' => $label.($numbers->count() === 1 ? ' собран и ждёт выдачи' : ' собраны и ждут выдачи'),
                    'body' => sprintf(
                        '%s: собрано, мест: %d. Выдаём %s по адресу: %s. Выпустите в кабинете пропуск и перешлите его курьеру — на складе он покажет QR-код, подписывать ничего не нужно.',
                        $label,
                        $packages,
                        $until,
                        config('warehouse.pickup_address'),
                    ),
                    'url' => url('/cabinet/pickup'),
                    'entity_label' => $label,
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
     * Недобор при сборке (решение заказчика 18.09.2026): менеджер замену не подбирает — клиенту уходит
     * письмо «товара не хватило, закажите что-то другое». В окне резерва строки уменьшает сам клиент,
     * это не недобор.
     */
    public function shortfall(\App\Events\Order\OrderItemsCancelled $event): void
    {
        $order = $event->order;
        if (! config('pickup.enabled') || $order->reserve || blank($order->user_id)) {
            return;
        }

        $number = $order->erp_number ?: $order->number;
        $lines = collect($event->items)->map(fn (array $item) => sprintf('%s — %s шт.', $item['name'], rtrim(rtrim(number_format($item['quantity'], 3, ',', ''), '0'), ',')));

        $this->mailStream->captureQuietly(new Occasion(
            key: 'orders.items_unavailable',
            clientUserId: (int) $order->user_id,
            companyId: $order->company_id,
            subject: $order,
            data: ['order_number' => $number, 'origin_suffix' => $this->suffix(now())],
            view: [
                'title' => "Заказ {$number}: части товара не хватило",
                'body' => 'При сборке не хватило: '.$lines->join('; ').'. Заказ будет собран без этих позиций, сумма пересчитана. '
                    .'Замену мы не подбираем — если товар нужен, закажите другой в каталоге отдельным заказом.',
                'url' => url(route('cabinet.orders.show', $order, false)),
                'entity_label' => "Заказ {$number}",
            ],
        ));
    }

    /**
     * Собранный заказ давно не забирают (решение заказчика 18.09.2026): храним сколько угодно,
     * но клиенту напоминаем. `$step` — ступень в днях, входит в ключ письма: одно письмо на ступень.
     */
    public function remindWaiting(GoodsIssue $goodsIssue, int $step): int
    {
        $sent = 0;
        $byClient = collect($this->pickupOrdersAt($goodsIssue, Stage::READY))->groupBy(fn (array $pair) => $pair[0]->user_id);

        foreach ($byClient as $pairs) {
            /** @var Order $lead */
            $lead = $pairs->first()[0];
            $numbers = $pairs->map(fn (array $pair) => $pair[0]->erp_number ?: $pair[0]->number)->sort()->values();
            $label = $numbers->count() === 1 ? 'Заказ '.$numbers[0] : 'Заказы '.$numbers->join(', ');
            $since = $goodsIssue->status_changed_at?->timezone($this->schedule->timezone())->format('d.m.Y');

            $this->mailStream->captureQuietly(new Occasion(
                key: 'orders.pickup_waiting',
                clientUserId: $lead->user_id,
                companyId: $lead->company_id,
                subject: $lead,
                data: ['order_number' => $numbers->join(', '), 'days' => $step, 'origin_suffix' => 'wait'.$step],
                view: [
                    'title' => $label.': ждёт на складе уже '.$step.' дн.',
                    'body' => sprintf(
                        '%s собран %s и до сих пор не забран. Мы храним его для вас, но место на стойке выдачи ограничено — пришлите, пожалуйста, курьера. Склад: %s, %s.',
                        $label,
                        $since,
                        config('warehouse.pickup_address'),
                        $this->schedule->weekText(),
                    ),
                    'url' => url('/cabinet/pickup'),
                    'entity_label' => $label,
                ],
            ));
            $sent++;
        }

        return $sent;
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
