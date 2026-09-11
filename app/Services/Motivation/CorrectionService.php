<?php

namespace App\Services\Motivation;

use App\Events\Payroll\PayrollInputsChanged;
use App\Models\Motivation\MotivationObjection;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\User;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Разовые корректировки (п. 6.7) и ответы на возражения (п. 11.3) — карточка mot-34.
 *
 * Корректировка ограничена долей переменной части из приказа: превышение
 * блокируется, а не предупреждается. Возражение либо принимается —
 * и месяц переоткрывается новой версией, либо отклоняется с обоснованием;
 * и то и другое остаётся в истории.
 */
class CorrectionService
{
    public function __construct(private readonly PayrollCalculationService $calculations) {}

    /**
     * @throws \InvalidArgumentException
     */
    public function add(int $managerId, CarbonInterface $month, float $amount, string $reason, User $actor): PayrollManualAdjustment
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $calculation = $this->calculations->ensureDraft($managerId, $period);

        if ($calculation->isFrozen()) {
            throw new \InvalidArgumentException('Расчёт за этот месяц утверждён — сначала переоткройте его.');
        }

        if (abs($amount) < 0.01) {
            throw new \InvalidArgumentException('Сумма корректировки не может быть нулевой.');
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw new \InvalidArgumentException('Корректировка требует основания — хотя бы одним предложением.');
        }

        $params = EffectiveParams::fromArray((array) $calculation->params_effective);
        $limitShare = (float) (config('motivation.default_parameters.adjustment_limit') ?? 0.15);
        $variable = $this->variablePart($calculation);
        $already = (float) PayrollManualAdjustment::query()
            ->where('personal_manager_id', $managerId)
            ->whereDate('period_month', $period)
            ->where('component_key', PayrollManualAdjustment::COMPONENT_MANUAL_CORRECTION)
            ->sum('amount');
        $limit = Money::round($variable * $limitShare);

        if ($params->enabled('motivation_variable') && abs($already + $amount) > $limit + 0.005) {
            throw new \InvalidArgumentException(sprintf(
                'Предел разовой корректировки — %s от переменной части (%s). С учётом уже внесённых корректировок допустимо ещё %s.',
                Money::percent($limitShare, 0),
                Money::rub($limit),
                Money::rub(max(0.0, $limit - abs($already))),
            ));
        }

        $adjustment = PayrollManualAdjustment::query()->create([
            'personal_manager_id' => $managerId,
            'period_month' => $period->toDateString(),
            'component_key' => PayrollManualAdjustment::COMPONENT_MANUAL_CORRECTION,
            'label' => mb_strimwidth(trim($reason), 0, 120, '…'),
            'qty' => 1,
            'price' => Money::round($amount),
            'amount' => Money::round($amount),
            'comment' => trim($reason),
            'author_id' => $actor->getKey(),
        ]);

        PayrollInputsChanged::dispatch([$managerId], 'adjustment.created', [$period->toDateString()]);

        return $adjustment;
    }

    public function remove(PayrollManualAdjustment $adjustment): void
    {
        $managerId = (int) $adjustment->personal_manager_id;
        $period = CarbonImmutable::instance($adjustment->period_month)->startOfMonth();

        if ($this->calculations->current($managerId, $period)?->isFrozen()) {
            throw new \InvalidArgumentException('Расчёт за этот месяц утверждён — сначала переоткройте его.');
        }

        $adjustment->delete();
        PayrollInputsChanged::dispatch([$managerId], 'adjustment.deleted', [$period->toDateString()]);
    }

    /**
     * Ответ на возражение: принять (переоткрыть месяц новой версией) или отклонить с обоснованием.
     *
     * @throws \InvalidArgumentException
     */
    public function respond(MotivationObjection $objection, string $decision, ?string $response, User $actor): MotivationObjection
    {
        if ($objection->status !== MotivationObjection::STATUS_OPEN) {
            throw new \InvalidArgumentException('На это возражение уже дан ответ.');
        }

        $response = trim((string) $response);

        if ($decision === MotivationObjection::STATUS_REJECTED && mb_strlen($response) < 5) {
            throw new \InvalidArgumentException('Отказ требует обоснования (п. 11.3).');
        }

        if (! in_array($decision, [MotivationObjection::STATUS_ACCEPTED, MotivationObjection::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Решение: принять или отклонить.');
        }

        if ($decision === MotivationObjection::STATUS_ACCEPTED) {
            $calculation = $objection->calculation;

            if ($calculation !== null && $calculation->isFrozen()) {
                $this->calculations->reopen($calculation, $actor, 'Возражение работника принято: '.mb_strimwidth($objection->reason, 0, 180, '…'));
            }
        }

        $objection->forceFill([
            'status' => $decision,
            'response' => $response !== '' ? $response : null,
            'responded_by' => $actor->getKey(),
            'responded_at' => now(),
        ])->save();

        return $objection;
    }

    private function variablePart(PayrollCalculation $calculation): float
    {
        foreach ((array) data_get($calculation->breakdown, 'components', []) as $component) {
            if (is_array($component) && ($component['key'] ?? null) === 'motivation_variable') {
                return (float) ($component['amount'] ?? 0);
            }
        }

        return 0.0;
    }
}
