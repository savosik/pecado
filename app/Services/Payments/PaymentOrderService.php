<?php

namespace App\Services\Payments;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\ContractStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\Contract;
use App\Models\CrmEmail;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\CrmEmailService;
use App\Support\Crm\CrmAttachments;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Платёжка «бери и плати» (карточка pay-01).
 *
 * Пара «контрагент клиента × наше юрлицо» из регистра взаиморасчётов, сумма
 * по сценарию (просрочка / весь долг / документ / своя), реквизиты из
 * `organizations` и `company_bank_accounts`. Своей таблицы нет намеренно —
 * платёжка не документ учёта, а удобство; учёт ведёт 1С.
 */
class PaymentOrderService
{
    public const SCENARIOS = [
        'overdue' => 'Погасить просрочку',
        'all' => 'Погасить весь долг',
        'document' => 'Оплатить документ',
        'custom' => 'Своя сумма',
    ];

    /** Банки режут назначение платежа на 210 символах. */
    private const PURPOSE_LIMIT = 210;

    public function __construct(private readonly CrmEmailService $emails) {}

    /**
     * Что можно оплатить: пары с непогашенными строками, суммы по сценариям,
     * документы, реквизиты и бухгалтеры из адресной книги.
     *
     * @return array<string, mixed>
     */
    public function options(User $client): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $lines = SettlementEntry::query()
            ->outstanding()
            ->where('user_id', $client->getKey())
            ->whereNotNull('company_id')
            ->whereNotNull('organization_id')
            ->where(fn ($query) => $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order'))
            ->orderBy('date')
            ->get();

        $balances = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('user_id', $client->getKey())
            ->whereNotNull('company_id')
            ->whereNotNull('organization_id')
            ->groupBy('company_id', 'organization_id')
            ->selectRaw('company_id, organization_id, SUM(COALESCE(amount_rub, amount)) as balance')
            ->get()
            ->keyBy(fn (object $row): string => $row->company_id.':'.$row->organization_id);

        $pairKeys = $lines->map(fn (SettlementEntry $line): string => $line->company_id.':'.$line->organization_id)
            ->merge($balances->keys())
            ->unique()
            ->values();

        $companies = Company::withTrashed()->whereIn('id', $pairKeys->map(fn (string $key): int => (int) explode(':', $key)[0]))->get()->keyBy('id');
        $organizations = Organization::query()->where('is_stub', false)->whereIn('id', $pairKeys->map(fn (string $key): int => (int) explode(':', $key)[1]))->get()->keyBy('id');

        $pairs = [];

        foreach ($pairKeys as $key) {
            [$companyId, $organizationId] = array_map('intval', explode(':', $key));
            $company = $companies->get($companyId);
            $organization = $organizations->get($organizationId);

            if ($company === null || $organization === null) {
                continue;
            }

            $pairLines = $lines->filter(fn (SettlementEntry $line): bool => (int) $line->company_id === $companyId && (int) $line->organization_id === $organizationId);
            $overdue = round((float) $pairLines->filter(fn (SettlementEntry $line): bool => $line->date->toDateString() < $today)->sum(fn (SettlementEntry $line): float => (float) $line->unsettled_amount), 2);
            $debt = round(max(0.0, -1 * (float) ($balances->get($key)?->balance ?? 0.0)), 2);

            if ($overdue <= 0 && $debt <= 0 && $pairLines->isEmpty()) {
                continue;
            }

            $pairs[] = [
                'key' => $key,
                'company_id' => $companyId,
                'company_name' => $company->name,
                'organization_id' => $organizationId,
                'organization_name' => $organization->name,
                'overdue' => $overdue,
                'debt' => $debt,
                'requisites_ok' => filled($organization->account_number) && filled($organization->bank_bik),
                'documents' => $pairLines->map(fn (SettlementEntry $line): array => [
                    'id' => (int) $line->id,
                    'number' => (string) ($line->document_number ?: $line->document_label),
                    'date' => $line->document_date?->format('d.m.Y'),
                    'due' => $line->date?->format('d.m.Y'),
                    'amount' => (float) $line->unsettled_amount,
                    'overdue' => $line->date->toDateString() < $today,
                ])->values()->all(),
            ];
        }

        return [
            'pairs' => $pairs,
            'scenarios' => collect(self::SCENARIOS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'contacts' => $this->accountants($client),
        ];
    }

    public function build(
        User $client,
        int $companyId,
        int $organizationId,
        string $scenario,
        ?int $entryId = null,
        ?float $amount = null,
        ?CarbonImmutable $today = null,
    ): PaymentOrder {
        if (! array_key_exists($scenario, self::SCENARIOS)) {
            throw new InvalidArgumentException('Неизвестный сценарий платёжки.');
        }

        $today ??= CarbonImmutable::today();

        $company = Company::withTrashed()->where('user_id', $client->getKey())->find($companyId)
            ?? throw new InvalidArgumentException('Контрагент не принадлежит партнёру.');
        $organization = Organization::query()->where('is_stub', false)->find($organizationId)
            ?? throw new InvalidArgumentException('Наше юрлицо не найдено.');

        if (blank($organization->account_number) || blank($organization->bank_bik)) {
            throw new RuntimeException(sprintf('У юрлица «%s» не заполнены банковские реквизиты — платёжку собрать нельзя.', $organization->name));
        }

        $lines = SettlementEntry::query()
            ->outstanding()
            ->where('user_id', $client->getKey())
            ->where('company_id', $companyId)
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order'))
            ->orderBy('date')
            ->get();

        [$sum, $documents] = match ($scenario) {
            'overdue' => $this->fromLines($lines->filter(fn (SettlementEntry $line): bool => $line->date->toDateString() < $today->toDateString())),
            'all' => $this->allDebt($client, $companyId, $organizationId, $lines),
            'document' => $this->fromLines($lines->filter(fn (SettlementEntry $line): bool => (int) $line->id === (int) $entryId)),
            'custom' => [round((float) $amount, 2), $this->describe($lines)],
        };

        if ($sum <= 0.0) {
            throw new RuntimeException('По этому сценарию платить нечего.');
        }

        $account = $company->bankAccounts()->orderByDesc('is_primary')->orderBy('id')->first();
        $contract = $this->contractFor($companyId, $organizationId, $today);

        return new PaymentOrder(
            number: (string) $company->getKey().'-'.$today->format('Ymd'),
            date: $today,
            scenario: $scenario,
            scenarioLabel: self::SCENARIOS[$scenario],
            amount: $sum,
            purpose: $this->purpose($documents, $sum, $scenario, $contract),
            payer: [
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'tax_code' => $company->tax_code,
                'bank_name' => $account?->bank_name,
                'bank_bik' => $account?->bank_bik,
                'correspondent_account' => $account?->correspondent_account,
                'account_number' => $account?->account_number,
            ],
            payee: [
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'tax_id' => $organization->tax_id,
                'tax_code' => $organization->tax_code,
                'bank_name' => $organization->bank_name,
                'bank_bik' => $organization->bank_bik,
                'correspondent_account' => $organization->correspondent_account,
                'account_number' => $organization->account_number,
            ],
            documents: $documents,
            companyId: $companyId,
            organizationId: $organizationId,
            contract: $contract,
        );
    }

    /**
     * Действующий договор пары контрагент × наше юрлицо из реестра: подписан,
     * не расторгнут, срок не истёк. Несколько — берём самый свежий.
     *
     * @return array{id: int, number: string, date: ?string, label: string}|null
     */
    private function contractFor(int $companyId, int $organizationId, CarbonImmutable $today): ?array
    {
        $contract = Contract::query()
            ->where('company_id', $companyId)
            ->where('organization_id', $organizationId)
            ->where('status', ContractStatus::SIGNED->value)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today->toDateString()))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($contract === null || blank($contract->number)) {
            return null;
        }

