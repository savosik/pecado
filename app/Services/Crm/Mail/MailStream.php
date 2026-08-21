<?php

namespace App\Services\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Enums\PrintedDocumentType;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationEventRegistry;
use App\Support\Crm\CrmAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Поток писем: система складывает письмо в тот же список, где лежат письма менеджера.
 *
 * Это ключевое решение эпика. Раньше существовали «уведомление», «сигнал»
 * и «доставка» — три сущности вместо одной, и разобраться в них не удалось.
 * Теперь повод (изменился заказ, выложен акт сверки, подошёл срок оплаты)
 * превращается в обычное письмо-черновик: тот же список, тот же самолётик.
 *
 * Кому оно уйдёт — решают правила-фильтры, а не место, где повод случился.
 */
class MailStream
{
    public function __construct(
        private readonly NotificationEventRegistry $registry,
        private readonly MailTagBuilder $tags,
        private readonly MailRuleEngine $rules,
    ) {}

    /**
     * Превратить повод в письмо потока.
     *
     * Возвращает null, когда письма быть не должно: поток выключен, домен
     * не включён, повод слишком стар или уже есть письмо про то же самое.
     */
    public function capture(PulseSignal $signal): ?CrmEmail
    {
        if (! $this->accepts($signal)) {
            return null;
        }

        $client = $signal->clientUserId === null
            ? null
            : User::query()->with('crmProfile')->find($signal->clientUserId);

        $data = $this->enrich($signal, $client);
        $tags = $this->tags->build($signal->eventKey, $data, $client);
        $originKey = $this->originKey($signal, $data);

        if ($this->alreadyToldAbout($originKey, $signal->eventKey)) {
            return null;
        }

        $existing = $this->findForCoalescing($originKey);

        if ($existing !== null) {
            return $this->coalesce($existing, $signal, $data, $tags);
        }

        $letter = new CrmEmail([
            'client_user_id' => $signal->clientUserId,
            'origin' => CrmEmail::ORIGIN_SYSTEM,
            'origin_event' => $signal->eventKey,
            'origin_key' => $originKey,
            'origin_data' => $data,
            'tags' => $tags,
            'to' => [],
            'subject' => $this->subject($signal, $data),
            'body_html' => $this->body($signal),
            'status' => EmailStatus::UNMATCHED->value,
        ]);

        if ($signal->subject instanceof Model) {
            $letter->related()->associate($signal->subject);
        }

        $author = $this->author($client);

        if ($author === null) {
            // Автор — обязательная колонка журнала, и подставить сюда «никого»
            // нельзя: письмо перестало бы быть видимым в списке. Молчим и пишем
            // в лог: это состояние настройки, а не ошибка данных.
            Log::warning('Поток писем: некому приписать письмо', [
                'event' => $signal->eventKey,
                'client_user_id' => $signal->clientUserId,
            ]);

            return null;
        }

        $letter->user_id = $author->id;
        $letter->reply_to = $author->email;
        $letter->save();

        $this->attachInvoice($letter, $data);

        $this->rules->apply($letter);

        return $letter->refresh();
    }

    /**
     * Метки для письма, написанного менеджером руками.
     *
     * Ручное письмо тоже часть потока: правило «всё по этому контрагенту»
     * обязано ловить и его, иначе поток был бы «общим» только на словах.
     *
     * @return array<int, string>
     */
    public function tagManualLetter(CrmEmail $letter): void
    {
        $client = $letter->client_user_id === null
            ? null
            : User::query()->with('crmProfile')->find($letter->client_user_id);

        $data = [];

        if ($client !== null) {
            $company = $this->companyOf($client);

            if ($company !== null) {
                $data['company_name'] = (string) ($company->name ?: $company->legal_name);
                $data['company_tax_id'] = $company->tax_id;
            }
        }

        $letter->tags = $this->tags->forManualLetter($client, $data);
        $letter->saveQuietly();
    }

    /**
     * Собирает ли система письма по этому поводу.
     */
    private function accepts(PulseSignal $signal): bool
    {
        if (! config('mail_stream.enabled')) {
            return false;
        }

        if (! $this->registry->exists($signal->eventKey)) {
            return false;
        }

        $domain = explode('.', $signal->eventKey)[0];

        if (! config('mail_stream.domains.'.$domain, false)) {
            return false;
        }

        // Возрастной ценз. Первичная выгрузка истории из 1С даёт тысячи поводов
        // пачкой; без этого предохранителя они залили бы поток, а при включённой
        // автоотправке — и почтовые ящики клиентов.
        $maxAge = (int) config('mail_stream.max_age_minutes', 180);

        return $signal->occurredAtOrNow()->greaterThanOrEqualTo(now()->subMinutes($maxAge));
    }

