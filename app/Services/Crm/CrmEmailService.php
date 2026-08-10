<?php

namespace App\Services\Crm;

use App\Enums\Crm\EmailStatus;
use App\Jobs\SendCrmEmailJob;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Письма менеджеров: черновики, отправка, форма ответа.
 *
 * Отправка идёт через очередь и всегда оставляет след в журнале — и успешная,
 * и провалившаяся. Письмо, о судьбе которого менеджер узнаёт только от партнёра,
 * хуже, чем неотправленное.
 */
class CrmEmailService
{
    /**
     * Письма, доступные актору.
     *
     * Переписка с партнёром — история партнёра, а не личное дело автора: менеджер видит
     * письма по своим партнёрам независимо от того, кто их писал. Письма без партнёра
     * (свободный адрес) видит только автор.
     *
     * @return Builder<CrmEmail>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmEmail::query();

        if ($actor->can('crm-clients-all.view')) {
            return $query;
        }

        $actorId = (int) $actor->getKey();

        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('client_user_id', User::query()->visibleInCrm($actor)->select('id'))
            ->orWhere(fn (Builder $own) => $own
                ->whereNull('client_user_id')
                ->where('user_id', $actorId)));
    }

    public function outboundEnabled(): bool
    {
        return (bool) config('notifications.mail.features.crm_outbound');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(User $actor, array $data, ?Model $related): CrmEmail
    {
        $email = new CrmEmail([
            'to' => $this->normalizeAddresses($data['to'] ?? []),
            'cc' => $this->normalizeAddresses($data['cc'] ?? []) ?: null,
            // Ответ партнёра должен прийти живому человеку, а не в общий ящик отдела.
            'reply_to' => $data['reply_to'] ?? $actor->email,
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'status' => EmailStatus::DRAFT->value,
        ]);

        if ($related !== null) {
            $email->related()->associate($related);
        }

        $email->user_id = (int) $actor->getKey();
        $email->save();

        return $email;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CrmEmail $email, array $data): CrmEmail
    {
        foreach (['subject', 'body_html', 'reply_to'] as $field) {
            if (array_key_exists($field, $data)) {
                $email->{$field} = $data[$field];
            }
        }

        if (array_key_exists('to', $data)) {
            $email->to = $this->normalizeAddresses($data['to']);
        }

        if (array_key_exists('cc', $data)) {
            $email->cc = $this->normalizeAddresses($data['cc']) ?: null;
        }

        // Повторная правка после сбоя возвращает письмо в черновики: иначе оно так
        // и висело бы «с ошибкой», хотя причину уже устранили.
        if ($email->status === EmailStatus::FAILED) {
            $email->status = EmailStatus::DRAFT;
            $email->error = null;
        }

        $email->save();

        return $email;
    }

    /**
     * Поставить письмо в очередь отправки.
     *
     * @throws RuntimeException когда отправка выключена флагом
     */
    public function send(CrmEmail $email): CrmEmail
    {
        if (! $this->outboundEnabled()) {
            throw new RuntimeException('Отправка писем из CRM выключена администратором.');
        }

        if ($email->to === []) {
            throw new RuntimeException('Не указан ни один получатель.');
        }

        $email->status = EmailStatus::QUEUED;
        $email->error = null;
        $email->save();

        SendCrmEmailJob::dispatch($email);

        return $email;
    }

    /**
     * Заготовка с раскрытыми подстановками.
     *
     * @return array{subject: string, body_html: string}
     */
    public function applyTemplate(CrmEmailTemplate $template, User $actor, ?User $client): array
    {
        $values = [
            'client_name' => $client === null ? '' : (string) $client->name,
            'manager_name' => $actor->name,
        ];

        return [
            'subject' => CrmEmailTemplate::render($template->subject, $values),
            'body_html' => CrmEmailTemplate::render($template->body_html, $values),
        ];
    }

    /**
     * Адреса из формы: пустые строки и дубликаты выбрасываем здесь, а не в валидации,
     * чтобы лишняя запятая в поле не превращалась в ошибку на весь запрос.
     *
     * @return list<string>
     */
    private function normalizeAddresses(mixed $addresses): array
    {
        if (is_string($addresses)) {
            $addresses = preg_split('/[,;\s]+/', $addresses) ?: [];
        }

        if (! is_array($addresses)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($address): string => trim((string) $address), $addresses),
            fn (string $address): bool => $address !== '',
        )));
    }

    /**
     * Форма письма для фронта — одна на журнал, карточку и ленту партнёра.
     *
     * @return array<string, mixed>
     */
    public function payload(CrmEmail $email, User $viewer): array
    {
        $related = $email->related;

        return [
            'id' => (int) $email->getKey(),
            'subject' => $email->subject,
            // HTML отдаём как есть — санитайзинг стоит на фронте, при показе.
            'body_html' => $email->body_html,
            'to' => $email->to,
            'cc' => $email->cc ?? [],
            'reply_to' => $email->reply_to,
            'status' => $email->status->value,
            'status_label' => $email->status->label(),
            'status_color' => $email->status->color(),
            'sent_at_label' => $email->sent_at?->format('d.m.Y H:i'),
            'created_at_label' => $email->created_at?->format('d.m.Y H:i'),
            'message_id' => $email->message_id,
            'error' => $email->error,
            'author' => [
                'id' => (int) $email->user_id,
                'name' => $email->author->name,
            ],
            'client_id' => $email->client_user_id === null ? null : (int) $email->client_user_id,
            'entity' => $related instanceof Model
                ? CrmEntityMap::describe($related, $viewer)
                : null,
            'attachments_count' => (int) ($email->attachments_count
                ?? $email->media()->where('collection_name', CrmAttachments::COLLECTION)->count()),
            'can' => [
                'update' => $viewer->can('update', $email),
                'send' => $viewer->can('send', $email),
                'delete' => $viewer->can('delete', $email),
            ],
        ];
    }
}
