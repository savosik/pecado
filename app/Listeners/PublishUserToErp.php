<?php

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Events\UserUpdated;
use App\Jobs\PublishUserToErpJob;
use Illuminate\Support\Str;

class PublishUserToErp
{
    /**
     * Handle the event.
     *
     * Согласно US-01 v2: публикует partner.created в erp_out.partners
     * только когда статус пользователя переводится в «Активен».
     */
    public function handle(object $event): void
    {
        if (!isset($event->user)) {
            return;
        }

        $user = $event->user;

        // Публикуем только для события UserUpdated, когда статус изменился на ACTIVE
        if ($event instanceof UserUpdated) {
            $changes = $user->getChanges();

            // Проверяем, что статус изменился именно на ACTIVE
            if (!isset($changes['status']) || $changes['status'] !== UserStatus::ACTIVE->value) {
                return;
            }
        } else {
            // Для других событий (UserCreated и т.д.) не публикуем
            return;
        }

        // Формируем payload согласно US-01 v2: partner.created (Сайт → 1С)
        $payload = [
            'event'      => 'partner.created',
            'timestamp'  => now()->toIso8601String(),
            'message_id' => 'msg-' . Str::uuid()->toString(),
            'uuid'       => (string) ($user->erp_id ?? $user->id),
            'login'      => $user->email,
            'name'       => $user->full_name,
            'phone'      => $user->phone,
            'email'      => $user->email,
        ];

        PublishUserToErpJob::dispatch($payload);
    }
}
