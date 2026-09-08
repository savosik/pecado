<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyQualification;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Квартальная премия отдела: зачёт партнёров, ступень, распределение (карточка mot-24).
 *
 * Живёт отдельно от месячного снимка: премия начисляется на отдел, на показатели
 * раздела 6 не влияет и Личный план не изменяет (пп. 7.6, 7.7). Отдельные таблицы
 * делают это видимым в данных, а не только в коде.
 *
 * Зачёт хранится построчно по каждому партнёру: условие пункта 7.3 проверяется
 * по каждому отдельно, и построчное хранение — единственный способ доказать,
 * что совокупный объём никто не делил на количество.
 */
class QuarterlyBonusService
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PartnerAttributionResolver $attribution,
        private readonly QuarterlyBonusCalculator $calculator,
    ) {}

    /**
     * Пересчитать черновик премии за квартал.
     *
     * Утверждённая и выплаченная премия не пересчитывается: она уже доведена
     * до работников и распределена.
     */
    public function recalculate(CarbonInterface $quarter): MotivationQuarterlyBonus
    {
        $start = CarbonImmutable::instance($quarter)->startOfQuarter()->startOfDay();
        $end = $start->endOfQuarter()->endOfDay();

        $existing = MotivationQuarterlyBonus::query()->whereDate('quarter_start', $start)->first();

        if ($existing !== null && $existing->isFrozen()) {
            return $existing;
        }

        $rows = $this->qualifications($start, $end);
        $params = (array) config('motivation.default_parameters', []);
        $threshold = (float) ($params['quarterly_qualification_amount'] ?? 0);
        $steps = (array) ($params['quarterly_steps'] ?? []);

        $result = $this->calculator->evaluate(
            array_map(
                fn (array $row): array => [
                    'user_id' => $row['user_id'],
                    'amount' => $row['shipments_amount'],
                    'returns' => $row['returns_amount'],
                ],
                $rows,
            ),
            $threshold,
            $steps,
        );

        return DB::transaction(function () use ($start, $rows, $result, $threshold, $steps, $existing): MotivationQuarterlyBonus {
            MotivationQuarterlyQualification::query()->whereDate('quarter_start', $start)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                MotivationQuarterlyQualification::query()->insert(array_map(
                    fn (array $row): array => [
                        'quarter_start' => $start->toDateString(),
                        'user_id' => $row['user_id'],
                        'personal_manager_id' => $row['personal_manager_id'],
                        'shipments_amount' => $row['shipments_amount'],
                        'returns_amount' => $row['returns_amount'],
                        'qualified' => in_array($row['user_id'], $result['qualified'], true),
                        'computed_at' => now(),
                    ],
                    $chunk,
                ));
            }

            $bonus = $existing ?? new MotivationQuarterlyBonus(['quarter_start' => $start->toDateString()]);

            $bonus->fill([
                'quarter_start' => $start->toDateString(),
                'qualified_count' => $result['qualified_count'],
                'step_reached' => $result['step'],
                'amount' => $result['amount'],
                'status' => MotivationQuarterlyBonus::STATUS_DRAFT,
                'snapshot' => [
                    'threshold' => $threshold,
                    'steps' => $steps,
                    'candidates' => count($rows),
                    'qualified' => $result['qualified'],
                    'total_amount' => $result['total'],
                    'next_step' => $this->calculator->nextStep($result['qualified_count'], $steps),
                    'computed_at' => now()->toIso8601String(),
                ],
            ])->save();

            return $bonus->refresh();
        });
    }

    /**
     * Кандидаты квартала: Новые партнёры и их объём за квартал.
     *
     * @return list<array{user_id: int, personal_manager_id: int|null, shipments_amount: float, returns_amount: float}>
     */
    private function qualifications(CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Новый партнёр квартала — тот, чей Период новизны захватывает хотя бы
        // один месяц квартала: премия платится за привлечение, а не за месяц,
        // в котором пришли деньги.
        $partnerIds = MotivationPartnerNovelty::query()
            ->newInMonth($start)
            ->orWhere(fn ($q) => $q->newInMonth($start->addMonth()))
            ->orWhere(fn ($q) => $q->newInMonth($start->addMonths(2)))
            ->pluck('user_id')
            ->map('intval')
            ->all();

        if ($partnerIds === []) {
            return [];
        }

        $managers = $this->attribution->managersOf($partnerIds, $end);
        $amounts = $this->amounts($partnerIds, $start, $end);
        $returns = $this->returns($partnerIds, $start, $end);

        $rows = [];

        foreach ($partnerIds as $partnerId) {
            $rows[] = [
                'user_id' => $partnerId,
                'personal_manager_id' => $managers[$partnerId] ?? null,
                'shipments_amount' => $amounts[$partnerId] ?? 0.0,
                'returns_amount' => $returns[$partnerId] ?? 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $partnerIds
     * @return array<int, float>
     */
    private function amounts(array $partnerIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ctx = AnalyticsContext::forScope($partnerIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $amounts = [];

        foreach ($this->analytics->byPartner($ctx, new AnalyticsFilters(dateFrom: $start, dateTo: $end), null) as $row) {
            if ($row['partner_id'] !== null) {
                $amounts[(int) $row['partner_id']] = round((float) $row['amount'], 2);
            }
        }

        return $amounts;
    }

    /**
     * @param  list<int>  $partnerIds
     * @return array<int, float>
     */
    private function returns(array $partnerIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ProductReturn::query()
            ->whereIn('user_id', $partnerIds)
            ->where('status', \App\Enums\ReturnStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('user_id, SUM(total_amount) AS total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($value): float => Money::round((float) $value))
            ->all();
    }

    public function approve(MotivationQuarterlyBonus $bonus, User $actor): MotivationQuarterlyBonus
    {
        if ($bonus->status !== MotivationQuarterlyBonus::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Утвердить можно только черновик премии.');
        }

        $bonus->forceFill([
            'status' => MotivationQuarterlyBonus::STATUS_APPROVED,
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ])->save();

        return $bonus;
    }

    public function markPaid(MotivationQuarterlyBonus $bonus, User $actor): MotivationQuarterlyBonus
    {
        if ($bonus->status !== MotivationQuarterlyBonus::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Отметить выплаченной можно только утверждённую премию.');
        }

        if ($bonus->amount > 0 && $this->sharesTotal($bonus) !== (float) $bonus->amount) {
            throw new \InvalidArgumentException('Премия не распределена: сумма долей не равна сумме премии.');
        }

        $bonus->forceFill([
            'status' => MotivationQuarterlyBonus::STATUS_PAID,
            'paid_by' => $actor->getKey(),
            'paid_at' => now(),
        ])->save();

        return $bonus;
    }

    /**
     * Распределить премию между работниками (п. 7.5).
     *
     * Распределение возможно только после утверждения итога квартала: до него
     * неизвестна сумма, а делить неизвестное нельзя. Сумма долей обязана
     * совпадать с суммой премии — иначе в расчётные листы попадёт не та сумма,
     * которую утвердили.
     *
     * @param  array<int, float>  $shares  personal_manager_id → сумма, ₽
     */
    public function distribute(MotivationQuarterlyBonus $bonus, array $shares, User $actor, ?string $reason = null): void
    {
        if ($bonus->status === MotivationQuarterlyBonus::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Распределять можно только утверждённую премию.');
        }

        if ($bonus->status === MotivationQuarterlyBonus::STATUS_PAID) {
            throw new \InvalidArgumentException('Выплаченная премия не перераспределяется.');
        }

        $total = Money::round(array_sum(array_map('floatval', $shares)));

        if ($total !== (float) $bonus->amount) {
            throw new \InvalidArgumentException(sprintf(
                'Сумма долей %s не равна сумме премии %s.',
                Money::rub($total),
                Money::rub((float) $bonus->amount),
            ));
        }

        foreach (array_keys($shares) as $managerId) {
            if (! PersonalManager::query()->whereKey($managerId)->exists()) {
                throw new \InvalidArgumentException(sprintf('Менеджер #%d не найден.', $managerId));
            }
        }

        DB::transaction(function () use ($bonus, $shares, $actor, $reason): void {
            MotivationQuarterlyShare::query()->where('bonus_id', $bonus->getKey())->delete();

            foreach ($shares as $managerId => $amount) {
                MotivationQuarterlyShare::query()->create([
                    'bonus_id' => $bonus->getKey(),
                    'personal_manager_id' => (int) $managerId,
                    'amount' => Money::round((float) $amount),
                    'reason' => $reason,
                    'author_id' => $actor->getKey(),
                ]);
            }
        });
    }

    private function sharesTotal(MotivationQuarterlyBonus $bonus): float
    {
        return Money::round((float) MotivationQuarterlyShare::query()
            ->where('bonus_id', $bonus->getKey())
            ->sum('amount'));
    }
}
