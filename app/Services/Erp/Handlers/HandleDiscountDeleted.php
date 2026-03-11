<?php

namespace App\Services\Erp\Handlers;

use App\Models\Discount;
use Illuminate\Support\Facades\Log;

class HandleDiscountDeleted
{
    /**
     * Обработка события discount.deleted из 1С.
     *
     * Деактивирует скидку (is_posted = false) и выполняет soft delete.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('discount.deleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $discount = Discount::where('external_id', $uuid)->first();

        if (!$discount) {
            Log::info('discount.deleted: скидка не найдена по UUID, событие проигнорировано', [
                'uuid' => $uuid,
            ]);

            return;
        }

        $discount->update(['is_posted' => false]);
        $discount->delete(); // soft delete

        Log::info('discount.deleted: скидка деактивирована', [
            'discount_id' => $discount->id,
            'uuid' => $uuid,
        ]);
    }
}
