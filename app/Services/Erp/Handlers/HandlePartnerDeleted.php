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
     * Находит пользователя по erp_id (UUID), с fallback-поиском
     * по email (для случая когда erp_id ещё не привязан).
     * Переводит в статус «Заблокирован».
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $email = $payload['email'] ?? null;

        if (! $uuid) {
            Log::warning('partner.deleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        // Основной поиск — по erp_id
        $user = User::where('erp_id', $uuid)->first();

        // Fallback — по email (erp_id мог ещё не быть привязан)
        if (! $user && $email) {
            $user = User::where('email', $email)->first();
        }

        if (! $user) {
            Log::warning('partner.deleted: пользователь не найден по erp_id или email', [
                'uuid' => $uuid,
                'email' => $email,
            ]);

            return;
        }

        User::withoutEvents(function () use ($user) {
            $user->update([
                'status' => UserStatus::BLOCKED,
            ]);
        });

        Log::info('partner.deleted: пользователь деактивирован', [
            'user_id' => $user->id,
            'erp_id' => $uuid,
            'email' => $user->email,
        ]);
    }
}
