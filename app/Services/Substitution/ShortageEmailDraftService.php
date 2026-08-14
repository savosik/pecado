<?php

namespace App\Services\Substitution;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\SubstitutionOffer;
use App\Models\User;
use App\Services\Crm\CrmEmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Письмо-извинение о недоборе: система готовит черновик, менеджер правит
 * и отправляет из карточки. Автоотправки в волне 1 нет вообще.
 *
 * Паттерн back-in-stock: черновик — это CrmEmail в истории переписки клиента,
 * от имени персонального менеджера (reply-to — его личная почта, from — общий
 * ящик отдела, как во всех письмах CRM).
 */
class ShortageEmailDraftService
{
    public const TEMPLATE_NAME = 'Недобор: подборка замен';

    public function __construct(
        private readonly CrmEmailService $emails,
    ) {}

    /**
     * Черновик при создании оффера. Без email клиента или менеджера черновик
     * не создаётся — карточка покажет письмо, сгенерированное на лету.
     */
    public function createDraft(SubstitutionOffer $offer): ?CrmEmail
    {
        $offer->loadMissing(['order.items', 'user', 'manager']);

        $client = $offer->user;
        $manager = $offer->manager;

        if ($client === null || blank($client->email) || $manager === null) {
            return null;
        }

        try {
            [$subject, $body] = $this->render($offer);

            $email = CrmEmail::create([
                'user_id' => $manager->id,
                'related_type' => $offer->order->getMorphClass(),
                'related_id' => $offer->order_id,
                'to' => [$client->email],
                'reply_to' => $manager->email,
                'subject' => $subject,
                'body_html' => $body,
                'status' => EmailStatus::DRAFT,
            ]);

            $offer->update(['crm_email_id' => $email->id]);

            return $email;
        } catch (\Throwable $e) {
            Log::warning('Недобор: не удалось создать черновик письма', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Черновик для карточки: сохранённый CrmEmail, а если его нет — текст на лету.
     *
     * @return array{subject: string, body_html: string, to: string|null, email_id: int|null, editable: bool}
     */
    public function draftFor(SubstitutionOffer $offer): array
    {
        $offer->loadMissing(['draftEmail', 'user', 'manager', 'order.items']);

        $email = $offer->draftEmail;

        if ($email !== null && $email->status->isEditable()) {
            return [
                'subject' => (string) $email->subject,
                'body_html' => (string) $email->body_html,
                'to' => $email->to[0] ?? null,
                'email_id' => $email->id,
                'editable' => true,
            ];
        }

        [$subject, $body] = $this->render($offer);

        return [
            'subject' => $subject,
            'body_html' => $body,
            'to' => $offer->user?->email,
            'email_id' => null,
            'editable' => $offer->user !== null && filled($offer->user->email),
        ];
    }

    /**
     * Отправка подборки из карточки: правки менеджера ложатся в черновик,
     * черновик уходит через общий контур CRM-писем (очередь, журнал, история).
     *
     * @return array{ok: bool, message: string|null}
     */
    public function send(SubstitutionOffer $offer, User $actor, string $subject, string $bodyHtml): array
    {
        if (! $this->emails->outboundEnabled()) {
            return [
                'ok' => false,
                'message' => 'Отправка писем из CRM выключена администратором (MAIL_FEATURE_CRM_OUTBOUND).',
            ];
        }

        $offer->loadMissing(['draftEmail', 'user', 'manager', 'order']);

        $client = $offer->user;

        if ($client === null || blank($client->email)) {
            return ['ok' => false, 'message' => 'У клиента нет email — письмо отправить некому.'];
        }

        $email = $offer->draftEmail;

        if ($email === null || ! $email->status->isEditable()) {
            $email = CrmEmail::create([
                'user_id' => $offer->manager?->id ?? $actor->id,
                'related_type' => $offer->order->getMorphClass(),
                'related_id' => $offer->order_id,
                'to' => [$client->email],
                'reply_to' => ($offer->manager ?? $actor)->email,
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'status' => EmailStatus::DRAFT,
            ]);

            $offer->update(['crm_email_id' => $email->id]);
        } else {
            $email->update([
                'subject' => $subject !== '' ? $subject : $email->subject,
                'body_html' => $bodyHtml !== '' ? $bodyHtml : $email->body_html,
            ]);
        }

        try {
            $this->emails->send($email);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => null];
    }

    /**
     * Текст письма: шаблон CRM, если заведён, иначе встроенный.
     *
     * @return array{0: string, 1: string}
     */
    private function render(SubstitutionOffer $offer): array
    {
        $client = $offer->user;
        $manager = $offer->manager;
        $number = $offer->order?->erp_number ?: $offer->order?->number;

        $lines = $offer->order
            ? $offer->order->items->where('cancelled', true)->values()
            : collect();

        $linesHtml = $lines->isEmpty()
            ? ''
            : '<ul>'.$lines->map(fn ($item) => sprintf(
                '<li>%s — %d шт.</li>',
                e($item->name),
                $item->quantity,
            ))->implode('').'</ul>';

        $link = Route::has('substitutions.show')
            ? \URL::temporarySignedRoute('substitutions.show', $offer->expires_at, ['offer' => $offer->uuid])
            : '';

        $values = [
            'client_name' => $client?->name ?? 'клиент',
            'manager_name' => $manager?->name ?? 'команда Pecado',
            'order_number' => (string) $number,
            'lines' => $linesHtml,
            'link' => $link,
        ];

        $template = CrmEmailTemplate::query()
            ->where('name', self::TEMPLATE_NAME)
            ->where('is_active', true)
            ->first();

        if ($template !== null) {
            return [
                CrmEmailTemplate::render($template->subject, $values),
                CrmEmailTemplate::render($template->body_html, $values),
            ];
        }

        $subject = sprintf('Заказ %s: часть позиций не прошла контроль при сборке', $number);

        $body = sprintf(
            '<p>Здравствуйте, %s!</p>'
            .'<p>При сборке заказа %s часть позиций не прошла контроль качества, и 1С сняла их с отгрузки:</p>'
            .'%s'
            .'<p>Приношу извинения — мы подобрали варианты замены с вашими ценами. '
            .'Посмотреть и согласовать можно по ссылке (действует до %s):</p>'
            .'<p><a href="%s">Подборка замен по заказу %s</a></p>'
            .'<p>Если удобнее — просто ответьте на это письмо или позвоните, решим голосом.</p>'
            .'<p>С уважением,<br>%s</p>',
            e($values['client_name']),
            e($values['order_number']),
            $values['lines'],
            $offer->expires_at?->format('d.m.Y') ?? '',
            e($values['link']),
            e($values['order_number']),
            e($values['manager_name']),
        );

        return [$subject, $body];
    }
}
