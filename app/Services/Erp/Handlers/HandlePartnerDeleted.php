<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandlePartnerDeleted
{
    /**
     * Обработка события partner.deleted из 1С.
     *
     * Находит пользователя по erp_id (UUID) и переводит
     * в статус «Заблокирован».
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('partner.deleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $user = User::where('erp_id', $uuid)->first();

        if (!$user) {
            Log::warning('partner.deleted: пользователь не найден по erp_id', [
                'uuid' => $uuid,
            ]);

            return;
        }

        $user->update([
            'status' => UserStatus::BLOCKED,
        ]);

        Log::info('partner.deleted: пользователь деактивирован', [
            'user_id' => $user->id,
            'erp_id' => $uuid,
        ]);
    }
}
