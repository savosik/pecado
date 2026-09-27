<?php

namespace App\Notifications\Assistant;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо РОПу: помощник клиента исчез с сайта (или вернулся).
 */
class AssistantAvailabilityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly bool $available, public readonly ?string $reason) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->available) {
            return (new MailMessage)
                ->subject('Помощник клиента снова работает — Pecado.ru')
                ->greeting('Помощник вернулся')
                ->line('Помощник на сайте снова отвечает клиентам: проба Anthropic прошла успешно, иконка показывается.')
                ->line('Ничего делать не нужно.');
        }

        $reason = $this->reason ?? 'причина не указана';
        $hint = match (true) {
            str_starts_with($reason, 'billing') => 'Похоже, закончился баланс или достигнут лимит workspace в консоли Anthropic. Пополните баланс — помощник вернётся сам в течение десяти минут.',
            str_starts_with($reason, 'auth') => 'Ключ Anthropic не принят. Проверьте ANTHROPIC_API_KEY на сервере.',
            str_starts_with($reason, 'forbidden') => 'Anthropic закрыл доступ (регион или права организации). Проверьте прокси и организацию.',
            str_starts_with($reason, 'connection') => 'Нет связи с Anthropic: скорее всего, лёг контейнер outbound-proxy или туннель до VPS.',
            str_starts_with($reason, 'manual') => 'Помощник выключен командой assistant:availability off.',
            default => 'Проверьте команду assistant:ping на сервере.',
        };

        return (new MailMessage)
            ->subject('Помощник клиента пропал с сайта — Pecado.ru')
            ->greeting('Помощник недоступен')
            ->line('Иконка помощника скрыта у всех клиентов: сервис не может отвечать.')
            ->line('Причина: '.$reason)
            ->line($hint)
            ->line('Пока помощник спрятан, сервер раз в десять минут пробует Anthropic и вернёт его сам, как только ответ пройдёт.');
    }
}
