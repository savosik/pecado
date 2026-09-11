<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyQualification;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Премия отдела за квартал» глазами работника (карточка mot-30, контракт 04 § 7).
 *
 * Показываются партнёры всего отдела, а не только свои: премия общая. Средняя
 * покупка на партнёра не передаётся ни в каком виде — зачёт поклиентный,
 * и средняя создавала бы впечатление, что «в среднем дотянули».
 */
class QuarterReferenceService
{
    public function __construct(private readonly QuarterlyBonusService $bonuses) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $month): array
    {
        $quarter = CarbonImmutable::instance($month)->startOfQuarter();

        $bonus = MotivationQuarterlyBonus::query()->whereDate('quarter_start', $quarter)->first()
            ?? $this->bonuses->recalculate($quarter);

        $snapshot = (array) $bonus->snapshot;
        $threshold = (float) ($snapshot['threshold'] ?? config('motivation.default_parameters.quarterly_qualification_amount', 0));
        $steps = (array) ($snapshot['steps'] ?? config('motivation.default_parameters.quarterly_steps', []));

        $rows = MotivationQuarterlyQualification::query()->forQuarter($quarter)->get();
        $partnerIds = $rows->pluck('user_id')->map('intval')->all();

        $names = User::query()->whereIn('id', $partnerIds)->get(['id', 'name', 'erp_name'])->keyBy('id');
        $managers = PersonalManager::query()->whereIn('id', $rows->pluck('personal_manager_id')->filter()->unique()->all())->pluck('name', 'id')->all();
        $novelty = MotivationPartnerNovelty::query()->whereIn('user_id', $partnerIds)->get()->keyBy('user_id');

        $candidates = [];

        foreach ($rows as $row) {
            $net = (float) $row->shipments_amount - (float) $row->returns_amount;
            /** @var User|null $user */
            $user = $names[(int) $row->user_id] ?? null;

            $candidates[] = [
                'partner_id' => (int) $row->user_id,
                'name' => (string) ($user->display_name ?? $user->name ?? ('#'.$row->user_id)),
                'manager_id' => $row->personal_manager_id === null ? null : (int) $row->personal_manager_id,
                'manager' => $row->personal_manager_id === null ? null : (string) ($managers[(int) $row->personal_manager_id] ?? ''),
                'first_purchase_on' => $novelty[(int) $row->user_id]?->novelty_started_on?->toDateString(),
                'quarter_amount' => Money::round($net),
                'qualified' => (bool) $row->qualified,
                'shortfall' => Money::round(max(0.0, $threshold - $net)),
            ];
        }

        // Засчитанные первыми, дальше — по близости к порогу.
        usort($candidates, fn (array $a, array $b): int => [$b['qualified'], $b['quarter_amount']] <=> [$a['qualified'], $a['quarter_amount']]);

        $qualified = (int) $bonus->qualified_count;
        $today = CarbonImmutable::today();
        $end = $quarter->endOfQuarter()->startOfDay();

        return [
            'quarter' => $quarter->toDateString(),
            'quarter_label' => sprintf('%s квартал %d', ['I', 'II', 'III', 'IV'][$quarter->quarter - 1], $quarter->year),
            'status' => $bonus->status,
            'frozen' => $bonus->isFrozen(),
            'qualification_amount' => $threshold,
            'steps' => array_values(array_map(fn (array $step): array => [
                'count' => (int) $step['count'],
                'amount' => (float) $step['amount'],
                'reached' => $qualified >= (int) $step['count'],
            ], $steps)),
            'qualified_count' => $qualified,
            'candidates_count' => count($candidates),
            'step_reached' => (int) $bonus->step_reached,
            'amount' => (float) $bonus->amount,
            'next_step' => $this->nextStep($qualified, $steps),
            'days_left' => $today->greaterThan($end) ? 0 : (int) $today->diffInDays($end),
            'candidates' => $candidates,
            'note' => 'Премия начисляется на отдел и не входит в месячный доход работника. Зачёт поклиентный: каждый партнёр должен сам набрать порог.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{count: int, amount: float, partners_needed: int}|null
     */
    private function nextStep(int $qualified, array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($qualified < (int) $step['count']) {
                return ['count' => (int) $step['count'], 'amount' => (float) $step['amount'], 'partners_needed' => (int) $step['count'] - $qualified];
            }
        }

        return null;
    }
}
