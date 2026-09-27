<?php

namespace App\Listeners;

use App\Events\AssistantAvailabilityChanged;
use App\Models\User;
use App\Notifications\Assistant\AssistantAvailabilityNotification;
use App\Services\Notifications\StaffNotifications;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

/**
 * Помощник пропал или вернулся — письмо РОПу, не чаще раза в час.
 *
 * Пополнение баланса не должно зависеть от того, что кто-то заметил пропажу
 * иконки. Сотрудник может отписаться у себя в «Моих уведомлениях».
 */
class NotifyAssistantAvailability
{
    private const COOLDOWN_KEY = 'assistant:availability-notified';

    public function __construct(private readonly StaffNotifications $staff) {}

    public function handle(AssistantAvailabilityChanged $event): void
    {
        $cooldown = max(1, (int) config('assistant.availability.notify_cooldown_minutes', 60));

        if (! $event->available && Cache::has(self::COOLDOWN_KEY)) {
            return;
        }

        if (! Role::query()->where('name', 'sales-head')->exists()) {
            return;
        }

        $heads = User::role('sales-head')->whereNotNull('email')->get();

        foreach ($heads as $head) {
            if (! $this->staff->wants($head, 'staff.assistant_availability')) {
                continue;
            }

            $head->notify(new AssistantAvailabilityNotification($event->available, $event->reason));
        }

        if (! $event->available) {
            Cache::put(self::COOLDOWN_KEY, true, now()->addMinutes($cooldown));
        } else {
            Cache::forget(self::COOLDOWN_KEY);
        }
    }
}
