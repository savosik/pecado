<?php

namespace App\Services\Client\Api\Operations;

use App\Models\Payment;
use App\Models\User;
use App\Services\Client\Api\CompanyContext;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Crm\Finance\ReconciliationService;
use App\Services\Currency\CabinetAmountConverter;
use App\Services\Payments\ClientPaymentQuery;
use App\Services\Settlements\CabinetSettlementFinance;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Carbon\CarbonImmutable;

/**
 * Деньги клиента: баланс, платежи, календарь оплат, акт сверки.
 *
 * Считает только регистр взаиморасчётов (`CabinetSettlementFinance`,
 * `ReconciliationService`) — свои суммы по документам запрещены. Раздел закрыт
 * гейтом FINANCE до сверки цифр с 1С (пилот по списку клиентов).
 */
class FinanceOperations implements OperationProvider
{
    public function __construct(
        private readonly CabinetSettlementFinance $finance,
        private readonly ReconciliationService $reconciliation,
        private readonly ClientPaymentQuery $payments,
        private readonly CabinetAmountConverter $amounts,
        private readonly CompanyContext $companies,
    ) {}

    public static function section(): array
    {
        return ['finance', 'Финансы'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'finance.balance', section: 'finance', method: 'GET', uri: 'finance/balance',
                summary: 'Баланс взаиморасчётов: долг, просрочка, разбивка по организациям',
                description: 'Сводка по регистру взаиморасчётов 1С (не по документам). Положительный баланс — долг клиента. '
                    .'null в data.summary — движений нет вовсе.',
                params: [],
                handler: [self::class, 'balance'],
                gate: FeatureGate::FINANCE,
            ),
            new Operation(
                id: 'finance.payments', section: 'finance', method: 'GET', uri: 'finance/payments',
                summary: 'Платежи: поступления и возвраты',
                description: 'Суммы в валюте документа и в валюте кабинета клиента. direction: in — поступление, out — возврат.',
                params: [
                    Param::list('direction', 'Направления: in, out'),
                    Param::integer('company_id', 'Контрагент клиента', rules: ['min:1']),
                    Param::string('search', 'Номер, номер по банку, УИП или назначение', rules: ['max:200']),
                    Param::string('date_from', 'С даты (YYYY-MM-DD)', rules: ['date']),
                    Param::string('date_to', 'По дату (YYYY-MM-DD)', rules: ['date']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'payments'],
                gate: FeatureGate::FINANCE,
            ),
            new Operation(
                id: 'finance.payment', section: 'finance', method: 'GET', uri: 'finance/payments/{payment}',
                summary: 'Карточка платежа',
                description: 'Реквизиты, назначение, подтверждение банком.',
                params: [Param::integer('payment', 'id платежа', true)],
                handler: [self::class, 'payment'],
                gate: FeatureGate::FINANCE,
            ),
            new Operation(
                id: 'finance.calendar', section: 'finance', method: 'GET', uri: 'finance/calendar',
                summary: 'Календарь оплат на месяц: план по графику 1С и просрочка',
                description: 'План, а не факт: строки графика оплаты реализаций («Правила оплаты» 1С). Факт — finance.payments.',
                params: [
                    Param::string('month', 'Месяц YYYY-MM (по умолчанию текущий)', rules: ['regex:/^\d{4}-\d{2}$/']),
                    Param::integer('company_id', 'Контрагент клиента', rules: ['min:1']),
                ],
                handler: [self::class, 'calendar'],
                gate: FeatureGate::FINANCE,
            ),
            new Operation(
                id: 'finance.reconciliation', section: 'finance', method: 'GET', uri: 'finance/reconciliation',
                summary: 'Акт сверки за период',
                description: 'Тот же документ, что менеджер видит в CRM, но по своим контрагентам. По умолчанию — период '
                    .'из настроек сверки; организация и контрагент — фильтры.',
                params: [
                    Param::string('date_from', 'Начало периода (YYYY-MM-DD)', rules: ['date']),
                    Param::string('date_to', 'Конец периода (YYYY-MM-DD)', rules: ['date']),
                    Param::integer('organization_id', 'Организация-продавец', rules: ['min:1']),
                    Param::integer('company_id', 'Контрагент клиента', rules: ['min:1']),
                    Param::integer('agreement_id', 'Договор (соглашение) 1С', rules: ['min:1']),
                    Param::string('currency', 'Валюта регистра (по умолчанию RUB)', rules: ['max:3']),
                ],
                handler: [self::class, 'reconciliation'],
                gate: FeatureGate::FINANCE,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function balance(User $actor, OperationInput $input): array
    {
        return Envelope::data([
            'summary' => $this->finance->summary($actor),
            'by_organization' => $this->finance->balanceByOrganization($actor),
            'companies' => $this->finance->companiesOf($actor),
        ]);
    }

    /** @return array<string, mixed> */
    public function payments(User $actor, OperationInput $input): array
    {
        $filters = $input->only(['direction', 'search', 'date_from', 'date_to']);

        if ($input->has('company_id')) {
            $filters['company_id'] = $this->companies->filterIds($actor, $input->int('company_id'))[0];
        }

        $query = $this->payments->builder($actor, $filters);
        $this->payments->applySort($query, 'date', 'desc');
        $currency = $this->amounts->currencyOf($actor);

        $paginator = $query->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (Payment $p) => $this->payments->row($p, $currency, iso: true), [
            'currency_code' => $currency?->code ?? 'RUB',
        ]);
    }

    /** @return array<string, mixed> */
    public function payment(User $actor, OperationInput $input): array
    {
        $payment = $this->payments->builder($actor)->whereKey((int) $input->int('payment'))->firstOrFail();

        return Envelope::data($this->payments->card($payment, $this->amounts->currencyOf($actor), iso: true));
    }

    /** @return array<string, mixed> */
    public function calendar(User $actor, OperationInput $input): array
    {
        $month = $input->month();
        $companyId = $input->has('company_id') ? $this->companies->filterIds($actor, $input->int('company_id'))[0] : null;

        $data = $this->finance->calendar($actor, CarbonImmutable::parse($month->toDateString()), $companyId);

        return Envelope::data($data + [
            'month' => $month->format('Y-m'),
            'companies' => $this->finance->companiesOf($actor),
        ]);
    }

    /** @return array<string, mixed> */
    public function reconciliation(User $actor, OperationInput $input): array
    {
        $period = $this->reconciliation->defaultPeriod();
        $companyId = $input->has('company_id') ? $this->companies->filterIds($actor, $input->int('company_id'))[0] : null;

        $act = $this->reconciliation->act(
            client: $actor,
            organizationId: $input->int('organization_id'),
            from: $input->string('date_from') ?? $period['from'],
            to: $input->string('date_to') ?? $period['to'],
            agreementId: $input->int('agreement_id'),
            currency: mb_strtoupper($input->string('currency') ?? 'RUB'),
            companyId: $companyId,
        );

        return Envelope::data($act, [
            'organizations' => $this->reconciliation->organizationsOf($actor),
            'companies' => $this->reconciliation->companiesOf($actor),
        ]);
    }
}
