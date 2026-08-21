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
        $notice = $event->notice;

        // Общий гейт email-рассылки подписок (на prod по умолчанию выключен).
        if (! config('notifications.mail.features.entity_subscriptions')) {
            return;
        }

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
            // Адресат мог сузить подписку до отдельных типов событий раздела
            // (напр. только состав заказа, без правок реквизитов).
            if (! $subscription->wantsEvent($notice->event)) {
                continue;
            }

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
