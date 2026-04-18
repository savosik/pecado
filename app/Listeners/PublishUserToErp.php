<?php

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Jobs\PublishUserToErpJob;
use App\Models\User;
use Illuminate\Support\Str;

class PublishUserToErp
{
    public function handle(object $event): void
    {
        $user = match (true) {
            $event instanceof UserCreated => $this->resolveFromCreated($event),
            $event instanceof UserUpdated => $this->resolveFromUpdated($event),
            default => null,
        };

        if ($user === null) {
            return;
        }

        $this->dispatch($user);
    }

    private function resolveFromCreated(UserCreated $event): ?User
    {
        // Пропускаем если имя ещё не заполнено — будет отправлено после онбординга через UserUpdated
        return empty($event->user->name) ? null : $event->user;
    }

    private function resolveFromUpdated(UserUpdated $event): ?User
    {
        $user = $event->user;

        // Отправляем partner.created только когда имя заполняется впервые (было null → стало непустым)
        if ($user->wasChanged('name') && empty($user->getOriginal('name')) && ! empty($user->name)) {
            return $user;
        }

        return null;
    }

    private function dispatch(User $user): void
    {
        $previewUrl = route('user.preview', ['token' => $user->view_token]);

        $payload = [
            'event' => 'partner.created',
            'timestamp' => now()->toIso8601String(),
            'message_id' => 'msg-'.Str::uuid()->toString(),
            'uuid' => (string) ($user->erp_id ?? $user->id),
            'login' => $user->email,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'is_active' => $user->status === UserStatus::ACTIVE,
            'comment' => $previewUrl,
        ];

        PublishUserToErpJob::dispatch($payload);
    }
}
