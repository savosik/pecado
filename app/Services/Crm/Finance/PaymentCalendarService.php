<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Календарь поступлений: график оплат из 1С и фактические платежи по дням.
 *
 * Раздел намеренно ничего не предсказывает — этим занят «План поступлений».
 * Здесь показано только то, что есть в учётной системе: какого числа партнёр
 * обязался заплатить и какого числа деньги пришли. Никаких вероятностей и
 * поправок на платёжную дисциплину: календарь — это документ, а не прогноз.
 *
 * Просрочка в дни календаря не раскладывается. Её срок уже прошёл, и рисовать
 * её «сегодняшним днём» значило бы обещать деньги, которых может не быть, —
 * поэтому она показана отдельным навесом с разбивкой по возрасту: сколько и
 * как давно ждём сверх того, что обещано в этом месяце.
 */
class PaymentCalendarService
{
    /** Разрезы: ось → как называется строка таблицы. */
    public const AXES = [
        'partner' => 'Партнёр',
        'company' => 'Контрагент',
        'organization' => 'Наша организация',
        'manager' => 'Менеджер',
    ];

    /**
     * План и факт по дням месяца.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, array{plan: float, settled: float, plan_count: int, fact: float, fact_count: int}>
     */
    public function days(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $days = [];

        $plan = $this->planQuery($clients, $filters)
            ->whereBetween(DB::raw('DATE(sch.date)'), [$from->toDateString(), $to->toDateString()])
            ->groupByRaw('DATE(sch.date)')
            ->selectRaw('DATE(sch.date) as day')
            ->selectRaw('SUM(sch.amount) as amount')
            ->selectRaw('SUM(sch.settled_amount) as settled')
            ->selectRaw('COUNT(*) as plan_lines')
            ->get();

        foreach ($plan as $row) {
            $days[(string) $row->day] = [
                // Весь график дня, а не остаток: календарь показывает, что
                // учётная система назначила к оплате. Сколько из этого уже
                // закрыто — рядом, отдельным числом.
                'plan' => round((float) $row->amount, 2),
                'settled' => round((float) $row->settled, 2),
                'plan_count' => (int) $row->plan_lines,
                'fact' => 0.0,
                'fact_count' => 0,
            ];
        }

        $facts = $this->factQuery($clients, $filters)
            ->whereBetween(DB::raw('DATE(f.date)'), [$from->toDateString(), $to->toDateString()])
            ->groupByRaw('DATE(f.date)')
            ->selectRaw('DATE(f.date) as day')
            ->selectRaw('SUM(COALESCE(f.amount_rub, f.amount)) as amount')
            ->selectRaw('COUNT(*) as payments')
            ->get();

        foreach ($facts as $row) {
            $day = (string) $row->day;
            $days[$day] ??= ['plan' => 0.0, 'settled' => 0.0, 'plan_count' => 0, 'fact' => 0.0, 'fact_count' => 0];
            $days[$day]['fact'] = round((float) $row->amount, 2);
            $days[$day]['fact_count'] = (int) $row->payments;
        }

        ksort($days);

        return $days;
    }

