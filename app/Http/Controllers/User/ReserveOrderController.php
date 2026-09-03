<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Erp\OrderReservePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Раздел «Заказы в резерве» (v16.9.0, режим «Заказы в резерве», res-07).
 *
 * Рабочее место интернетчика: список удержанных заказов с таймерами и
 * подтверждение отгрузки. Отмена и правка — на странице заказа. Весь контур
 * за глобальным рубильником order_reserve.enabled.
 */
class ReserveOrderController extends Controller
{
    /**
     * Список заказов в резерве. GET /cabinet/reserves
     */
    public function index(Request $request): InertiaResponse
    {
        abort_unless((bool) config('order_reserve.enabled'), 404);

        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('reserve', true)
            ->with(['items:id,order_id,quantity'])
            ->orderBy('reserved_until')
            ->get();

        return Inertia::render('User/Cabinet/Reserves/Index', [
            'reserves' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->erp_number ?? $order->number ?? ('#'.$order->id),
                'total_amount' => (float) $order->total_amount,
                'currency_code' => $order->currency_code,
                'items_count' => $order->items->count(),
                'quantity' => (float) $order->items->sum('quantity'),
                'created_at_formatted' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                // ISO для живого таймера на клиенте; фактический срок из 1С
                'reserved_until' => $order->reserved_until?->toIso8601String(),
                'reserved_until_formatted' => $order->reserved_until?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ])->values(),
        ]);
    }

    /**
     * Правка состава резервного заказа клиентом (v16.9.0, res-08).
     * POST /cabinet/orders/{order}/reserve-items
     *
     * V1 — только уменьшение: снизить количество, удалить строку. Клиент
     * присылает ЦЕЛЕВОЙ состав ({id, quantity} по остающимся строкам);
     * отсутствующие строки удаляются. Пустой состав не принимается —
     * удаление последней строки на фронте превращается в отмену заказа.
     *
     * В 1С уходит order.updated с полным составом и base_items_version
     * (оптимистичная блокировка: отставшую правку 1С отклонит и вернёт
     * актуальный состав с conflict — сайт применит его эхом).
     */
    public function updateItems(
        Request $request,
        Order $order,
        OrderReservePublisher $publisher,
        \App\Services\Order\OrderChangeLogger $changeLogger,
    ): JsonResponse {
        abort_unless((bool) config('order_reserve.enabled'), 404);
        abort_unless($order->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => 'Состав пуст — чтобы отказаться от всего заказа, используйте отмену.',
            'items.min' => 'Состав пуст — чтобы отказаться от всего заказа, используйте отмену.',
        ]);

        try {
            app(\App\Services\Order\ClientOrderActions::class)
                ->updateReserveItems($order, $validated['items'], $publisher, $changeLogger);
        } catch (\App\Services\Order\ReserveActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Состав обновлён. Освободившийся товар вернулся в свободный остаток.',
        ]);
    }

    /**
     * Подтверждение резервного заказа — «отправить в отгрузку».
     * POST /cabinet/orders/{order}/confirm-reserve
     *
     * В 1С уходит order.confirmed (строки «Резервировать на складе» → «Отгрузить»,
     * статус «К отгрузке», обычный конвейер). Локально снимаем признак резерва
     * сразу — заказ уходит из раздела, статусные переходы приедут из 1С эхом.
     */
    public function confirm(
        Request $request,
        Order $order,
        OrderReservePublisher $publisher,
    ): JsonResponse {
        abort_unless((bool) config('order_reserve.enabled'), 404);
        abort_unless($order->user_id === $request->user()->id, 403);

        try {
            // Гонку (резерв снят таймаутом или менеджером) отбивает сервис
            app(\App\Services\Order\ClientOrderActions::class)->confirmReserve($order, $publisher);
        } catch (\App\Services\Order\ReserveActionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Заказ отправлен в отгрузку — дальше он идёт по обычному конвейеру.',
        ]);
    }
}
