<?php

namespace App\Listeners;

use App\Events\EntityChanged;
use App\Models\EntitySubscription;
use App\Notifications\EntityChangedNotification;
use App\Services\Subscriptions\SubscriptionRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Диспетчер уведомлений подписчикам раздела.
 *
 * По событию EntityChanged находит активные подписки владельца сущности на
 * данный раздел и рассылает EntityChangedNotification по каналу каждой
 * подписки (on-demand — адресат хранится в подписке, а не в модели User).
 */
class SendEntitySubscriptionNotifications implements ShouldQueue
{
    /**
     * Дожидаемся коммита транзакции, в которой создан OrderChangeLog
     * (ERP/админка оборачивают правки в транзакцию) — иначе при откате
     * подписчики получили бы письмо о несостоявшемся изменении.
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly SubscriptionRegistry $registry,
    ) {}

    public function handle(EntityChanged $event): void
    {
        // Общий гейт email-рассылки подписок (на prod по умолчанию выключен).
        if (! config('notifications.mail.features.entity_subscriptions')) {
            return;
        }

        $notice = $event->notice;

        // Раздел должен быть зарегистрирован и включён в реестре.
        if (! $this->registry->isEnabled($notice->section)) {
            return;
        }

        $subscriptions = EntitySubscription::query()
            ->where('user_id', $notice->ownerUserId)
            ->where('section', $notice->section)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->deliver($subscription, $notice);
        }
    }

    private function deliver(EntitySubscription $subscription, \App\Subscriptions\EntityChangeNotice $notice): void
    {
        // Сейчас реализован только email; telegram — задел на будущую фазу.
        if ($subscription->channel !== 'email' || blank($subscription->destination)) {
            return;
        }

        Notification::route('mail', $subscription->destination)
            ->notify(new EntityChangedNotification($notice, $subscription));

        $subscription->forceFill(['last_notified_at' => now()])->saveQuietly();
    }
}