    /**
     * Ключ склейки: что считается «тем же самым письмом».
     *
     * 1С правит заказ построчно, и без склейки серия правок дала бы десяток
     * писем об одном изменении. Для просрочки в ключ входит ступень: пока
     * просрочка не перешла через 30/60/90, нового письма не появляется.
     *
     * @param  array<string, mixed>  $data
     */
    private function originKey(PulseSignal $signal, array $data): string
    {
        $parts = [$signal->eventKey, 'c'.($signal->clientUserId ?? 0)];

        if (filled($data['order_number'] ?? null)) {
            $parts[] = 'o'.$data['order_number'];
        }

        if (filled($data['document_number'] ?? null)) {
            $parts[] = 'd'.$data['document_number'];
        }

        if (filled($data['shipment_number'] ?? null)) {
            $parts[] = 's'.$data['shipment_number'];
        }

        if (str_starts_with($signal->eventKey, 'finance.overdue')) {
            $parts[] = 'step'.$this->overdueStep((int) ($data['days_overdue'] ?? 0));
        }

        return implode(':', $parts);
    }

    private function overdueStep(int $days): int
    {
        $step = 0;

        foreach ((array) config('mail_stream.steps.просрочка', []) as $candidate) {
            if ($days >= (int) $candidate) {
                $step = (int) $candidate;
            }
        }

        return $step;
    }

    /**
     * Уже говорили об этом же.
     *
     * Просрочка — состояние, а не событие: она длится месяцами, и письмо про неё
     * должно появляться на переходах (возникла, выросла, погасилась), а не каждый
     * день. Ступень входит в ключ, поэтому переход через 30/60/90 даёт новое письмо,
     * а неизменное состояние — не даёт ничего.
     *
     * Проверяется по самому потоку, а не по внешнему состоянию: письмо и есть
     * запись о том, что клиенту уже сказали.
     */
    private function alreadyToldAbout(string $originKey, string $eventKey): bool
    {
        if (! str_starts_with($eventKey, 'finance.')) {
            return false;
        }

        $days = (int) config('mail_stream.finance_repeat_days', 14);

        return CrmEmail::query()
            ->where('origin_key', $originKey)
            ->where('created_at', '>=', now()->subDays($days))
            ->exists();
    }

    /**
     * Незакрытое письмо про то же самое в окне склейки.
     */
    private function findForCoalescing(string $originKey): ?CrmEmail
    {
        $window = (int) config('mail_stream.coalesce_minutes', 30);

        return CrmEmail::query()
            ->where('origin_key', $originKey)
            ->whereIn('status', [EmailStatus::DRAFT->value, EmailStatus::UNMATCHED->value])
            ->where('created_at', '>=', now()->subMinutes($window))
            ->latest('id')
            ->first();
    }

    /**
     * Дописать повторный повод в уже существующее письмо.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tags
     */
    private function coalesce(CrmEmail $letter, PulseSignal $signal, array $data, array $tags): CrmEmail
    {
        $letter->origin_data = array_merge((array) $letter->origin_data, $data);
        $letter->tags = array_values(array_unique(array_merge($letter->tagList(), $tags)));
        $letter->body_html = $this->body($signal);
        $letter->subject = $this->subject($signal, $data);
        $letter->save();

        $this->rules->apply($letter);

        return $letter->refresh();
    }

    /**
     * Тема письма: заготовка события с раскрытыми подстановками.
     *
     * @param  array<string, mixed>  $data
     */
    private function subject(PulseSignal $signal, array $data): string
    {
        $event = $this->registry->get($signal->eventKey);
        $template = $event?->defaultSubject() ?: $this->registry->label($signal->eventKey);

        $subject = CrmEmailTemplate::render($template, [
            'order_number' => (string) ($data['order_number'] ?? $signal->view['entity_label'] ?? ''),
            'status_label' => (string) ($data['status_label'] ?? ''),
            'client_name' => (string) ($data['client_name'] ?? ''),
            'company_name' => (string) ($data['company_name'] ?? ''),
            'document_title' => (string) ($data['document_title'] ?? ''),
            'amount' => (string) ($data['total'] ?? $data['amount'] ?? ''),
        ]);

        return trim($subject) !== '' ? $subject : (string) ($signal->view['title'] ?? 'Уведомление');
    }

    /**
     * Тело письма — тот же фрагмент, что и у письма менеджера, поэтому
     * менеджер может его дописать перед отправкой.
     */
    private function body(PulseSignal $signal): string
    {
        return trim(view('mail.stream.body', [
            'title' => $signal->view['title'] ?? null,
            'body' => $signal->view['body'] ?? null,
            'entityLabel' => $signal->view['entity_label'] ?? null,
            'rows' => $signal->view['rows'] ?? [],
            'url' => $signal->view['url'] ?? null,
        ])->render());
    }

