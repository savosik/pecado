<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Erp\OrderReservePublisher;
use Illuminate\Support\Facades\DB;

/**
 * Действия клиента над заказом в режиме «Заказы в резерве» (v16.9.0).
 *
 * Единственная реализация отмены, подтверждения и правки состава: кабинет
 * (User\OrderController, User\ReserveOrderController) и клиентский API
 * (Api\ClientApiController) — тонкие транспортные слои над этим сервисом.
 * Отказы — ReserveActionException с машиночитаемым кодом и русским текстом.
 *
 * Проверки владельца и глобального рубильника остаются на транспорте:
 * у кабинета и API разная модель аутентификации.
 */
class ClientOrderActions
{
    /**
     * Отмена заказа клиентом: ранние статусы либо окно резерва.
     *
     * Публикация в 1С — до локального закрытия: если постановка джобы упала,
     * заказ остаётся активным и клиент повторит попытку. Локально заказ
     * закрывается сразу (closed + soft-delete), не дожидаясь эха 1С.
     *
     * @throws ReserveActionException
     */
    public function cancel(Order $order, OrderReservePublisher $publisher, string $historyComment = 'Отменён клиентом'): void
    {
        if (! $order->cancellableByClient()) {
            throw new ReserveActionException(
                'not_cancellable',
                'Заказ уже передан в сборку — отменить его нельзя. Свяжитесь с вашим менеджером.',
            );
        }

        $publisher->publishDeleted($order, OrderReservePublisher::REASON_CLIENT_CANCELLED);

        // Комментарий уходит в OrderStatusHistory (booted::updating) — менеджер
        // видит, что отмена клиентская (и через какой канал), а не 1С-овская
        request()->merge(['status_comment' => $historyComment]);

        $order->status = OrderStatus::CLOSED;
        if ($order->reserve) {
            $order->reserve = false;
            // Исход для метрик злоупотреблений (res-11)
            $order->reserve_outcome = 'cancelled';
        }
        $order->save();
        $order->deleteQuietly();
    }

    /**
     * Подтверждение резервного заказа — «отправить в отгрузку».
     *
     * В 1С уходит order.confirmed; локально признак снимается сразу,
     * статусные переходы приедут из 1С эхом.
     *
     * @throws ReserveActionException
     */
    public function confirmReserve(Order $order, OrderReservePublisher $publisher): void
    {
        $this->assertInReserveWindow($order, 'подтвердить');

        $publisher->publishConfirmed($order);

        $order->reserve = false;
        // Исход для метрик злоупотреблений (res-11)
        $order->reserve_outcome = 'confirmed';
        $order->save();
    }

    /**
     * Правка состава резервного заказа: целевой состав {id, quantity},
     * отсутствующие строки удаляются, v1 — только уменьшение.
     *
     * $baseItemsVersion — версия состава, которую видел клиент. Обязательна для
     * защиты от гонки: 1С перенумеровывает строки при физическом удалении, и
     * ключ строки стабилен только в пределах версии (v16.9.1, вопрос 1С в топике
     * №6). Без проверки клиент со старым представлением уменьшил бы не ту строку —
     * id у нас стабилен, а товар под ним после roundtrip мог смениться.
     * Проверка 1С остаётся вторым рубежом, но отбивать устаревшую правку до
     * отправки в шину честнее: клиент не увидит применённую и откатившуюся правку.
     *
     * @param  list<array{id: int, quantity: int}>  $targetItems
     *
     * @throws ReserveActionException
     */
    public function updateReserveItems(Order $order, array $targetItems, OrderReservePublisher $publisher, OrderChangeLogger $changeLogger, ?int $baseItemsVersion = null): void
    {
        $this->assertInReserveWindow($order, 'изменить');

        if ($baseItemsVersion !== null && $baseItemsVersion !== (int) ($order->items_version ?? 0)) {
            throw new ReserveActionException(
                'stale_items_version',
                'Состав заказа изменился с момента, когда вы его открыли. Обновите данные и повторите правку.',
                409,
            );
        }

        if ($targetItems === []) {
            throw new ReserveActionException(
                'empty_composition',
                'Состав пуст — чтобы отказаться от всего заказа, используйте отмену.',
            );
        }

        $order->load('items');
        $current = $order->items->filter(fn ($item) => ! $item->cancelled)->keyBy('id');
        $target = collect($targetItems)->keyBy('id');

        foreach ($target as $id => $row) {
            $item = $current->get($id);

            if ($item === null) {
                throw new ReserveActionException('line_not_found', 'Строка не найдена в заказе — обновите данные.');
            }

            if ($row['quantity'] > $item->quantity) {
                throw new ReserveActionException(
                    'increase_forbidden',
                    'Увеличить количество в резерве нельзя — только уменьшить. Нужно больше — оформите отдельный заказ.',
                );
            }
        }

        $changed = $current->contains(fn ($item) => ! $target->has($item->id)
            || (int) $target[$item->id]['quantity'] !== (int) $item->quantity);

        if (! $changed) {
            throw new ReserveActionException('no_changes', 'Изменений нет.');
        }

        $oldSnapshot = $changeLogger->snapshotItems($order);
        $oldTotal = (float) $order->total_amount;

        DB::transaction(function () use ($order, $current, $target) {
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

        // base_items_version — версия, от которой правил клиент (совпадает с нашей
        // после проверки выше); для заказа, ещё не получившего эха от 1С, — 0
        $publisher->publishUpdated($order, $baseItemsVersion ?? (int) ($order->items_version ?? 0));
    }

    /** @throws ReserveActionException */
    private function assertInReserveWindow(Order $order, string $verb): void
    {
        if (! $order->reserve || $order->trashed()) {
            throw new ReserveActionException(
                'not_reserved',
                "Заказ уже не в резерве — {$verb} его нельзя. Обновите данные, чтобы увидеть актуальное состояние.",
            );
        }
    }
}
