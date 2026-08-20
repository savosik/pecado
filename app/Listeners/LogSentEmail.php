<?php

namespace App\Listeners;

use App\Enums\UserKind;
use App\Mail\CrmManagerMail;
use App\Models\NotificationDelivery;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\Notifications\MailClientTag;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Журнал исходящих писем: одна запись на каждого получателя.
 *
 * Слушаем `MessageSent`, а не `NotificationSent`, потому что вопрос
 * «кому ушло письмо» не про уведомления, а про почту: через ту же трубу идут
 * письма менеджеров из CRM и всё, что появится дальше. Событие приходит после
 * успешной сдачи письма транспорту, поэтому в журнале нет того, что не ушло.
 *
 * Письмо на пять адресов даёт пять записей, а не одну со списком: журнал
 * читают вопросом «что получил этот адрес», и список внутри строки на такой
 * вопрос не отвечает.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        if (! config('notifications.mail.journal_enabled')) {
            return;
        }

        try {
            $this->record($event);
        } catch (\Throwable $e) {
            // Журнал — наблюдение за отправкой, а не её часть. Упавшая запись
            // не должна превращать успешно отправленное письмо в ошибку job-а
            // и уводить уведомление на повторную отправку.
            Log::warning('Не удалось записать письмо в журнал', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Чем письмо было порождено.
     *
     * У уведомлений класс кладёт сам Laravel. Mailable такого следа не оставляет,
     * поэтому письмо менеджера узнаётся по собственному заголовку — иначе оно
     * попало бы в ленту партнёра вторым экземпляром рядом с записью `crm_emails`.
     */
    private function source(MessageSent $event): ?string
    {
        if (isset($event->data['__laravel_notification'])) {
            return (string) $event->data['__laravel_notification'];
        }

        return $event->message->getHeaders()->has(CrmManagerMail::ID_HEADER)
            ? CrmManagerMail::class
            : null;
    }

    private function record(MessageSent $event): void
    {
        $message = $event->message;

        $recipients = array_map(
            fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
            $message->getTo(),
        );

        if ($recipients === []) {
            return;
        }

        $clientId = MailClientTag::read($message);
        $source = $this->source($event);
        $messageId = $message->getHeaders()->get('Message-ID')?->getBodyAsString();
        $sentAt = now();
        $deliveryId = $this->deliveryId($message);

        // Решение пульта помечается отправленным здесь, а не в момент постановки
        // в очередь: журнал доставок должен отвечать за факт, а не за намерение.
        if ($deliveryId !== null) {
            NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->update([
                    'status' => NotificationDelivery::STATUS_SENT,
                    'sent_at' => $sentAt,
                    'message_id' => $this->cut($messageId, 512),
                    'updated_at' => $sentAt,
                ]);
        }

        // Один запрос на всех получателей письма вместо запроса на адрес:
        // рассылка на отдел иначе давала бы по обращению к users на строку.
        $users = User::query()
            ->whereIn('email', $recipients)
            ->get(['id', 'email', 'user_kind'])
            ->keyBy('email');

        foreach ($recipients as $recipient) {
            $user = $users->get($recipient);

            // Письмо без явной пометки, ушедшее клиенту, относится к нему же:
            // так в ленту попадают письма о регистрации и смене пароля, где
            // помечать нечего — получатель и есть клиент. Сотрудник в эту ветку
            // не попадает: письмо коллеге о задаче не событие в жизни партнёра.
            $fallbackClientId = $user?->user_kind === UserKind::CLIENT
                ? (int) $user->getKey()
                : null;

            SentEmail::create([
                'recipient' => $recipient,
                // Тема режется по длине колонки: длинная тема — не повод
                // потерять запись о том, что письмо ушло.
                'subject' => $this->cut($message->getSubject(), 512),
                'source' => $source,
                'client_user_id' => $clientId ?? $fallbackClientId,
                'recipient_user_id' => $user?->getKey(),
                'message_id' => $this->cut($messageId, 512),
                'notification_delivery_id' => $deliveryId,
                'sent_at' => $sentAt,
            ]);
        }
    }

    /**
     * Решение пульта, породившее письмо.
     *
     * Заголовок ставит PulseNotification — тем же приёмом, что и пометка
     * клиента: связка двух журналов позволяет пройти путь целиком, от письма
     * в почтовом логе до правила, которое его отправило.
     */
    private function deliveryId(\Symfony\Component\Mime\Email $message): ?int
    {
        $header = $message->getHeaders()->get('X-Pecado-Delivery')?->getBodyAsString();

        return filled($header) && ctype_digit(trim($header)) ? (int) trim($header) : null;
    }

    private function cut(?string $value, int $limit): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $limit);
    }
}
