<?php

namespace App\Services\Erp\Handlers;

use App\Enums\ReturnStatus;
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

        if (! $uuid) {
            Log::warning('HandleReturnUpdated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $return = ProductReturn::where('uuid', $uuid)->first();

        if (! $return) {
            Log::info('HandleReturnUpdated: возврат не найден', ['uuid' => $uuid]);

            return;
        }

        $changed = false;

        if (isset($payload['number'])) {
            $return->erp_number = $payload['number'];
            $changed = true;
        }

        if (isset($payload['status'])) {
            $rawStatus = $payload['status'];
            $status = ReturnStatus::tryFrom($rawStatus);

            if ($status === null) {
                Log::warning('HandleReturnUpdated: неизвестный статус из 1С, статус не изменён', [
                    'uuid' => $uuid,
                    'status' => $rawStatus,
                ]);
            } else {
                $return->status = $status;
                $changed = true;
            }
        }

        if ($changed) {
            $return->save();
        }

        Log::info('HandleReturnUpdated: возврат обновлён', [
            'uuid' => $uuid,
            'status' => $payload['status'] ?? 'не изменён',
        ]);
    }
}
