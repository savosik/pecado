<?php

namespace App\Services\Erp\Handlers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class HandleOrderDeleted
{
    /**
     * Обработка события order.deleted из 1С.
     *
     * До v14 заказ переводился в отдельный финальный статус `deleted`.
     * Начиная с v14 (выравнивание со справочником 1С) такого статуса нет,
     * поэтому удаление выражается через soft-delete (`deleted_at`)
     * + перевод статуса в `closed`. Запись в БД сохраняется для аудита.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleOrderDeleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $order = Order::withTrashed()->where('uuid', $uuid)->first();

        if (! $order) {
            Log::info('HandleOrderDeleted: заказ не найден', ['uuid' => $uuid]);

            return;
        }

        $order->status = OrderStatus::CLOSED;
        $order->fromErp = true;
        $order->saveQuietly();

        if (! $order->trashed()) {
            $order->deleteQuietly();
        }

        Log::info('HandleOrderDeleted: заказ помечен удалённым (soft-delete, status=closed)', [
            'uuid' => $uuid,
        ]);
    }
}
