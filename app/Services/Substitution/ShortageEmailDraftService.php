<?php

namespace App\Services\Substitution;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\EntitySubscription;
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
                'to' => $this->defaultRecipients($offer),
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
     * @return array{subject: string, body_html: string, to: list<string>, email_id: int|null, editable: bool}
     */
    public function draftFor(SubstitutionOffer $offer): array
    {
        $offer->loadMissing(['draftEmail', 'user', 'manager', 'order.items']);

        $email = $offer->draftEmail;

        if ($email !== null && $email->status->isEditable()) {
            return [
                'subject' => (string) $email->subject,
                'body_html' => (string) $email->body_html,
                'to' => array_values($email->to ?? []),
                'email_id' => $email->id,
                'editable' => true,
            ];
        }

        [$subject, $body] = $this->render($offer);

        return [
            'subject' => $subject,
            'body_html' => $body,
            'to' => $this->defaultRecipients($offer),
            'email_id' => null,
            'editable' => $offer->user !== null && filled($offer->user->email),
        ];
    }

    /**
     * Перегенерация текста после правки состава кандидатов: письмо в карточке
     * сразу отражает актуальную подборку, сохранённый черновик обновляется.
     * Ручные правки текста при этом затираются — это осознанная цена
     * актуальности: состав замен в письме важнее формулировок.
     *
     * @return array{subject: string, body_html: string, to: list<string>, email_id: int|null, editable: bool}
     */
    public function refreshDraft(SubstitutionOffer $offer): array
    {
        $offer->loadMissing(['draftEmail', 'user', 'manager', 'order.items']);
        $offer->load(['items.product', 'items.productDefect.product']);

        [$subject, $body] = $this->render($offer);

        $email = $offer->draftEmail;

        if ($email !== null && $email->status->isEditable()) {
            $email->update(['subject' => $subject, 'body_html' => $body]);
        }

        return $this->draftFor($offer);
    }

    /**
     * Получатели по умолчанию: основной email клиента плюс адреса из его
     * активных email-подписок раздела «Заказы», ждущих событие замены.
     *
     * @return list<string>
     */
    public function defaultRecipients(SubstitutionOffer $offer): array
    {
        $client = $offer->user;

        if ($client === null) {
            return [];
        }

        $subscribed = $client->subscriptions()
            ->where('section', 'orders')
            ->where('channel', 'email')
            ->where('is_active', true)
            ->get()
            ->filter(fn (EntitySubscription $subscription) => $subscription->wantsEvent('substitution_offered'))
            ->pluck('destination');

        return collect([$client->email])
            ->concat($subscribed)
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Подсказки адресов для поля «Кому»: клиент, его подписки, контакты из
     * анкеты партнёра, менеджер и сам актор — всё, что менеджеру может
     * понадобиться дописать в письмо без копания по карточкам.
     *
     * @return list<array{email: string, label: string}>
     */
    public function recipientOptions(SubstitutionOffer $offer, User $actor): array
    {
        $offer->loadMissing(['user.crmProfile', 'manager']);

        $client = $offer->user;
        $options = collect();

        $push = function (?string $address, string $label) use ($options): void {
            $address = mb_strtolower(trim((string) $address));

            if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL) || $options->has($address)) {
                return;
            }

            $options->put($address, ['email' => $address, 'label' => $label]);
        };

        $push($client?->email, 'Основной email клиента');

        foreach ($this->defaultRecipients($offer) as $address) {
            $push($address, 'Подписка на изменения заказов');
        }

        // Анкета партнёра хранит контакты свободным текстом — вылавливаем адреса.
        $profile = $client?->crmProfile;
        $profileContacts = [
            'ЛПР из анкеты' => $profile?->decision_maker_contact,
            'Бухгалтер из анкеты' => $profile?->accountant_contact,
            'Собственник из анкеты' => $profile?->owner_contact,
        ];

        foreach ($profileContacts as $label => $contact) {
            if (blank($contact)) {
                continue;
            }

            preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u', (string) $contact, $matches);

            foreach ($matches[0] as $address) {
                $push($address, $label);
            }
        }

        $push($offer->manager?->email, 'Персональный менеджер');
        $push($actor->email, 'Ваш адрес');

        return $options->values()->all();
    }

    /**
     * Отправка подборки из карточки: правки менеджера ложатся в черновик,
     * черновик уходит через общий контур CRM-писем (очередь, журнал, история).
     *
     * @param  list<string>  $to  адреса из формы; пустой список = получатели по умолчанию
     * @return array{ok: bool, message: string|null}
     */
    public function send(SubstitutionOffer $offer, User $actor, string $subject, string $bodyHtml, array $to = []): array
    {
        if (! $this->emails->outboundEnabled()) {
            return [
                'ok' => false,
                'message' => 'Отправка писем из CRM выключена администратором (MAIL_FEATURE_CRM_OUTBOUND).',
            ];
        }

        $offer->loadMissing(['draftEmail', 'user', 'manager', 'order']);

        $recipients = collect($to)
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            $recipients = $this->defaultRecipients($offer);
        }

        if ($recipients === []) {
            return ['ok' => false, 'message' => 'Не указан ни один получатель, а у клиента нет email — письмо отправить некому.'];
        }

        $email = $offer->draftEmail;

        if ($email === null || ! $email->status->isEditable()) {
            $email = CrmEmail::create([
                'user_id' => $offer->manager?->id ?? $actor->id,
                'related_type' => $offer->order->getMorphClass(),
                'related_id' => $offer->order_id,
                'to' => $recipients,
                'reply_to' => ($offer->manager ?? $actor)->email,
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'status' => EmailStatus::DRAFT,
            ]);

            $offer->update(['crm_email_id' => $email->id]);
        } else {
            $email->update([
                'to' => $recipients,
                'subject' => $subject !== '' ? $subject : $email->subject,
                'body_html' => $bodyHtml !== '' ? $bodyHtml : $email->body_html,
            ]);
        }

        try {
            $this->emails->send($email);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->notifySubscribers($offer);

        return ['ok' => true, 'message' => null];
    }

    /**
     * Блок «подобрана замена» подписчикам раздела «Заказы» — вдобавок к
     * письму-извинению, которое первично и уходит и неподписанным.
     */
    private function notifySubscribers(SubstitutionOffer $offer): void
    {
        $order = $offer->order;

        if ($order === null || blank($order->user_id)) {
            return;
        }

        $number = $order->erp_number ?: $order->number;

        \App\Events\EntityChanged::dispatch(new \App\Subscriptions\EntityChangeNotice(
            section: 'orders',
            ownerUserId: (int) $order->user_id,
            title: sprintf('Подобрана замена по заказу %s — Pecado.ru', $number),
            body: 'По отменённым при сборке позициям подобрана замена — подборка отправлена на вашу почту.',
            url: url(route('cabinet.orders.show', $order, false)),
            entityLabel: "Заказ {$number}",
            rows: [[
                'type' => 'note',
                'text' => 'Персональный менеджер отправил подборку замен по недобору. Ссылка на согласование — в отдельном письме.',
            ]],
            event: 'substitution_offered',
        ));
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

        // Видимые клиенту кандидаты — прямо в тексте письма, по строкам недобора:
        // менеджер добавил замену в карточке — письмо сразу её показывает.
        $offer->loadMissing(['items.product', 'items.productDefect.product']);
        $candidatesBySource = $offer->items
            ->whereNull('removed_by_manager_at')
            ->groupBy('source_order_item_id');

        $linesHtml = $lines->isEmpty()
            ? ''
            : '<ul>'.$lines->map(function ($item) use ($candidatesBySource) {
                $line = sprintf('<li>%s — %d шт.', e($item->name), $item->quantity);

                $candidates = ($candidatesBySource[$item->id] ?? collect())
                    ->map(function ($candidate) {
                        $product = $candidate->product ?? $candidate->productDefect?->product;

                        if ($product === null) {
                            return null;
                        }

                        return sprintf(
                            '<li>%s — %s ₽</li>',
                            e($product->name),
                            number_format((float) $candidate->price_snapshot, 0, ',', ' '),
                        );
                    })
                    ->filter();

                if ($candidates->isNotEmpty()) {
                    $line .= '<br>Предлагаем на замену:<ul>'.$candidates->implode('').'</ul>';
                }

                return $line.'</li>';
            })->implode('').'</ul>';

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
