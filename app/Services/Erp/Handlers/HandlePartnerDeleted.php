<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandlePartnerDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $email = $payload['email'] ?? null;

        if (! $uuid && ! $email) {
            Log::warning('partner.deleted: отсутствуют uuid и email', ['payload' => $payload]);

            return;
        }

        $user = null;

        if ($uuid) {
            $user = User::where('erp_id', $uuid)->first();
        }

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