    /**
     * Приложить счёт к письму о сроке оплаты.
     *
     * Прямая просьба заказчика: «присылать платёжку». Тяжёлый файл письмом
     * не уходит — почтовые серверы отбивают такие письма, и клиент не получил бы
     * ничего; вместо файла в тексте появляется ссылка на кабинет.
     *
     * @param  array<string, mixed>  $data
     */
    private function attachInvoice(CrmEmail $letter, array $data): void
    {
        if ($letter->origin_event !== 'finance.payment_due_soon') {
            return;
        }

        $shipmentId = (int) ($data['shipment_id'] ?? 0);

        if ($shipmentId <= 0) {
            return;
        }

        $invoice = PrintedDocument::query()
            ->where('shipment_id', $shipmentId)
            ->where('file_status', PrintedDocument::FILE_STORED)
            ->where('type', PrintedDocumentType::INVOICE)
            ->latest('id')
            ->first();

        if ($invoice === null || blank($invoice->path)) {
            return;
        }

        $limit = (int) config('mail_stream.max_attachment_bytes', 5 * 1024 * 1024);

        if ((int) ($invoice->size_bytes ?? 0) > $limit) {
            $letter->body_html .= sprintf(
                '<p style="font-size:14px;color:#333333;margin:14px 0 0;">Счёт доступен в личном кабинете: <a href="%s">раздел «Документы»</a>.</p>',
                url(route('cabinet.documents.index', [], false)),
            );
            $letter->save();

            return;
        }

        try {
            $letter
                ->addMediaFromDisk($invoice->path, (string) ($invoice->disk ?: config('filesystems.default')))
                ->preservingOriginal()
                ->usingFileName($invoice->original_filename ?: 'schet.pdf')
                ->toMediaCollection(CrmAttachments::COLLECTION);
        } catch (\Throwable $exception) {
            // Отсутствие счёта не повод не отправить напоминание об оплате.
            Log::warning('Поток писем: счёт не приложился', [
                'letter' => $letter->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Числа повода плюс данные клиента — по ним работают условия правил.
     *
     * Считаются здесь, один раз: иначе каждый вызывающий обязан был бы помнить
     * про ИНН и менеджера, и рано или поздно забыл бы.
     *
     * @return array<string, mixed>
     */
    private function enrich(PulseSignal $signal, ?User $client): array
    {
        $data = $signal->data;
        $data['event'] = $signal->eventKey;
        $data['event_domain'] = explode('.', $signal->eventKey)[0];

        if ($client !== null) {
            $data['client_user_id'] = $client->id;
            $data['client_name'] = (string) $client->display_name;
            $data['client_city'] = $client->city;
            $data['client_status'] = $client->crmProfile?->lifecycle_status?->label();
            $data['client_business'] = $client->crmProfile?->business_type?->label();
            $data['client_notes'] = $client->crmProfile?->notes_md;
            $data['manager_id'] = $client->personal_manager_id;
        }

        $company = $signal->companyId === null
            ? $this->companyOf($client)
            : Company::query()->withoutGlobalScopes()->find($signal->companyId);

        if ($company !== null) {
            $data['company_id'] = $company->id;
            $data['company_name'] = (string) ($company->name ?: $company->legal_name);
            $data['company_tax_id'] = $company->tax_id;
        }

        return array_filter($data, fn ($value): bool => $value !== null);
    }

    private function companyOf(?User $client): ?Company
    {
        if ($client === null) {
            return null;
        }

        return Company::query()->withoutGlobalScopes()->where('user_id', $client->id)->first();
    }

    /**
     * Кому приписать письмо в списке.
     *
     * Персональный менеджер клиента: письмо про его клиента должно попасть
     * в его рабочую папку, а обратный адрес — быть его личным, чтобы ответ
     * клиента пришёл живому человеку, а не в общий ящик.
     */
    private function author(?User $client): ?User
    {
        if ($client?->personal_manager_id !== null) {
            $manager = PersonalManager::query()->with('user')->find($client->personal_manager_id);

            if ($manager?->user !== null && filled($manager->user->email)) {
                return $manager->user;
            }
        }

        $fallbackId = (int) config('mail_stream.fallback_author_id', 0);

        if ($fallbackId > 0) {
            return User::query()->find($fallbackId);
        }

        return User::query()->role('sales-head')->first()
            ?? User::query()->role('sales-manager')->first();
    }
}
