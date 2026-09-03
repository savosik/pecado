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

        if (! $order->reserve || $order->trashed()) {
            return response()->json([
                'message' => 'Заказ уже не в резерве — изменить состав нельзя. Обновите страницу.',
            ], 422);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'items.required' => 'Состав пуст — чтобы отказаться от всего заказа, используйте отмену.',
            'items.min' => 'Состав пуст — чтобы отказаться от всего заказа, используйте отмену.',
        ]);

        $order->load('items');
        $current = $order->items->filter(fn ($item) => ! $item->cancelled)->keyBy('id');
        $target = collect($validated['items'])->keyBy('id');

        // Целевые строки обязаны существовать, количество — только уменьшаться
        foreach ($target as $id => $row) {
            $item = $current->get($id);

            if ($item === null) {
                return response()->json(['message' => 'Строка не найдена в заказе — обновите страницу.'], 422);
            }

            if ($row['quantity'] > $item->quantity) {
                return response()->json([
                    'message' => 'Увеличить количество в резерве нельзя — только уменьшить. Нужно больше — оформите отдельный заказ.',
                ], 422);
            }
        }

        $changed = $current->contains(fn ($item) => ! $target->has($item->id)
            || (int) $target[$item->id]['quantity'] !== (int) $item->quantity);

        if (! $changed) {
            return response()->json(['message' => 'Изменений нет.'], 422);
        }

        $oldSnapshot = $changeLogger->snapshotItems($order);
        $oldTotal = (float) $order->total_amount;

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $current, $target) {
            $total = 0.0;

            foreach ($current as $item) {
                if (! $target->has($item->id)) {
                    $item->delete();

                    continue;
                }

                $quantity = (int) $target[$item->id]['quantity'];
                $price = (float) ($item->final_price ?? $item->price);

                if ($quantity !== (int) $item->quantity) {
                    $item->update(['quantity' => $quantity, 'subtotal' => $quantity * $price]);
                }

                $total += $quantity * $price;
            }

            $order->total_amount = $total;
            $order->saveQuietly();
        });

        $order->refresh()->load('items.product');

        $changeLogger->logItemChanges($order, $oldSnapshot, $changeLogger->snapshotItems($order), $oldTotal, (float) $order->total_amount, 'client');

        // base_items_version — последний применённый items_version от 1С;
        // для заказа, ещё не получившего эха, — 0
        $publisher->publishUpdated($order, (int) ($order->items_version ?? 0));

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

        // Гонка: резерв мог быть снят по таймауту или менеджером
        if (! $order->reserve || $order->trashed()) {
            return response()->json([
                'message' => 'Заказ уже не в резерве — обновите страницу, чтобы увидеть актуальное состояние.',
            ], 422);
        }

        $publisher->publishConfirmed($order);

        $order->reserve = false;
        $order->save();

        return response()->json([
            'message' => 'Заказ отправлен в отгрузку — дальше он идёт по обычному конвейеру.',
        ]);
    }
}
