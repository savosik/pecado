<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * Пометка письма клиентом, к которому оно относится.
 *
 * Определять клиента по адресу получателя достаточно ровно до первого письма,
 * которое уходит не клиенту: уведомление менеджеру о заказе адресовано менеджеру,
 * а событие принадлежит клиенту. Поэтому клиент едет в заголовке письма —
 * отправитель знает его точно, а журнал читает готовое значение.
 */
class MailClientTag
{
    public const HEADER = 'X-Pecado-Client';

    /**
     * Пометить письмо клиентом. `null` оставляет письмо непомеченным.
     */
    public static function tag(MailMessage $mail, int|string|null $clientId): MailMessage
    {
        if (blank($clientId)) {
            return $mail;
        }

        return $mail->withSymfonyMessage(function (Email $message) use ($clientId): void {
            $message->getHeaders()->addTextHeader(self::HEADER, (string) $clientId);
        });
    }

    /**
     * Прочитать клиента из письма.
     */
    public static function read(Email $message): ?int
    {
        $header = $message->getHeaders()->get(self::HEADER);

        if ($header === null) {
            return null;
        }

        $value = (int) $header->getBodyAsString();

        return $value > 0 ? $value : null;
    }
}
