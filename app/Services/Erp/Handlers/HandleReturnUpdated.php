<?php

namespace App\Services\Erp\Handlers;

use App\Models\ProductReturn;
use Illuminate\Support\Facades\Log;

class HandleReturnUpdated
{
    /**
     * Обработка события return.updated из 1С.
     * Обновляет статус возврата по UUID.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('HandleReturnUpdated: отсутствует uuid', ['payload' => $payload]);
            return;
        }

        $return = ProductReturn::where('uuid', $uuid)->first();

        if (!$return) {
            Log::info('HandleReturnUpdated: возврат не найден', ['uuid' => $uuid]);
            return;
        }

        if (isset($payload['status'])) {
            $return->status = $payload['status'];
            $return->save();
        }

        Log::info('HandleReturnUpdated: возврат обновлён', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
        ]);
    }
}
