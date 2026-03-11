<?php

namespace App\Services\Erp\Handlers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class HandleOrderDeleted
{
    /**
     * Обработка события order.deleted из 1С.
     * Soft-delete заказа по UUID.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('HandleOrderDeleted: отсутствует uuid', ['payload' => $payload]);
            return;
        }

        $order = Order::where('uuid', $uuid)->first();

        if (!$order) {
            Log::info('HandleOrderDeleted: заказ не найден', ['uuid' => $uuid]);
            return;
        }

        $order->status = 'cancelled';
        $order->save();

        Log::info('HandleOrderDeleted: заказ распроведён (статус cancelled, запись сохранена)', ['uuid' => $uuid]);
    }
}
