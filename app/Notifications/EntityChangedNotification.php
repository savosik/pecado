<?php

namespace App\Notifications;

use App\Models\EntitySubscription;
use App\Services\Subscriptions\SubscriptionRegistry;
use App\Subscriptions\EntityChangeNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Универсальное уведомление об изменении сущности раздела кабинета.
 *
 * Отправляется on-demand: Notification::route('mail', $email)->notify(...).
 * Канал определяется маршрутом, поэтому один класс годится и для email, и
 * (в будущем) для telegram — достаточно добавить ветку в via()/toTelegram().
 */
class EntityChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public EntityChangeNotice $notice,
        public EntitySubscription $subscription,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return match ($this->subscription->channel) {
            'telegram' => ['telegram'], // задел: канал появится в фазе Telegram
            default => ['mail'],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sectionLabel = app(SubscriptionRegistry::class)->label($this->notice->section);

        return (new MailMessage)
            ->subject($this->notice->title)
            ->markdown('mail.subscriptions.entity-changed', [
                'sectionLabel' => $sectionLabel,
                'title' => $this->notice->title,
                'body' => $this->notice->body,
                'url' => $this->notice->url,
                'entityLabel' => $this->notice->entityLabel,
                'unsubscribeUrl' => url(route('subscriptions.unsubscribe', $this->subscription->unsubscribe_token, false)),
            ]);
    }
}
