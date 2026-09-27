<?php

namespace App\Services\Order;

use App\Enums\DeliveryMethod;
use App\Enums\ShipTogetherConflictReason;
use App\Enums\ShipTogetherStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\OrderReservePublisher;
use App\Support\Order\StatusCommentContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Совместная отгрузка резервов (протокол v16.11.0, топик №7 Agent Hub).
 *
 * Клиент отмечает несколько резервов и отправляет их в отгрузку вместе. Объединяются
 * не заказы, а отгрузка: заказы остаются отдельными документами, 1С по манифесту
 * группы оформляет минимальный комплект реализаций и расходных ордеров.
 *
 * Сайт проверяет то, что знает о заказе (партнёр, контрагент, валюта, склад, способ
 * и адрес доставки, окно резерва); соглашение, договор, организацию, запреты объединения
 * и остатки проверяет 1С на предконтроле и отвечает одним итогом на всю группу.
 * До итога резерв держится локально, правки и отмена закрыты.
 */
class ShipTogetherService
{
    public function __construct(
        private readonly OrderReservePublisher $publisher,
        private readonly OrderWarehouseResolver $warehouses,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('order_reserve.enabled') && (bool) config('order_reserve.ship_together.enabled');
    }

    /**
     * Доступна ли совместная отгрузка партнёру: глобальный рубильник плюс канарейка
     * испытаний (список erp_id партнёров; пустой — доступно всем участникам резервов).
     */
    public static function enabledFor(?User $user): bool
    {
        if (! self::enabled() || $user === null) {
            return false;
        }

        $canary = array_values(array_filter(array_map(
            static fn (string $uuid): string => trim($uuid),
            explode(',', (string) config('order_reserve.ship_together.canary', '')),
        )));

        return $canary === [] || in_array((string) $user->erp_id, $canary, true);
    }

    /**
     * Отправить группу резервов в отгрузку вместе.
     *
     * Заказы блокируются и помечаются `pending` в одной транзакции, сообщения в шину
     * уходят после её фиксации — все разом, с одинаковым ключом и манифестом.
     *
     * $skipUuids — только для испытаний (команда reserve:ship-together-trial): сообщения
     * по этим заказам в шину НЕ уходят, чтобы воспроизвести неполную группу (Р-7.3). Payload
     * для них всё равно собирается и возвращается — досылка тем же message_id.
     *
     * @param  list<int>  $orderIds
     * @param  list<string>  $skipUuids
     * @return array{key: string, orders: Collection<int, Order>, payloads: list<array{payload: array<string, mixed>, sent: bool}>}
     *
     * @throws ReserveActionException
     */
    public function confirmGroup(User $user, array $orderIds, array $skipUuids = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if (count($ids) < 2) {
            throw new ReserveActionException(
                'group_too_small',
                'Для совместной отгрузки отметьте хотя бы два заказа. Один заказ отправляйте обычной кнопкой «В отгрузку».',
            );
        }

        $key = (string) Str::uuid();

        $orders = DB::transaction(function () use ($user, $ids, $key): Collection {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($ids)) {
                throw new ReserveActionException(
                    'not_found',
                    'Часть выбранных заказов не найдена среди ваших. Обновите страницу и повторите.',
                    404,
                );
            }

            $this->assertGroupCompatible($orders);

            StatusCommentContext::with('Отправлен в отгрузку вместе с другими заказами', function () use ($orders, $key): void {
                foreach ($orders as $order) {
                    $order->ship_together_key = $key;
                    $order->ship_together_status = ShipTogetherStatus::PENDING;
                    $order->ship_together_conflict = null;
                    $order->ship_together_sent_at = now();
                    $order->save();
                }
            });

            return $orders;
        });

        $manifest = $orders->pluck('uuid')->values()->all();
        $confirmedAt = now();
        $payloads = [];

        foreach ($orders as $order) {
            $sent = ! in_array((string) $order->uuid, $skipUuids, true);
            $payloads[] = [
                'payload' => $this->publisher->publishConfirmedInGroup($order, $key, $manifest, $confirmedAt, dispatch: $sent),
                'sent' => $sent,
            ];
        }

