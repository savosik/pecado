<?php

namespace App\Mail;

use App\Models\CrmEmail;
use App\Support\Crm\CrmAttachments;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо клиенту от менеджера — первый Mailable в проекте.
 *
 * Notification здесь не подходит принципиально: получатель не наш пользователь,
 * а произвольный адрес, и тело письма задаёт человек, а не шаблон.
 *
 * From — общий ящик отдела, Reply-To — личная почта менеджера. Это даёт клиенту
 * возможность ответить конкретному человеку и при этом не требует хранить пароли
 * личных ящиков и настраивать SPF/DKIM под каждый.
 */
class CrmManagerMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Заголовок, по которому слушатель MessageSent находит запись журнала:
     * Message-ID выдаёт SMTP-сервер, и до отправки его ещё не существует.
     */
    public const ID_HEADER = 'X-Crm-Email-Id';

    public function __construct(public CrmEmail $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->email->subject,
            replyTo: $this->email->reply_to === null ? [] : [$this->email->reply_to],
            cc: $this->email->cc ?? [],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            self::ID_HEADER => (string) $this->email->getKey(),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.crm.manager-message',
            with: ['bodyHtml' => $this->email->body_html],
        );
    }

    /**
     * Вложения берутся из той же медиа-коллекции, что и файлы остальных сущностей CRM.
     *
     * @return list<\Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return $this->email
            ->getMedia(CrmAttachments::COLLECTION)
            ->map(fn ($media) => \Illuminate\Mail\Mailables\Attachment::fromStorageDisk(
                $media->disk,
                $media->getPathRelativeToRoot(),
            )->as($media->file_name)->withMime($media->mime_type))
            ->all();
    }
}
