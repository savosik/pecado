<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\Finance\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Взаиморасчёты для машинного потребителя (v16.0.0, карточка fin-09).
 *
 * Заменяют денежные операции, построенные на графе документов. До регистра
 * агенту приходилось не верить собственному API: в ответах висели предупреждения
 * «это НЕ долг клиента, отвечайте по `payment.balances`», потому что сумма
 * остатков по документам систематически превышала реальный долг в разы.
 *
 * Теперь долг — это долг, и предупреждения сняты. Осталось одно, и оно важное:
 * **`amount` со знаком**. Агент, не знающий этого, выдаст долг наизнанку
 * и не заметит — арифметика сойдётся, а смысл перевернётся.
 *
 * Журнал платежей (`payment.list` / `payment.show`) остаётся как был: он отвечает
 * на другой вопрос — «дошёл ли мой платёж», и регистр его не заменяет.
 */
class SettlementOperations
{
    use ResolvesCrmEntities;

    public function __construct(
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * Баланс, текущий долг и просрочка одним ответом.
     *
     * Три числа вместе намеренно: по отдельности их путают. Сальдо включает
     * обязательства, срок которых ещё не наступил, текущий долг — только
     * наступившие, просрочка — только пропущенные.
     *
     * @return array<string, mixed>
     */
    public function balance(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');
        $today = CarbonImmutable::today()->toDateString();

        $clientId = $input->int('client_id')
            ? $this->client($actor, $input, 'client_id')->getKey()
            : null;

        $scope = fn (string $nature) => DB::table('settlement_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->where('e.nature', $nature)
            ->whereIn('e.user_id', (clone $clients))
            ->when($clientId !== null, fn ($query) => $query->where('e.user_id', $clientId));

        $facts = $scope(SettlementEntry::NATURE_FACT)
            ->groupBy('e.user_id', 'e.company_id', 'u.name', 'u.erp_name', 'c.name', 'c.tax_id')
            ->select(['e.user_id', 'e.company_id', 'u.name', 'u.erp_name'])
            ->selectRaw('c.name as contractor, c.tax_id as tax_id')
            ->selectRaw('SUM(COALESCE(e.amount_rub, e.amount)) as balance')
            ->get();

        $due = $this->planTotals($scope(SettlementEntry::NATURE_PLAN), $today, overdueOnly: false);
        $overdue = $this->planTotals($scope(SettlementEntry::NATURE_PLAN), $today, overdueOnly: true);

        $rows = $facts->map(function (object $row) use ($due, $overdue): array {
            $key = (int) $row->company_id;

            return [
                'client' => $row->erp_name ?: $row->name,
                'client_id' => (int) $row->user_id,
                'contractor' => $row->contractor,
                'contractor_id' => $key,
                'tax_id' => $row->tax_id,
                // Отрицательное сальдо — долг партнёра. Знак повторяет 1С,
                // чтобы число сверялось с учётной системой не задумываясь.
                'balance' => round((float) $row->balance, 2),
                'due_now' => round((float) ($due[$key] ?? 0), 2),
                'overdue' => round((float) ($overdue[$key] ?? 0), 2),
                'advance' => round(max(0.0, (float) $row->balance), 2),
                'currency_code' => 'RUB',
            ];
        })->values();

        if ($input->bool('only_overdue')) {
            $rows = $rows->filter(static fn (array $row): bool => $row['overdue'] > 0)->values();
        }

        return [
            'data' => $rows->sortByDesc('overdue')->values()->all(),
            'meta' => [
                'total' => $rows->count(),
                'notes' => $this->notes(),
            ],
        ];
    }

    /**
     * Плановые платежи с остатком: когда и сколько партнёр должен внести.
     *
     * @return array<string, mixed>
     */
    public function schedule(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');

        $query = SettlementEntry::query()
            ->outstanding()
            ->whereIn('user_id', (clone $clients))
            ->when(
                $input->int('client_id'),
                fn ($q) => $q->where('user_id', $this->client($actor, $input, 'client_id')->getKey()),
            )
            ->when($input->bool('only_overdue'), fn ($q) => $q->overdue())
            ->when($input->string('date_from'), fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($input->string('date_to'), fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->with(['user:id,name,erp_name'])
            ->orderBy('date');

        $perPage = min(max((int) ($input->int('per_page') ?: 25), 1), 100);
        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) ($input->int('page') ?: 1)));

        return [
            'data' => collect($page->items())->map(fn (SettlementEntry $line): array => [
                'id' => (int) $line->getKey(),
                'client' => $line->user?->display_name,
                'client_id' => $line->user_id,
                'due_date' => $line->date?->toDateString(),
                'document' => $line->document_label,
                'amount' => (float) $line->amount,
                'settled_amount' => (float) $line->settled_amount,
                'unsettled_amount' => $line->unsettled_amount,
                'is_overdue' => $line->is_overdue,
                'is_settled_derived' => $line->is_settled_derived,
                'currency_code' => $line->currency_code,
            ])->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'notes' => [
                    'Суммы графика положительные: это «сколько партнёр должен заплатить», а не движение баланса.',
                    'Остаток строки = amount − settled_amount, погашенную часть присылает 1С. Сайт платежи не раскладывает.',
                    '`is_settled_derived: true` — погашение разнесено по этапам заказа для календаря. '
                        .'Величина производная: в баланс и сверку её не берите.',
                ],
            ],
        ];
    }

