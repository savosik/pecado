<?php

namespace App\Notifications\Pulse;

use App\Models\NotificationDelivery;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Support\Notifications\MailClientTag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо, отправленное пультом.
 *
 * Отправляется on-demand: Notification::route('mail', $email)->notify(...).
 * Канал определяется правилом, поэтому один класс годится и для email,
 * и в будущем для telegram — достаточно добавить ветку в via().
 *
 * Ставит два заголовка: X-Pecado-Client (к какому клиенту относится письмо,
 * читается журналом писем) и X-Pecado-Delivery (какое решение пульта его
 * породило — по нему связываются два журнала).
 */
class PulseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly PulseSignal $signal,
        public readonly NotificationDelivery $delivery,
        public readonly string $subject,
        public readonly string $template,
        public readonly ?string $unsubscribeUrl = null,
    ) {
        // Письма не должны конкурировать с ERP-джобами и выгрузками:
        // всплеск рассылки задержал бы обработку шины.
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return match ($this->delivery->channel) {
            'telegram' => ['telegram'], // задел: канал появится отдельной карточкой
            default => ['mail'],
        };
    }

    /**
     * Отправка не удалась после всех попыток.
     *
     * Жёсткий отказ сервера кладёт адрес в стоп-лист, чтобы он не отбивался
     * на каждом следующем письме и не портил репутацию отправителя.
     */
    public function failed(\Throwable $exception): void
    {
        app(\App\Listeners\RecordMailBounce::class)
            ->handleFailure($this->delivery->id, $exception->getMessage());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $view = $this->signal->view;

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->markdown($this->template, [
                'title' => $view['title'] ?? $this->subject,
                'body' => $view['body'] ?? '',
                'rows' => $view['rows'] ?? [],
                'url' => $view['url'] ?? null,
                'entityLabel' => $view['entity_label'] ?? null,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);

        if ($this->signal->clientUserId !== null) {
            MailClientTag::tag($mail, $this->signal->clientUserId);
        }

        // По этому заголовку LogSentEmail связывает письмо с решением пульта
        $mail->withSymfonyMessage(function ($message): void {
            $message->getHeaders()->addTextHeader('X-Pecado-Delivery', (string) $this->delivery->id);

            // Рекламное письмо обязано нести машиночитаемую отписку: почтовые
            // клиенты показывают по ней кнопку, и без неё жалоба на спам
            // становится единственным способом прекратить рассылку.
            if ($this->unsubscribeUrl !== null && str_starts_with($this->signal->eventKey, 'campaigns.')) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });

        return $mail;
    }
}
