<?php

namespace App\Services\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\EmailStatus;
use App\Jobs\SendCrmEmailJob;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Services\Crm\Mail\PartnerAddressBook;
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
    public function visibleTo(User $actor, CrmScope $scope = CrmScope::DEPARTMENT): Builder
    {
        $query = CrmEmail::query();
        $actorId = (int) $actor->getKey();

        // Письмо на свободный адрес видит только автор — даже тот, кто видит
        // отдел. Иначе вместе с охватом отдела открылись бы чужие черновики,
        // а черновик это не запись о клиенте, а недописанная мысль.
        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('client_user_id', User::query()->inCrmScope($actor, $scope)->select('id'))
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

        // Письмо на адрес, который принадлежит партнёру (бухгалтеру, директору,
        // закупщику), подшивается к его карточке. Иначе переписка с живым
        // человеком висела бы письмом «в никуда», а в ленте партнёра её бы
        // не было — при том что это и есть его переписка.
        if ($email->client_user_id === null) {
            $clientId = app(PartnerAddressBook::class)->resolveAny($email->to ?? []);

            if ($clientId !== null) {
                $email->forceFill(['client_user_id' => $clientId])->save();
            }
        }

        // Письмо менеджера тоже часть общего потока: правило «всё по этому
        // контрагенту» обязано его видеть, иначе поток был бы общим на словах.
        // Получателей правило ему не проставляет — их выбрал человек.
        app(MailStream::class)->tagManualLetter($email);

        return $email->refresh();
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
            // Кем составлено: менеджером или системой по поводу. Колонка нужна
            // и тогда, когда автоматических писем ещё нет, — чтобы менеджеру
            // не пришлось переучиваться, когда они появятся.
            'origin' => $email->origin,
            'origin_label' => $email->isSystem() ? 'Система' : 'Менеджер',
            'origin_event' => $email->origin_event,
            'tags' => $email->tagList(),
            'skip_reason' => $email->skip_reason,
            // Кому письмо уже ушло: адрес, попавший сюда, второго письма
            // не получит, даже если его назовёт ещё одно правило.
            'delivered_to' => $email->relationLoaded('deliveries')
                ? $email->deliveries->whereNotNull('sent_at')->pluck('recipient')->values()->all()
                : [],
            'auto_sent_rule' => $email->autoSentRule?->name,
            'author' => [
                'id' => (int) $email->user_id,
                'name' => $email->isSystem() ? 'Система' : ($email->author?->name ?? '—'),
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
