<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Order;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Erp\OrderReservePublisher;
use App\Services\Order\ClientOrderActions;
use App\Services\Order\OrderChangeLogger;
use App\Services\Order\ShipTogetherService;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Validation\ValidationException;

/**
 * Режим «Заказы в резерве» (v16.9.0): что сейчас удержано на складе.
 *
 * Чтение и самообслуживание: подтвердить отгрузку, уменьшить состав. Отмена
 * резервного заказа — `orders.cancel`. Записи уходят в шину 1С через
 * {@see ClientOrderActions} — те же коды отказов, что у кабинета и legacy.
 */
class ReserveOperations implements OperationProvider
{
    use ResolvesClientEntities;

    public function __construct(
        private readonly ClientOrderActions $actions,
        private readonly OrderReservePublisher $publisher,
        private readonly OrderChangeLogger $changeLogger,
    ) {}

    public static function section(): array
    {
        return ['reserves', 'Резервы'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'reserves.list',
                section: 'reserves',
                method: 'GET',
                uri: 'reserves',
                summary: 'Заказы в резерве: состав, суммы и срок удержания',
                description: 'Удержанные заказы клиента с фактическим сроком `reserved_until` (1С может урезать '
                    .'запрошенный срок до своего предела). Пока заказ в резерве, товар не уйдёт другому покупателю; '
                    .'без подтверждения до срока резерв снимется сам. `items_version` — версия состава: ключи строк '
                    .'стабильны только в её пределах, правка состава требует передать её как базовую. '
                    .'Отменённые в 1С строки в состав не входят.',
                params: [],
                handler: [self::class, 'list'],
                gate: FeatureGate::RESERVE,
            ),
            new Operation(
                id: 'reserves.confirm',
                section: 'reserves',
                method: 'POST',
                uri: 'reserves/{order}/confirm',
                summary: 'Подтвердить резерв — отправить заказ в отгрузку',
                description: 'В 1С уходит order.confirmed; признак резерва снимается сразу, статусы приедут эхом. '
                    .'После срока удержания подтверждать нечего (422 not_reserved).',
                params: [Param::string('order', 'Заказ: id, номер или uuid', required: true)],
                handler: [self::class, 'confirm'],
                mutating: true,
                gate: FeatureGate::RESERVE,
            ),
            new Operation(
                id: 'reserves.confirm_group',
                section: 'reserves',
                method: 'POST',
                uri: 'reserves/ship-together',
                summary: 'Отправить несколько резервов в отгрузку вместе — одной реализацией и одним расходным ордером',
                description: 'Совместная отгрузка (протокол v16.11.0): по каждому заказу в 1С уходит order.confirmed с ключом '
                    .'группы; склад оформляет по группе минимальный комплект документов (одна реализация и один ордер, если '
                    .'позволяют реквизиты). Заказы остаются отдельными документами. Условия: минимум два заказа, одно юрлицо, '
                    .'одна валюта, один склад, один способ и адрес доставки, все — в резерве. Резерв НЕ снимается сразу: '
                    .'заказы ждут итог склада (ship_together.status = pending, правки и отмена закрыты), итог виден в '
                    .'orders.get / reserves.list — confirmed либо conflict с причиной; после conflict заказы снова в резерве, '
                    .'повторите группу или подтвердите по одному. Отказы до отправки: group_too_small, group_mixed_company, '
                    .'group_mixed_currency, group_mixed_delivery, group_mixed_address, group_mixed_warehouse, '
                    .'group_multi_warehouse, not_reserved, ship_together_pending (409).',
                params: [
                    Param::list('orders', 'Заказы: id, номера или uuid (минимум два)', 'string', true, ['min:2']),
                ],
                handler: [self::class, 'confirmGroup'],
                mutating: true,
                gate: FeatureGate::RESERVE,
            ),
            new Operation(
                id: 'reserves.items',
                section: 'reserves',
                method: 'POST',
                uri: 'reserves/{order}/items',
                summary: 'Уменьшить состав резервного заказа',
                description: 'Передаётся ЦЕЛЕВОЙ состав по остающимся строкам (item_id из reserves.list); строк, которых '
                    .'нет в запросе, в заказе не останется. Только уменьшение: увеличение отклоняется (increase_forbidden), '
                    .'для большего объёма создайте отдельный заказ. Пустой состав не принимается — для отказа от всего '
                    .'заказа используйте orders.cancel. base_items_version обязателен: если состав успел измениться, '
                    .'ответ 409 stale_items_version — перечитайте reserves.list и повторите.',
                params: [
                    Param::string('order', 'Заказ: id, номер или uuid', required: true),
                    Param::integer('base_items_version', 'Версия состава (items_version из reserves.list), от которой правите', true, ['min:0']),
                    Param::list('items', 'Строки {item_id, quantity}', 'object', true, ['min:1']),
                ],
                handler: [self::class, 'items'],
                mutating: true,
                gate: FeatureGate::RESERVE,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $orders = Order::query()
            ->where('user_id', $actor->id)
            ->where('reserve', true)
            ->with(['items' => fn ($q) => $q->where('cancelled', false), 'items.product:id,sku,slug,name'])
            ->orderBy('reserved_until')
            ->orderBy('id')
            ->get();

        return Envelope::data($orders->map(fn (Order $order) => [
            'order_id' => $order->id,
            ...$order->clientNumberPayload(),
            'uuid' => $order->uuid,
            'total_amount' => (float) $order->total_amount,
            'currency_code' => $order->currency_code,
            'reserved_until' => $order->reserved_until?->toIso8601String(),
            'items_version' => (int) ($order->items_version ?? 0),
            // v16.11.0: состояние группы совместной отгрузки (null — группой не отправлялся)
            'ship_together' => ShipTogetherService::present($order),
            'created_at' => ($order->erp_created_at ?? $order->created_at)?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'item_id' => $item->id,
                'sku' => $item->product?->sku,
                'name' => $item->product?->name ?? $item->name,
                'quantity' => (float) $item->quantity,
                'price' => (float) ($item->final_price ?? $item->price),
                'subtotal' => (float) $item->subtotal,
            ])->values()->all(),
        ])->values()->all(), [
            'total' => $orders->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(User $actor, OperationInput $input): array
    {
        $order = $this->orderOf($actor, (string) $input->get('order'), withTrashed: true);

        $this->actions->confirmReserve($order, $this->publisher);

        return Envelope::data(['order_id' => $order->id, 'confirmed' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmGroup(User $actor, OperationInput $input): array
    {
        $ids = array_map(
            fn ($identifier) => $this->orderOf($actor, (string) $identifier, withTrashed: true)->id,
            $input->array('orders'),
        );

        $result = app(ShipTogetherService::class)->confirmGroup($actor, $ids);

        return Envelope::data([
            'ship_together_key' => $result['key'],
            'status' => 'pending',
            'order_ids' => $result['orders']->pluck('id')->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function items(User $actor, OperationInput $input): array
    {
        $order = $this->orderOf($actor, (string) $input->get('order'), withTrashed: true);
        $target = [];

        foreach ($input->array('items') as $i => $row) {
            $row = is_array($row) ? $row : [];

            if (! is_numeric($row['item_id'] ?? null) || ! is_numeric($row['quantity'] ?? null) || (int) $row['quantity'] < 1) {
                throw ValidationException::withMessages(["items.{$i}" => 'Строка должна содержать item_id и quantity ≥ 1.']);
            }

            $target[] = ['id' => (int) $row['item_id'], 'quantity' => (int) $row['quantity']];
        }

        $this->actions->updateReserveItems($order, $target, $this->publisher, $this->changeLogger, (int) $input->int('base_items_version'));
        $order->refresh();

        return Envelope::data([
            'order_id' => $order->id,
            'total_amount' => round((float) $order->total_amount, 2),
            'items_version' => (int) ($order->items_version ?? 0),
        ]);
    }
}