    /**
     * Акт сверки по партнёру за период.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input, 'client_id');
        $period = $this->reconciliation->defaultPeriod();

        $act = $this->reconciliation->act(
            client: $client,
            organizationId: $input->int('organization_id') ?: null,
            from: $input->string('date_from') ?: $period['from'],
            to: $input->string('date_to') ?: $period['to'],
            currency: $input->string('currency') ?: 'RUB',
        );

        return [
            'data' => $act,
            'meta' => [
                'notes' => [
                    ...$this->notes(),
                    'Формула: сальдо на начало + оплаты + возвраты товара − реализации − возврат денег.',
                    $act['discrepancy'] !== null
                        ? 'ВНИМАНИЕ: сумма движений не сходится с балансом 1С. Акт неполный, отправлять клиенту нельзя.'
                        : 'Сумма движений сходится с балансом 1С.',
                ],
            ],
        ];
    }

    /**
     * Кому звонить: партнёры с просрочкой, по убыванию суммы.
     *
     * @return array<string, mixed>
     */
    public function debtors(User $actor, OperationInput $input): array
    {
        $clients = User::query()->visibleInCrm($actor)->select('users.id');
        $limit = min(max((int) ($input->int('limit') ?: 20), 1), 100);

        $rows = DB::table('settlement_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->where('e.nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('e.user_id', (clone $clients))
            ->whereDate('e.date', '<', CarbonImmutable::today()->toDateString())
            ->whereRaw('e.amount - e.settled_amount > '.SettlementEntry::EPSILON)
            ->groupBy('e.user_id', 'u.name', 'u.erp_name', 'pm.name')
            ->select(['e.user_id', 'u.name', 'u.erp_name'])
            ->selectRaw('pm.name as manager')
            ->selectRaw('SUM(e.amount - e.settled_amount) as overdue')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('MIN(e.date) as oldest_due_date')
            ->orderByRaw('SUM(e.amount - e.settled_amount) DESC')
            ->limit($limit)
            ->get();

        return [
            'data' => $rows->map(static fn (object $row): array => [
                'client' => $row->erp_name ?: $row->name,
                'client_id' => (int) $row->user_id,
                'manager' => $row->manager,
                'overdue' => round((float) $row->overdue, 2),
                'lines' => (int) $row->lines_count,
                'oldest_due_date' => $row->oldest_due_date !== null
                    ? CarbonImmutable::parse($row->oldest_due_date)->toDateString()
                    : null,
                'currency_code' => 'RUB',
            ])->all(),
            'meta' => [
                'total' => $rows->count(),
                'notes' => [
                    'Просрочка — непогашенные плановые платежи с датой раньше сегодняшней.',
                    'Партнёр с переплатой сюда не попадает: у него нет непогашенных строк.',
                ],
            ],
        ];
    }

    /**
     * Непогашенный план по контрагентам.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array<int, float>
     */
    private function planTotals($query, string $today, bool $overdueOnly): array
    {
        return $query
            ->whereRaw('e.amount - e.settled_amount > '.SettlementEntry::EPSILON)
            ->whereDate('e.date', $overdueOnly ? '<' : '<=', $today)
            ->whereNotNull('e.company_id')
            ->groupBy('e.company_id')
            ->select('e.company_id')
            ->selectRaw('SUM(e.amount - e.settled_amount) as total')
            ->pluck('total', 'company_id')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * Пояснения, без которых агент ошибётся молча.
     *
     * Знак — единственное оставшееся предупреждение, и снимать его нельзя:
     * перепутав его, агент выдаст долг наизнанку с полной уверенностью.
     *
     * @return list<string>
     */
    private function notes(): array
    {
        return [
            'Знак содержательный: отрицательное сальдо — партнёр должен нам, положительное — переплата.',
            'Регистр взаиморасчётов ведётся с '.ReconciliationService::LEDGER_STARTS_AT
                .'. За более ранние периоды данных нет — это не «нулевой долг».',
            'balance — сальдо всех операций, due_now — наступившие по сроку обязательства, '
                .'overdue — из них просроченные. Это три разных числа, не подменяйте одно другим.',
        ];
    }
}
