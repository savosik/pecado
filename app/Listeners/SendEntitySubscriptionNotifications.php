<?php

namespace App\Listeners;

use App\Events\EntityChanged;
use App\Models\EntitySubscription;
use App\Notifications\EntityChangedNotification;
use App\Services\Notifications\Pulse\PulseMode;
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

    /**
     * Раздел кабинета и тип события → ключ события пульта.
     *
     * Тот же маппинг, что в notifications:import-subscriptions: подписки
     * переезжают в правила именно по нему.
     */
    private const PULSE_EVENTS = [
        'orders' => [
            'items_updated' => 'orders.items_updated',
            'attributes_updated' => 'orders.attributes_updated',
            'api_shortfall' => 'orders.shortfall',
            'substitution_offered' => 'orders.substitution_offered',
        ],
        'documents' => [],
    ];

    public function handle(EntityChanged $event): void
    {
        $notice = $event->notice;

        // Событие переведено на пульт — здесь молчим. Подписчик кабинета
        // получит письмо по правилу, в которое его подписка перенесена
        // командой notifications:import-subscriptions; без этого гейта
        // ему пришло бы два письма об одном изменении.
        if ($this->handledByPulse($notice)) {
            return;
        }

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

    /**
     * Отвечает ли пульт за это изменение.
     *
     * Для подписки «все типы» (event = null) смотрим на маску раздела:
     * такая подписка переносится одним правилом orders.*, и молчать надо
     * ровно тогда, когда пульт обрабатывает раздел целиком.
     */
    private function handledByPulse(\App\Subscriptions\EntityChangeNotice $notice): bool
    {
        $map = self::PULSE_EVENTS[$notice->section] ?? [];

        if ($notice->event !== null && isset($map[$notice->event])) {
            return PulseMode::handles($map[$notice->event]);
        }

        // Раздел без градации или незнакомый тип: ориентируемся на маску домена
        return PulseMode::handles($notice->section.'.*');
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
