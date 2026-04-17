<?php

namespace App\Services\Erp\Handlers;

use App\Models\ProductReturn;
use Illuminate\Support\Facades\Log;

class HandleReturnDeleted
{
    /**
     * Обработка события return.deleted из 1С.
     * Soft-delete возврата по UUID.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleReturnDeleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $return = ProductReturn::where('uuid', $uuid)->first();

        if (! $return) {
            Log::info('HandleReturnDeleted: возврат не найден', ['uuid' => $uuid]);

            return;
        }

        $return->delete();

        Log::info('HandleReturnDeleted: возврат удалён (soft-delete)', ['uuid' => $uuid]);
    }
}
