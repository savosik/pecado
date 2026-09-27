<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Services\Crm\Finance\ReconciliationService;
use App\Services\Currency\CabinetAmountConverter;
use App\Services\CurrencyService;
use App\Services\Payments\ClientPaymentQuery;
use App\Services\Settlements\CabinetSettlementFinance;
use App\Services\SimpleCsvExporter;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Оплаты клиента в личном кабинете.
 *
 * Только чтение: платёж заводит 1С. Гейт такой же жёсткий, как у отгрузок —
 * прямое сравнение user_id, без политик: чужой платёж не должен быть виден
 * ни при каких настройках ролей.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService,
        protected ClientPaymentQuery $payments,
    ) {}

    /**
     * Список оплат. GET /cabinet/payments
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        [$query, $context] = $this->buildIndexQuery($request, $user);

        $payments = $query->paginate($context['per_page'])->withQueryString();
        $currency = $this->getUserCurrency($request);

        $payments->getCollection()->transform(fn (Payment $payment) => $this->payments->row($payment, $currency));

        return Inertia::render('User/Cabinet/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'search' => $context['search'],
                'direction' => $context['directions'],
                'company_id' => $context['company_id'] ? (string) $context['company_id'] : '',
                'date_from' => $context['date_from'],
                'date_to' => $context['date_to'],
                'amount_from' => $context['amount_from'],
                'amount_to' => $context['amount_to'],
                'sort_by' => $context['sort_by'],
                'sort_order' => $context['sort_order'],
                'per_page' => $context['per_page'],
            ],
            'directions' => ClientPaymentQuery::directionOptions(),
            'companies' => $user->companies()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (\App\Models\Company $c) => ['value' => (string) $c->id, 'label' => $c->name]),
            'exportEnabled' => (bool) config('search-cabinet.export'),
        ]);
    }

    /**
     * Карточка платежа. GET /cabinet/payments/{payment}
     */
    public function show(Request $request, Payment $payment): InertiaResponse
    {
        $user = $request->user();

        abort_unless($payment->user_id === $user->id, 403);
        abort_if($payment->isFromInternalOrganization(), 404);

        $currency = $this->getUserCurrency($request);

        return Inertia::render('User/Cabinet/Payments/Show', [
            'payment' => $this->payments->card($payment, $currency),
            'currency_code' => $currency?->code ?? 'RUB',
        ]);
    }

    /**
     * Календарь оплат. GET /cabinet/payments/calendar?month=YYYY-MM
     *
     * План, а не факт: строится по графику оплаты реализаций («Правила оплаты» 1С),
     * а не по проведённым платежам. Факт клиент смотрит списком в этом же разделе.
     */
    public function calendar(Request $request): InertiaResponse
    {
        $user = $request->user();
        $currency = $this->getUserCurrency($request);

        $month = $this->resolveMonth($request->input('month'));
        $today = Carbon::today();

        // План приходит из ленты регистра: раскладывать платежи самим сайт
        // перестал в v16.0.0, старый расчёт снят в fin-11.
        return $this->ledgerCalendar($user, $month, $today, $currency, $request->integer('company_id') ?: null);
    }

    /**
     * Строки графика клиента с данными реализации.
     *
     * Джойном, а не через связь: календарю нужны только плоские поля, а грузить
     * реализации со связями ради номера документа — лишние запросы на каждый месяц.
     */
    /**
     * Календарь на регистре взаиморасчётов.
     *
     * Форма ответа та же, что у старого расчёта: экран один, и переключение
     * источника не должно менять его разметку — иначе при включении флага
     * поедут не только цифры, но и вёрстка, и отличить одно от другого станет нельзя.
     */
    private function ledgerCalendar(User $user, Carbon $month, Carbon $today, ?Currency $currency, ?int $companyId = null): InertiaResponse
    {
        $finance = app(CabinetSettlementFinance::class);
        $data = $finance->calendar(
            $user,
            \Carbon\CarbonImmutable::parse($month->toDateString()),
            $companyId,
        );

        return Inertia::render('User/Cabinet/Payments/Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->monthLabel($month),
            'prevMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'today' => $today->toDateString(),
            'entries' => $data['entries'],
            'overdueEntries' => $data['overdue'],
            'summary' => $data['summary'],
            // Пустой список прячет селектор контрагента на фронте.
            'companies' => $finance->companiesOf($user),
            'companyId' => $companyId,
            'currencyCode' => $currency?->code ?? 'RUB',
            // Кнопка «Платёжка» у строки графика — только если платёжка открыта клиенту.
            'paymentOrdersEnabled' => PaymentOrderController::availableFor($user),
        ]);
    }

    /**
     * Акт сверки для клиента. GET /cabinet/payments/reconciliation
     *
     * Тот же документ, что менеджер видит в CRM, но по своим контрагентам
     * и только за себя. Клиент сам видит, из чего сложился долг, — это снимает
     * часть звонков менеджеру, а спорную строку он находит по номеру документа
     * без переписки.
     *
     * Выбора клиента здесь нет и быть не может: скоуп задаёт сессия.
     */
    public function reconciliation(Request $request, ReconciliationService $service): InertiaResponse
    {
        $user = $request->user();
        $period = $service->defaultPeriod();

        return Inertia::render('User/Cabinet/Payments/Reconciliation', [
            'act' => $service->act(
                client: $user,
                organizationId: $request->integer('organization_id') ?: null,
                from: (string) $request->string('date_from', $period['from']),
                to: (string) $request->string('date_to', $period['to']),
                companyId: $request->integer('company_id') ?: null,
            ),
            'organizations' => $service->organizationsOf($user),
            'companies' => $service->companiesOf($user),
            'form' => [
                'organization_id' => $request->integer('organization_id') ?: null,
                'company_id' => $request->integer('company_id') ?: null,
                'date_from' => (string) $request->string('date_from', $period['from']),
                'date_to' => (string) $request->string('date_to', $period['to']),
            ],
        ]);
    }

    /**
     * Месяц из строки YYYY-MM. Мусор в параметре — текущий месяц, а не 500.
     */
    private function resolveMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
            } catch (\Throwable) {
                // Падаем в текущий месяц ниже.
            }
        }

        return Carbon::today()->startOfMonth();
    }

    /**
     * «Август 2026». Carbon::translatedFormat зависит от локали приложения,
     * а месяцы нужны по-русски в любом окружении.
     */
    private function monthLabel(Carbon $month): string
    {
        $names = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $names[$month->month].' '.$month->year;
    }

    /**
     * Экспорт текущей выдачи. GET /cabinet/payments/export?format=csv|xlsx
     */
    public function export(Request $request, SimpleCsvExporter $csv, SimpleXlsxExporter $xlsx): StreamedResponse
    {
        abort_unless((bool) config('search-cabinet.export'), 404);

        $format = strtolower((string) $request->input('format', ''));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Допустимые форматы: csv, xlsx.');

        $user = $request->user();
        [$query] = $this->buildIndexQuery($request, $user);
        $currency = $this->getUserCurrency($request);

        $withSeller = (bool) config('erp.organizations.enabled');

        $headers = array_merge(
            ['Номер', 'Дата', 'Направление', 'Контрагент'],
            $withSeller ? ['Получатель'] : [],
            ['Номер по банку', 'Сумма', 'Валюта', 'Сумма в валюте кабинета'],
        );

        // Генератором по cursor(): выгрузка кабинета идёт потоково, держать
        // в памяти все платежи со связями дороже самой генерации файла.
        $rows = (function () use ($query, $currency, $withSeller) {
            foreach ($query->cursor() as $payment) {
                yield array_merge(
                    [
                        $payment->number,
                        $payment->date->format('Y-m-d H:i'),
                        ClientPaymentQuery::DIRECTION_LABELS[$payment->direction] ?? $payment->direction,
                        $payment->company?->name ?? '',
                    ],
                    $withSeller ? [$payment->organization?->name ?? 'Не указана'] : [],
                    [
                        $payment->bank_number ?? '',
                        round((float) $payment->amount, 2),
                        $payment->currency_code ?? 'RUB',
                        round(app(CabinetAmountConverter::class)->convert((float) $payment->amount, $payment->currency_code, $currency), 2),
                    ],
                );
            }
        })();

        $filename = 'payments-'.now()->format('Y-m-d-His');

        return $format === 'csv'
            ? $csv->stream($filename, $headers, $rows)
            : $xlsx->stream($filename, $headers, $rows, 'Оплаты');
    }

    /**
     * Выборка и контекст фильтров — общим сервисом с клиентским API v1.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder<Payment>, 1: array<string, mixed>}
     */
    private function buildIndexQuery(Request $request, User $user): array
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'direction' => $request->input('direction'),
            'company_id' => $request->integer('company_id') ?: null,
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'amount_from' => $request->input('amount_from'),
            'amount_to' => $request->input('amount_to'),
        ];

        $query = $this->payments->builder($user, $filters);

        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $this->payments->applySort($query, $sortBy, $sortOrder);

        return [$query, [
            'search' => $filters['search'],
            'directions' => $this->payments->directions($filters['direction']),
            'company_id' => $filters['company_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'amount_from' => $filters['amount_from'],
            'amount_to' => $filters['amount_to'],
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => min(max((int) $request->input('per_page', 15), 5), 100),
        ]];
    }

    private function getUserCurrency(Request $request): ?Currency
    {
        return $request->user()?->region?->currency;
    }
}
