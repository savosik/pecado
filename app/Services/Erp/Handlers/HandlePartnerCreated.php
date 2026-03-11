<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandlePartnerCreated
{
    /**
     * Обработка события partner.created из 1С.
     *
     * Находит пользователя по login (email), устанавливает erp_id (UUID)
     * и переводит в статус «Активен».
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $login = $payload['login'] ?? null;

        if (!$uuid || !$login) {
            Log::warning('partner.created: отсутствует uuid или login', ['payload' => $payload]);

            return;
        }

        $user = User::where('email', $login)->first();

        if (!$user) {
            Log::warning('partner.created: пользователь не найден по login', [
                'login' => $login,
                'uuid' => $uuid,
            ]);

            return;
        }

        $user->update([
            'erp_id' => $uuid,
            'status' => UserStatus::ACTIVE,
        ]);

        Log::info('partner.created: пользователь активирован', [
            'user_id' => $user->id,
            'login' => $login,
            'erp_id' => $uuid,
        ]);
    }
}
