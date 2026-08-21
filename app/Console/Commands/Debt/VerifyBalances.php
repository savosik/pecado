<?php

namespace App\Console\Commands\Debt;

use App\Models\User;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\PaymentForecast;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сверка долга по каждому клиенту: мастер-данные 1С против расчёта сайта.
 *
 * Мастер — contractor_balances (проекция расчёта 1С). Расчёт сайта — остатки
 * просроченных строк графиков оплат (PaymentForecast). Идеального равенства
 * между ними нет by design: 1С гасит долг зачётами и корректировками, которых
 * график не видит. Команда отвечает на два вопроса debt-01:
 *   1) у кого расчёты сходятся в пределах допуска — кандидаты в пилот
 *      финансов кабинета (CABINET_FINANCE_PILOT_USERS);
 *   2) у кого и насколько расходятся — их разбирать до включения эскалации.
 *
 * Отдельно помечаются протухшие балансы (balance_erp_updated_at старше
 * config('debt.stale_after_days')): по ним эскалация запрещена конституцией
 * домена, а систематическое протухание — ранний сигнал проблем обмена.
 */
class VerifyBalances extends Command
{
    protected $signature = 'debt:verify-balances
        {--strict : Ненулевой код выхода, если есть расхождения больше допуска}
        {--tolerance=1 : Допустимое расхождение просрочки, ₽}
        {--top=15 : Сколько крупнейших расхождений показать в таблице}';

    protected $description = 'Сверка просрочки клиентов: contractor_balances (1С) против графиков оплат (сайт)';

    public function handle(PaymentForecast $forecast): int
    {
        $today = CarbonImmutable::today();
        $tolerance = max(0.01, (float) $this->option('tolerance'));
        $staleBefore = $today->subDays((int) config('debt.stale_after_days', 3));

        // Мастер 1С: просрочка и сальдо по клиенту, самый старый баланс — для свежести.
        // DB::table, а не модель: строка здесь — агрегат по клиенту, не контрагент.
        $master = DB::table('contractor_balances')
            ->selectRaw('user_id')
            ->selectRaw('SUM(overdue_debt) as overdue_1c')
            ->selectRaw('SUM(current_balance) as balance_1c')
            ->selectRaw('MIN(balance_erp_updated_at) as oldest_update')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        if ($master->isEmpty()) {
            $this->info('В contractor_balances нет данных — сверять нечего.');

            return self::SUCCESS;
        }

        $siteOverdue = $this->siteOverdueByClient($forecast, $master->keys()->all(), $today);

        $names = User::query()
            ->whereIn('id', $master->keys())
            ->pluck('erp_name', 'id');

        $rows = $master->map(function (object $row, int $userId) use ($siteOverdue, $names, $tolerance, $staleBefore): array {
            $overdue1c = round((float) $row->overdue_1c, 2);
            $overdueSite = round((float) ($siteOverdue[$userId] ?? 0.0), 2);
            $diff = round(abs($overdue1c - $overdueSite), 2);
            $oldest = $row->oldest_update !== null ? CarbonImmutable::parse($row->oldest_update) : null;

            return [
                'user_id' => $userId,
                'client' => (string) ($names[$userId] ?? ('id '.$userId)),
                'overdue_1c' => $overdue1c,
                'overdue_site' => $overdueSite,
                'diff' => $diff,
                'matched' => $diff <= $tolerance,
                'stale' => $oldest !== null && $oldest->lessThan($staleBefore),
            ];
        })->values();

        $mismatched = $rows->where('matched', false)->sortByDesc('diff')->values();
        $stale = $rows->where('stale', true);

        $this->line(sprintf(
            'Клиентов в сверке: %d. Сходится в пределах %.2f ₽: %d. Расходится: %d. Протухших балансов (старше %s): %d.',
            $rows->count(),
            $tolerance,
            $rows->where('matched', true)->count(),
            $mismatched->count(),
            $staleBefore->format('d.m.Y'),
            $stale->count(),
        ));

        if ($mismatched->isNotEmpty()) {
            $this->newLine();
            $this->line('Крупнейшие расхождения (1С учитывает зачёты, которых график не видит — разбирать перед пилотом):');
            $this->table(
                ['user_id', 'Клиент', 'Просрочка 1С, ₽', 'Просрочка по графикам, ₽', 'Расхождение, ₽', 'Баланс протух'],
                $mismatched->take(max(1, (int) $this->option('top')))->map(static fn (array $r): array => [
                    $r['user_id'],
                    mb_strimwidth($r['client'], 0, 45, '…'),
                    number_format($r['overdue_1c'], 2, ',', ' '),
                    number_format($r['overdue_site'], 2, ',', ' '),
                    number_format($r['diff'], 2, ',', ' '),
                    $r['stale'] ? 'да' : '',
                ])->all(),
            );
        }

        $matchedIds = $rows->where('matched', true)->pluck('user_id')->sort()->values();

        if ($matchedIds->isNotEmpty()) {
            $this->newLine();
            $this->line('Кандидаты в пилот финансов кабинета (сверены в пределах допуска):');
            $this->line('CABINET_FINANCE_PILOT_USERS='.$matchedIds->implode(','));
        }

        if ($this->option('strict') && $mismatched->isNotEmpty()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Просрочка по расчёту сайта: остатки просроченных строк графиков, в рублях.
     * Агрегация в PHP: валютные строки конвертируются через toRub, а просроченных
     * строк даже на проде сотни — таблица в память помещается свободно.
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    private function siteOverdueByClient(PaymentForecast $forecast, array $userIds, CarbonImmutable $today): array
    {
        $filters = new FinanceFilters(dateFrom: $today, dateTo: $today);

        $query = $forecast->plannedQuery(User::query()->whereIn('id', $userIds)->select('id'), $filters);

        return $forecast->overdueOnly($query, $today)
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $lines): float => round($lines->sum(
                fn (object $line): float => $forecast->toRub(
                    (float) $line->amount - (float) $line->paid_amount - (float) $line->prepaid_amount,
                    $line->currency_code,
                ),
            ), 2))
            ->all();
    }
}