        return ['key' => $key, 'orders' => $orders, 'payloads' => $payloads];
    }

    /**
     * Проверки сайта до публикации — ровно те, что зафиксированы в правилах v16.11.0.
     * Один партнёр гарантирован выборкой по user_id.
     *
     * @param  Collection<int, Order>  $orders
     *
     * @throws ReserveActionException
     */
    private function assertGroupCompatible(Collection $orders): void
    {
        foreach ($orders as $order) {
            $number = $order->clientLabel();

            if (! $order->reserve || $order->trashed()) {
                throw new ReserveActionException(
                    'not_reserved',
                    "Заказ {$number} уже не в резерве — отправить его вместе с другими нельзя. Обновите данные.",
                );
            }

            if ($order->shipTogetherPending()) {
                throw new ReserveActionException(
                    'ship_together_pending',
                    "Заказ {$number} уже отправлен в отгрузку вместе с другими и ждёт подтверждения склада.",
                    409,
                );
            }
        }

        $this->assertSame($orders, 'company_id', 'group_mixed_company',
            'Выбранные заказы оформлены на разные юрлица — отгрузить их одним документом нельзя. Отметьте заказы одного юрлица.');
        $this->assertSame($orders, 'currency_code', 'group_mixed_currency',
            'Выбранные заказы в разных валютах — отгрузить их одним документом нельзя.');
        $this->assertSame($orders, fn (Order $o) => $o->delivery_method instanceof DeliveryMethod ? $o->delivery_method->value : (string) $o->delivery_method,
            'group_mixed_delivery',
            'Среди выбранных заказов есть и самовывоз, и доставка — отправляйте их раздельно.');
        $this->assertSame($orders, fn (Order $o) => $o->delivery_method === DeliveryMethod::PICKUP ? '' : trim(mb_strtolower((string) $o->delivery_address)),
            'group_mixed_address',
            'У выбранных заказов разные адреса доставки — отправляйте их раздельно.');

        $warehouses = $orders->map(fn (Order $o) => $this->warehouses->resolve($o));

        if ($warehouses->contains(fn (array $w) => count($w) !== 1)) {
            throw new ReserveActionException(
                'group_multi_warehouse',
                'Заказ комплектуется с нескольких складов — один расходный ордер по нему невозможен, отправьте его отдельно.',
            );
        }

        if ($warehouses->map(fn (array $w) => $w[0])->unique()->count() !== 1) {
            throw new ReserveActionException(
                'group_mixed_warehouse',
                'Выбранные заказы отгружаются с разных складов — отправляйте их раздельно.',
            );
        }
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  string|callable(Order): mixed  $attribute
     *
     * @throws ReserveActionException
     */
    private function assertSame(Collection $orders, string|callable $attribute, string $code, string $message): void
    {
        $values = is_string($attribute)
            ? $orders->map(fn (Order $o) => (string) $o->{$attribute})
            : $orders->map($attribute);

        if ($values->unique()->count() > 1) {
            throw new ReserveActionException($code, $message);
        }
    }

    /**
     * Терминальный итог группы из order.updated 1С (v16.11.0).
     *
     * Вызывается обработчиком после того, как `reserve` уже применён: на успех
     * 1С шлёт reserve=false, на отказ — reserve=true. Здесь только состояние группы
     * и исход резерва; письмо об отказе — одно на группу.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyOutcome(Order $order, array $payload): void
    {
        $status = ShipTogetherStatus::tryFrom((string) ($payload['ship_together_status'] ?? ''));

        if ($status === null || $status === ShipTogetherStatus::PENDING) {
            return;
        }

        if (! empty($payload['ship_together_key'])) {
            $order->ship_together_key = (string) $payload['ship_together_key'];
        }

        $order->ship_together_status = $status;

        if ($status === ShipTogetherStatus::CONFIRMED) {
            $order->ship_together_conflict = null;
            $order->reserve = false;
            $order->reserved_until = null;
            $order->reserve_outcome = 'confirmed';

            return;
        }

        $conflict = is_array($payload['ship_together_conflict'] ?? null) ? $payload['ship_together_conflict'] : [];
        $order->ship_together_conflict = [
            'reason' => (string) ($conflict['reason'] ?? ShipTogetherConflictReason::PROCESSING_ERROR->value),
            'message' => isset($conflict['message']) ? (string) $conflict['message'] : null,
            'order_uuid' => isset($conflict['order_uuid']) ? (string) $conflict['order_uuid'] : null,
        ];
        // Отказ по всей группе: 1С резерв не трогала, заказ снова в окне резерва.
        // Поле reserve уже применено из этого же сообщения и авторитетно: если заказ
        // сняли с резерва руками до итога (причина not_reserved), он в резерв не вернётся.
        if ($order->reserve) {
            $order->reserve_outcome = null;
        }
    }

    /**
     * Локальный отказ группы, когда итог из 1С так и не пришёл (страховка сайта).
     */
    public function markNoResponse(Order $order): void
    {
        $order->ship_together_status = ShipTogetherStatus::CONFLICT;
        $order->ship_together_conflict = [
            'reason' => ShipTogetherConflictReason::NO_RESPONSE->value,
            'message' => 'Сайт не получил ответ склада по группе за отведённое время. Заказ остался в резерве — попробуйте отправить снова.',
            'order_uuid' => null,
        ];
    }

    /**
     * Представление состояния группы для кабинета и API.
     *
     * @return array{key: string|null, status: string|null, status_label: string|null, conflict: array{reason: string, message: string|null, label: string}|null}|null
     */
    public static function present(Order $order): ?array
    {
        $status = $order->ship_together_status;

        if ($status === null) {
            return null;
        }

        $conflict = $order->ship_together_conflict;

        return [
            'key' => $order->ship_together_key,
            'status' => $status->value,
            'status_label' => $status->label(),
            'conflict' => is_array($conflict) ? [
                'reason' => (string) ($conflict['reason'] ?? ''),
                'message' => $conflict['message'] ?? null,
                'label' => ShipTogetherConflictReason::labelFor($conflict['reason'] ?? null),
            ] : null,
        ];
    }
}