    /**
     * Навес просрочки: сколько денег ждут дольше срока и насколько давно.
     *
     * Считается на дату начала показываемого месяца, а не на сегодня: листая
     * календарь назад, менеджер должен видеть ту картину, которая была тогда,
     * иначе прошлый месяц выглядел бы задним числом хуже, чем был.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array{total: float, lines: int, oldest_days: int, buckets: list<array{key: string, label: string, amount: float, count: int}>}
     */
    public function overdueThread(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $asOf,
    ): array {
        $rows = $this->planQuery($clients, $filters, unpaidOnly: true)
            ->whereDate('sch.date', '<', $asOf->toDateString())
            ->selectRaw('sch.date as due_date')
            ->selectRaw('sch.amount - sch.settled_amount as unpaid')
            ->get();

        $buckets = array_fill_keys(array_column(self::BUCKETS, 'key'), ['amount' => 0.0, 'count' => 0]);
        $total = 0.0;
        $oldest = 0;

        foreach ($rows as $row) {
            $days = (int) CarbonImmutable::parse($row->due_date)->diffInDays($asOf);
            $amount = (float) $row->unpaid;
            $key = $this->bucketKey($days);

            $buckets[$key]['amount'] += $amount;
            $buckets[$key]['count']++;
            $total += $amount;
            $oldest = max($oldest, $days);
        }

        return [
            'total' => round($total, 2),
            'lines' => $rows->count(),
            'oldest_days' => $oldest,
            'buckets' => array_map(fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'amount' => round($buckets[$bucket['key']]['amount'], 2),
                'count' => $buckets[$bucket['key']]['count'],
            ], self::BUCKETS),
        ];
    }

    /** Корзины возраста просрочки — те же, что в разделе «Просрочка». */
    private const BUCKETS = [
        ['key' => 'w1', 'label' => 'до недели', 'to' => 7],
        ['key' => 'm1', 'label' => 'до месяца', 'to' => 30],
        ['key' => 'm3', 'label' => 'до трёх месяцев', 'to' => 90],
        ['key' => 'old', 'label' => 'дольше', 'to' => null],
    ];

    private function bucketKey(int $days): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($bucket['to'] === null || $days <= $bucket['to']) {
                return $bucket['key'];
            }
        }

        return 'old';
    }

    /**
     * Разрез месяца: сколько обещано, сколько пришло и сколько висит просрочкой
     * у каждого партнёра, контрагента, нашей организации или менеджера.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function breakdown(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $axis,
    ): array {
        $rows = [];

        $plan = $this->axisQuery($this->planQuery($clients, $filters), $axis, 'sch')
            ->whereBetween(DB::raw('DATE(sch.date)'), [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(sch.amount) as amount')
            ->selectRaw('SUM(sch.settled_amount) as settled')
            ->selectRaw('COUNT(*) as plan_lines')
            ->get();

        foreach ($plan as $row) {
            $key = (string) ($row->group_key ?? '0');

            // Присваивание, а не объединение через «+»: у пустой строки ключи
            // уже есть, и сложение массивов оставило бы нули.
            $rows[$key] ??= $this->emptyRow($axis, $row);
            $rows[$key]['plan'] += round((float) $row->amount, 2);
            $rows[$key]['settled'] += round((float) $row->settled, 2);
            $rows[$key]['plan_count'] += (int) $row->plan_lines;
        }

        $fact = $this->axisQuery($this->factQuery($clients, $filters), $axis, 'f')
            ->whereBetween(DB::raw('DATE(f.date)'), [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(COALESCE(f.amount_rub, f.amount)) as amount')
            ->selectRaw('COUNT(*) as payments')
            ->get();

        foreach ($fact as $row) {
            $key = (string) ($row->group_key ?? '0');
            $rows[$key] ??= $this->emptyRow($axis, $row);
            $rows[$key]['fact'] += round((float) $row->amount, 2);
            $rows[$key]['fact_count'] += (int) $row->payments;
        }

        $overdue = $this->axisQuery($this->planQuery($clients, $filters, unpaidOnly: true), $axis, 'sch')
            ->whereDate('sch.date', '<', $from->toDateString())
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as amount')
            ->get();

        foreach ($overdue as $row) {
            $key = (string) ($row->group_key ?? '0');
            $rows[$key] ??= $this->emptyRow($axis, $row);
            $rows[$key]['overdue'] += round((float) $row->amount, 2);
        }

        $rows = array_values($rows);

        // Сверху те, кто должен принести больше всего в этом месяце; при
        // равенстве — по факту: строка «ничего не обещал, но заплатил» тоже
        // должна быть на виду.
        usort($rows, static fn (array $a, array $b): int => [$b['plan'], $b['fact']] <=> [$a['plan'], $a['fact']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(string $axis, object $row): array
    {
        return [
            'key' => $axis.':'.($row->group_key ?? '0'),
            'entity_id' => $row->group_key !== null ? (int) $row->group_key : null,
            'title' => $row->group_title ?: $this->emptyTitle($axis),
            'subtitle' => $axis === 'partner' ? ($row->manager_name ?? null) : null,
            'url' => $axis === 'partner' && $row->group_key !== null
                ? route('crm.clients.show', (int) $row->group_key)
                : null,
            'plan' => 0.0,
            'settled' => 0.0,
            'plan_count' => 0,
            'fact' => 0.0,
            'fact_count' => 0,
            'overdue' => 0.0,
        ];
    }

    private function emptyTitle(string $axis): string
    {
        return match ($axis) {
            'manager' => 'Без менеджера',
            'organization' => 'Организация не указана',
            'company' => 'Контрагент не заведён',
            default => 'Партнёр не определён',
        };
    }

    /**
     * Плановые строки: график оплаты из 1С без поправок.
     *
     * По умолчанию — весь график, включая уже оплаченные строки: календарь
     * показывает документ, а не остаток долга. Фильтр по остатку включается
     * там, где речь именно о непогашенном — в навесе просрочки.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function planQuery(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        bool $unpaidOnly = false,
    ): \Illuminate\Database\Query\Builder {
        return DB::table('settlement_entries as sch')
            ->join('users as u', 'u.id', '=', 'sch.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->where('sch.nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('sch.user_id', (clone $clients))
            ->when(
                $unpaidOnly,
                fn ($query) => $query->whereRaw('sch.amount - sch.settled_amount > '.SettlementEntry::EPSILON),
            )
            // План по заказу — намерение, а не обязательство заплатить:
            // та же граница, что в просрочке и прогнозе.
            ->where(function ($query): void {
                $query->whereNull('sch.document_kind')->orWhere('sch.document_kind', '<>', 'order');
            })
            ->when(
                $filters->organizationIds !== [],
                fn ($query) => $query->whereIn('sch.organization_id', $filters->organizationIds),
            );
    }

    /**
     * Фактические платежи: приходы за вычетом возвратов.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function factQuery(EloquentBuilder $clients, FinanceFilters $filters): \Illuminate\Database\Query\Builder
    {
        return DB::table('settlement_entries as f')
            ->join('users as u', 'u.id', '=', 'f.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->where('f.nature', SettlementEntry::NATURE_FACT)
            // Возвраты входят со своим знаком: деньги, которые вернули
            // партнёру, в этот день не пришли, и день, где возврат больше
            // прихода, обязан показывать минус, а не приход.
            ->whereIn('f.type', [SettlementEntry::TYPE_PAYMENT_IN, SettlementEntry::TYPE_PAYMENT_OUT])
            ->whereIn('f.user_id', (clone $clients))
            ->when(
                $filters->organizationIds !== [],
                fn ($query) => $query->whereIn('f.organization_id', $filters->organizationIds),
            );
    }

    /** Группировка запроса по выбранной оси разреза. */
    private function axisQuery(
        \Illuminate\Database\Query\Builder $query,
        string $axis,
        string $alias,
    ): \Illuminate\Database\Query\Builder {
        [$key, $title] = match ($axis) {
            'manager' => ['u.personal_manager_id', 'pm.name'],
            'organization' => [$alias.'.organization_id', 'org.name'],
            'company' => [$alias.'.company_id', 'cmp.name'],
            default => [$alias.'.user_id', 'COALESCE(NULLIF(u.erp_name, \'\'), u.name)'],
        };

        if ($axis === 'organization') {
            $query->leftJoin('organizations as org', 'org.id', '=', $alias.'.organization_id');
        }

        if ($axis === 'company') {
            $query->leftJoin('companies as cmp', 'cmp.id', '=', $alias.'.company_id');
        }

        $query
            ->selectRaw($key.' as group_key')
            ->selectRaw($title.' as group_title')
            ->groupByRaw($key)
            ->groupByRaw($title);

        // Менеджер подписывается только под партнёром: там он один и от
        // группировки не зависит. На остальных осях группировка по нему
        // дробила бы строку организации на столько частей, сколько менеджеров
        // за ней стоит, — и суммы разреза переставали сходиться с итогом.
        if ($axis === 'partner') {
            $query->selectRaw('pm.name as manager_name')->groupBy('pm.name');
        }

        return $query;
    }
}
