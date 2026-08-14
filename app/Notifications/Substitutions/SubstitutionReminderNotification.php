<?php

namespace App\Notifications\Substitutions;

use App\Models\SubstitutionOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Единственное автонапоминание клиенту о неоткрытой подборке замен.
 *
 * Строго одно: оптовик не должен получать капельную рассылку. Повторную
 * отправку блокирует отметка reminded_at на подборке.
 */
class SubstitutionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public SubstitutionOffer $offer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->offer->order?->erp_number ?: $this->offer->order?->number;

        $url = URL::temporarySignedRoute(
            'substitutions.show',
            $this->offer->expires_at,
            ['offer' => $this->offer->uuid],
        );

        $mail = (new MailMessage)
            ->subject(sprintf('Напоминание: замена по заказу %s ждёт вашего решения — Pecado.ru', $number))
            ->greeting('Здравствуйте!')
            ->line(sprintf(
                'По заказу %s часть позиций не прошла контроль при сборке — мы подобрали варианты замены с вашими ценами.',
                $number,
            ))
            ->line(sprintf('Подборка действует до %s, после этого потребуется связаться с менеджером.', $this->offer->expires_at->format('d.m.Y')))
            ->action('Посмотреть подборку замен', $url)
            ->line('Это единственное напоминание — больше мы вас не потревожим.');

        if ($this->offer->manager?->email) {
            $mail->replyTo($this->offer->manager->email, $this->offer->manager->name);
        }

        return $mail;
    }
}