        $date = $contract->date ?? $contract->signed_at;

        return [
            'id' => (int) $contract->getKey(),
            'number' => (string) $contract->number,
            'date' => $date?->format('d.m.Y'),
            'label' => 'Договор № '.$contract->number.($date ? ' от '.$date->format('d.m.Y') : ''),
        ];
    }

    /**
     * PDF платёжного поручения с QR — DejaVu Sans есть в dompdf и знает кириллицу.
     */
    public function pdf(PaymentOrder $order): string
    {
        return Pdf::loadView('payments.order', [
            'order' => $order,
            'qr' => $this->qrDataUri($order),
        ])
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false)
            ->output();
    }

    public function qrDataUri(PaymentOrder $order): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 5,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($order->qrPayload());
    }

    /**
     * Файл обмена с клиент-банком (1CClientBankExchange 1.03, Windows-1251) —
     * бухгалтер загружает и платит двумя кликами.
     */
    public function clientBankExchange(PaymentOrder $order): string
    {
        $date = $order->date->format('d.m.Y');
        $payerTitle = trim(($order->payer['tax_id'] ? 'ИНН '.$order->payer['tax_id'].' ' : '').($order->payer['legal_name'] ?: $order->payer['name']));
        $payeeTitle = trim(($order->payee['tax_id'] ? 'ИНН '.$order->payee['tax_id'].' ' : '').($order->payee['legal_name'] ?: $order->payee['name']));

        $rows = [
            '1CClientBankExchange',
            'ВерсияФормата=1.03',
            'Кодировка=Windows',
            'Отправитель=Pecado.ru',
            'Получатель=',
            'ДатаСоздания='.$date,
            'ВремяСоздания='.now()->format('H:i:s'),
            'ДатаНачала='.$date,
            'ДатаКонца='.$date,
            'РасчСчет='.($order->payer['account_number'] ?? ''),
            'СекцияДокумент=Платежное поручение',
            'Номер='.preg_replace('/\D+/', '', $order->number),
            'Дата='.$date,
            'Сумма='.number_format($order->amount, 2, '.', ''),
            'ПлательщикСчет='.($order->payer['account_number'] ?? ''),
            'Плательщик='.$payerTitle,
            'ПлательщикИНН='.($order->payer['tax_id'] ?? ''),
            'ПлательщикКПП='.($order->payer['tax_code'] ?? ''),
            'Плательщик1='.($order->payer['legal_name'] ?: $order->payer['name']),
            'ПлательщикРасчСчет='.($order->payer['account_number'] ?? ''),
            'ПлательщикБанк1='.($order->payer['bank_name'] ?? ''),
            'ПлательщикБИК='.($order->payer['bank_bik'] ?? ''),
            'ПлательщикКорсчет='.($order->payer['correspondent_account'] ?? ''),
            'ПолучательСчет='.$order->payee['account_number'],
            'Получатель='.$payeeTitle,
            'ПолучательИНН='.($order->payee['tax_id'] ?? ''),
            'ПолучательКПП='.($order->payee['tax_code'] ?? ''),
            'Получатель1='.($order->payee['legal_name'] ?: $order->payee['name']),
            'ПолучательРасчСчет='.$order->payee['account_number'],
            'ПолучательБанк1='.($order->payee['bank_name'] ?? ''),
            'ПолучательБИК='.$order->payee['bank_bik'],
            'ПолучательКорсчет='.($order->payee['correspondent_account'] ?? ''),
            'ВидПлатежа=',
            'ВидОплаты=01',
            'Очередность=5',
            'НазначениеПлатежа='.$order->purpose,
            'КонецДокумента',
            'КонецФайла',
        ];

        $text = implode("\r\n", $rows)."\r\n";

        return (string) (mb_convert_encoding($text, 'Windows-1251', 'UTF-8') ?: $text);
    }

    /**
     * Отправить платёжку бухгалтеру письмом из CRM с двумя вложениями.
     * Автор — менеджер партнёра (reply-to живому человеку), инициатор может
     * быть и клиентом из кабинета, и менеджером.
     */
    public function send(User $client, PaymentOrder $order, string $email, bool $saveContact, ?User $actor = null): CrmEmail
    {
        $author = $actor?->isStaff() ? $actor : $this->authorFor($client);

        if ($author === null) {
            throw new RuntimeException('Некому отправить письмо: у партнёра нет менеджера с почтой.');
        }

        $letter = $this->emails->createDraft($author, [
            'to' => [$email],
            'subject' => sprintf('Платёжное поручение на %s ₽ — %s', $order->amountFormatted(), $order->payee['name']),
            'body_html' => view('payments.order-email', ['order' => $order, 'client' => $client])->render(),
        ], $client);

        $letter->addMediaFromString($this->pdf($order))
            ->usingFileName($order->fileStem().'.pdf')
            ->toMediaCollection(CrmAttachments::COLLECTION);
        $letter->addMediaFromString($this->clientBankExchange($order))
            ->usingFileName($order->fileStem().'.txt')
            ->toMediaCollection(CrmAttachments::COLLECTION);

        if ($saveContact) {
            $this->rememberAccountant($client, $order->companyId, $email, $actor ?? $client);
        }

        return $this->emails->send($letter);
    }

    /**
     * @param  Collection<int, SettlementEntry>  $lines
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function fromLines(Collection $lines): array
    {
        $documents = $this->describe($lines);

        return [round((float) array_sum(array_column($documents, 'amount')), 2), $documents];
    }

    /**
     * Весь долг пары — сальдо регистра; документы перечисляются для назначения.
     *
     * @param  Collection<int, SettlementEntry>  $lines
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function allDebt(User $client, int $companyId, int $organizationId, Collection $lines): array
    {
        $balance = (float) DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('user_id', $client->getKey())
            ->where('company_id', $companyId)
            ->where('organization_id', $organizationId)
            ->sum(DB::raw('COALESCE(amount_rub, amount)'));

        $debt = round(max(0.0, -1 * $balance), 2);
        $documents = $this->describe($lines);

        // Регистр может ещё не знать сальдо (первые строки пришли планом) —
        // тогда долг равен сумме непогашенных строк.
        if ($debt <= 0.0) {
            $debt = round((float) array_sum(array_column($documents, 'amount')), 2);
        }

        return [$debt, $documents];
    }

    /**
     * @param  Collection<int, SettlementEntry>  $lines
     * @return list<array{id: int, number: string, date: ?string, due: ?string, amount: float, overdue: bool}>
     */
    private function describe(Collection $lines): array
    {
        $today = CarbonImmutable::today()->toDateString();

        return $lines->map(fn (SettlementEntry $line): array => [
            'id' => (int) $line->id,
            'number' => (string) ($line->document_number ?: $line->document_label),
            'date' => $line->document_date?->format('d.m.Y'),
            'due' => $line->date?->format('d.m.Y'),
            'amount' => (float) $line->unsettled_amount,
            'overdue' => $line->date->toDateString() < $today,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     */
    /**
     * Назначение платежа. Есть действующий договор — ссылаемся на него,
     * документы идут уточнением; нет — только документы. Список документов
     * режется под лимит банка, договор и сумма остаются всегда.
     *
     * @param  list<array<string, mixed>>  $documents
     * @param  array{number: string, date: ?string, label: string}|null  $contract
     */
    private function purpose(array $documents, float $sum, string $scenario, ?array $contract = null): string
    {
        $refs = array_map(
            static fn (array $document): string => '№ '.$document['number'].($document['date'] ? ' от '.$document['date'] : ''),
            $documents,
        );

        if ($contract !== null) {
            $prefix = 'Оплата по договору № '.$contract['number'].($contract['date'] ? ' от '.$contract['date'] : '');
            $docsWord = match ($scenario) {
                'overdue' => ' за товар по просроченным документам',
                'document' => ' за товар по документу',
                'custom' => ' (предоплата)',
                default => ' за товар по документам',
            };
            $withDocs = static fn (array $list, bool $more): string => $list === [] || $scenario === 'custom'
                ? $prefix.($scenario === 'custom' ? $docsWord : '').'.'
                : $prefix.$docsWord.' '.implode(', ', $list).($more ? ' и др.' : '').'.';
        } else {
            $prefix = match ($scenario) {
                'overdue' => 'Оплата просроченной задолженности по документам',
                'all' => 'Оплата задолженности по документам',
                'document' => 'Оплата по документу',
                default => 'Оплата за товар',
            };
            $withDocs = static fn (array $list, bool $more): string => $list === []
                ? $prefix.'.'
                : $prefix.' '.implode(', ', $list).($more ? ' и др.' : '').'.';
        }

        $tail = sprintf(' Сумма %s руб. НДС — по счёту.', number_format($sum, 2, ',', ' '));
        $list = $refs;
        $text = $withDocs($list, false).$tail;

        // Не влезает — режем список документов, а не договор и сумму.
        while (mb_strlen($text) > self::PURPOSE_LIMIT && $list !== []) {
            array_pop($list);
            $text = $withDocs($list, $list !== []).$tail;
        }

        return mb_substr($text, 0, self::PURPOSE_LIMIT);
    }

    /**
     * @return list<array{id: int, name: string, email: string, role: string, company_id: ?int}>
     */
    private function accountants(User $client): array
    {
        return Contact::query()
            ->with('links')
            ->deliverable()
            ->where('client_user_id', $client->getKey())
            ->get()
            ->map(function (Contact $contact): array {
                $roleOf = static fn (ContactLink $link): ?ContactRole => $link->role instanceof ContactRole
                    ? $link->role
                    : ContactRole::tryFrom((string) $link->role);
                $accountant = $contact->links->first(fn (ContactLink $link): bool => $roleOf($link) === ContactRole::ACCOUNTANT);
                $link = $accountant ?? $contact->links->first();

                return [
                    'id' => (int) $contact->getKey(),
                    'name' => (string) $contact->full_name,
                    'email' => (string) $contact->email,
                    'role' => $link ? ($roleOf($link)?->label() ?? '') : '',
                    'is_accountant' => $accountant !== null,
                    'company_id' => $link && $link->subject_type === Company::class ? (int) $link->subject_id : null,
                ];
            })
            ->sortByDesc('is_accountant')
            ->values()
            ->all();
    }

    private function rememberAccountant(User $client, int $companyId, string $email, User $by): void
    {
        $exists = Contact::query()
            ->where('client_user_id', $client->getKey())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($exists) {
            return;
        }

        $company = Company::withTrashed()->find($companyId);

        $contact = new Contact([
            'full_name' => 'Бухгалтерия '.($company?->name ?? $client->display_name),
            'email' => $email,
            'is_active' => true,
        ]);
        $contact->client_user_id = $client->getKey();
        $contact->source = $by->isStaff() ? ContactSource::MANUAL : ContactSource::SELF;
        $contact->created_by_user_id = $by->getKey();
        $contact->updated_by_user_id = $by->getKey();
        $contact->save();

        ContactLink::query()->create([
            'contact_id' => $contact->getKey(),
            'subject_type' => Company::class,
            'subject_id' => $companyId,
            'role' => ContactRole::ACCOUNTANT->value,
            'client_user_id' => $client->getKey(),
            'source' => $contact->source,
            'created_by_user_id' => $by->getKey(),
        ]);
    }

    private function authorFor(User $client): ?User
    {
        if ($client->personal_manager_id !== null) {
            $manager = PersonalManager::query()->with('user')->find($client->personal_manager_id);

            if ($manager?->user !== null && filled($manager->user->email)) {
                return $manager->user;
            }
        }

        $fallbackId = (int) config('mail_stream.fallback_author_id', 0);

        return $fallbackId > 0 ? User::query()->find($fallbackId) : null;
    }
}
