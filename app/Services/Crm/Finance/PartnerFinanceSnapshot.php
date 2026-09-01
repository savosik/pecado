<?php

namespace App\Services\Crm\Finance;

use App\Enums\DebtLevel;
use App\Models\SettlementEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Компактное финансовое состояние партнёра: долг, доля просрочки, последний
 * платёж и ступень дебиторки.
 *
 * Нужен везде, где в разделе упомянут партнёр: имя без денег за ним ничего не
 * говорит менеджеру, который решает, звонить ли по завтрашнему сроку. Поэтому
 * снимок считается **пачкой на всю страницу** — четыре агрегата на любое число
 * партнёров, а не запрос на строку.
 *
 * Просрочка здесь считается строго: всё, что просрочено хоть на день. Раздел
 * «Дебиторка» пользуется смягчённым счётом (льгота в пять банковских дней и
 * отсечка 5 000 ₽), потому что он ведёт блокировки и не должен наказывать за
 * платёж, который ещё не разнесли. Финансисту же нужна юридическая картина,
 * сходящаяся с разделом «Просрочка», поэтому ступень дебиторки показывается
 * рядом отдельной строкой, а не подменяет собой долю.
 */
class PartnerFinanceSnapshot
{
    use FormatsForecastRows;

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function for(array $userIds, ?CarbonImmutable $today = null): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return [];
        }

        $today ??= CarbonImmutable::today();

        $debts = $this->debts($userIds);
        $overdue = $this->overdue($userIds, $today);
        $payments = $this->lastPayments($userIds);
        $levels = $this->levels($userIds);

        $snapshot = [];

        foreach ($userIds as $userId) {
            $debt = round($debts[$userId] ?? 0.0, 2);
            $overdueAmount = round($overdue[$userId]['total'] ?? 0.0, 2);

            $snapshot[$userId] = [
                'debt' => $debt,
                'overdue' => $overdueAmount,
                // Доля считается только от настоящего долга: при авансовом
                // сальдо знаменатель отрицательный, и процент был бы бессмыслицей.
                'overdue_share' => $debt > 0 ? (int) round(min(100, $overdueAmount / $debt * 100)) : 0,
                'buckets' => $overdue[$userId]['buckets'] ?? [],
                'last_payment' => $payments[$userId] ?? null,
                'debt_level' => $levels[$userId] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * Долг по регистру: отрицательное сальдо движений.
     *
     * Аванс не превращается в «отрицательный долг» — он просто не долг,
     * иначе переплата одного партнёра гасила бы долг в итогах страницы.
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    private function debts(array $userIds): array
    {
        $rows = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', $userIds)
            ->select('user_id')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance')
            ->groupBy('user_id')
            ->get();

        $debts = [];

        foreach ($rows as $row) {
            $balance = (float) $row->balance;
            $debts[(int) $row->user_id] = $balance < 0 ? -$balance : 0.0;
        }

        return $debts;
    }

    /**
     * Просрочка с разбивкой по возрасту.
     *
     * Заказы исключены: план по заказу — намерение, а долг создаёт отгрузка
     * (круг 12 сверки). Иначе счёт на предоплату висел бы просрочкой вечно.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{total: float, buckets: list<array<string, mixed>>}>
     */
    private function overdue(array $userIds, CarbonImmutable $today): array
    {
        $rows = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('user_id', $userIds)
            ->whereRaw('amount - settled_amount > '.SettlementEntry::EPSILON)
            ->whereDate('date', '<', $today->toDateString())
            ->where(static function ($query): void {
                $query->whereNull('document_kind')
                    ->orWhere('document_kind', '<>', PaymentPlanService::KIND_ADVANCE);
            })
            ->select(['user_id', 'date', 'currency_code'])
            ->selectRaw('SUM(amount - settled_amount) as unpaid')
            ->groupBy('user_id', 'date', 'currency_code')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $days = (int) CarbonImmutable::parse($row->date)->diffInDays($today);
            $amount = $this->toRub((float) $row->unpaid, $row->currency_code);

            $result[$userId] ??= ['total' => 0.0, 'raw' => []];
            $result[$userId]['total'] += $amount;

            $key = $this->agingKey($days);
            $result[$userId]['raw'][$key] = ($result[$userId]['raw'][$key] ?? 0.0) + $amount;
        }

        foreach ($result as $userId => $data) {
            $buckets = [];

            foreach (self::AGING_BUCKETS as $bucket) {
                $amount = round($data['raw'][$bucket['key']] ?? 0.0, 2);

                if ($amount > 0) {
                    $buckets[] = [
                        'key' => $bucket['key'],
                        'label' => $bucket['label'],
                        'amount' => $amount,
                    ];
                }
            }

            $result[$userId] = ['total' => $data['total'], 'buckets' => $buckets];
        }

        return $result;
    }

    /**
     * Последнее поступление: дата и сумма пришедшего в этот день.
     *
     * Считается по регистру, а не по журналу платежей: у раздела один
     * источник денег, и вторая витрина рано или поздно разъедется с первой.
     * Сумма берётся за день целиком — 1С разносит один платёж на десяток
     * реализаций, и «последняя строка» показала бы случайный хвост платежа.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{date: string, amount: float, days_ago: int}>
     */
    private function lastPayments(array $userIds): array
    {
        $latest = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->whereIn('user_id', $userIds)
            ->select('user_id')
            ->selectRaw('MAX(date) as last_date')
            ->groupBy('user_id')
            ->get();

        if ($latest->isEmpty()) {
            return [];
        }

        $dates = [];

        foreach ($latest as $row) {
            $dates[(int) $row->user_id] = CarbonImmutable::parse($row->last_date)->toDateString();
        }

        $sums = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->whereIn('user_id', array_keys($dates))
            ->select(['user_id', 'currency_code'])
            ->selectRaw('DATE(date) as day')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as amount')
            ->groupBy('user_id', 'currency_code', DB::raw('DATE(date)'))
            ->get();

        $today = CarbonImmutable::today();
        $result = [];

        foreach ($sums as $row) {
            $userId = (int) $row->user_id;
            $day = (string) $row->day;

            if (($dates[$userId] ?? null) !== $day) {
                continue;
            }

            $result[$userId] ??= [
                'date' => $day,
                'amount' => 0.0,
                'days_ago' => (int) CarbonImmutable::parse($day)->diffInDays($today),
            ];
            $result[$userId]['amount'] = round(
                $result[$userId]['amount'] + $this->toRub((float) $row->amount, $row->currency_code),
                2,
            );
        }

        return $result;
    }

    /**
     * Ступень дебиторки — справочно, рядом с юридической долей просрочки.
     *
     * Берётся только уровень: в `debt_states` лежат лишь партнёры с
     * просрочкой, и как источник долга по всем партнёрам таблица не годится.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{level: string, label: string, color: string}>
     */
    private function levels(array $userIds): array
    {
        $rows = DB::table('debt_states')
            ->whereIn('user_id', $userIds)
            ->whereNull('company_id')
            ->where('dry_run', false)
            ->select(['user_id', 'level'])
            ->get();

        $levels = [];

        foreach ($rows as $row) {
            $level = DebtLevel::tryFrom((string) $row->level);

            if ($level === null || $level === DebtLevel::CLEAN) {
                continue;
            }

            $levels[(int) $row->user_id] = [
                'level' => $level->value,
                'label' => $level->label(),
                'color' => $level->color(),
            ];
        }

        return $levels;
    }
}
