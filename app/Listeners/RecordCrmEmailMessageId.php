<?php

namespace App\Listeners;

use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use Illuminate\Mail\Events\MessageSent;

/**
 * Дописывает Message-ID в запись журнала после фактической отправки.
 *
 * Message-ID выдаёт почтовая система в момент отправки, поэтому заранее его записать
 * нельзя. Без него письмо невозможно найти в логах почтового сервера, а это первое,
 * что спрашивают при разборе «клиент говорит, что ничего не получал».
 *
 * Связка идёт через собственный заголовок: он единственное, что переживает путь
 * от Mailable до события.
 */
class RecordCrmEmailMessageId
{
    public function handle(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(CrmManagerMail::ID_HEADER);

        if ($header === null) {
            return;
        }

        $emailId = (int) $header->getBodyAsString();
        $messageId = $event->sent->getMessageId();

        if ($emailId === 0 || $messageId === '') {
            return;
        }

        CrmEmail::query()->whereKey($emailId)->update(['message_id' => $messageId]);
    }
}
