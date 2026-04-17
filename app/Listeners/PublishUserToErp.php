<?php

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Jobs\PublishUserToErpJob;
use Illuminate\Support\Str;

class PublishUserToErp
{
    public function handle(object $event): void
    {
        if (! $event instanceof UserCreated) {
            return;
        }

        $user = $event->user;

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
