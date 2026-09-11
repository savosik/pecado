<?php

namespace App\Services\Motivation;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\User;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Приказ о Личных планах на квартал (карточка mot-25).
 *
 * Приказ хранит обоснование, а не сам план: при утверждении значения
 * записываются в crm_sales_plans, откуда план читают все экраны CRM.
 * Второй правды о плане не заводим — иначе «Планы продаж» и «Мой месяц»
 * начнут расходиться, и объяснить это будет нечем.
 */
class PlanOrderService
{
    public function __construct(private readonly PlanCalculator $calculator) {}

    /**
     * Черновик приказа: считается заново, прежний черновик перезаписывается.
     *
     * @param  array<string, mixed>  $params
     */
    public function draft(
        int $managerId,
        CarbonInterface $quarter,
        array $params = [],
        ?User $author = null,
        ?string $waiveReason = null,
    ): MotivationPlanOrder {
        $start = CarbonImmutable::instance($quarter)->startOfQuarter()->startOfDay();
        $result = $this->calculator->calculate($managerId, $start, $params, $waiveReason !== null);

        $draft = MotivationPlanOrder::query()
            ->forQuarter($start)
            ->where('personal_manager_id', $managerId)
            ->where('status', MotivationPlanOrder::STATUS_DRAFT)
            ->first();

        $version = $draft !== null
            ? (int) $draft->version
            : ((int) MotivationPlanOrder::query()
                ->forQuarter($start)
                ->where('personal_manager_id', $managerId)
                ->max('version')) + 1;

        $order = $draft ?? new MotivationPlanOrder;

        $order->fill([
            'quarter_start' => $start->toDateString(),
            'personal_manager_id' => $managerId,
            'version' => $version,
            'median_per_day' => $result['median_per_day'],
            'working_days' => $result['working_days'],
            'seasonal' => $result['seasonal'],
            'growth_rate' => $result['growth_rate'],
            'overperformance_carry' => $result['overperformance_carry'],
            'previous_quarter_total' => $result['previous_quarter_total'],
            'decline_limited' => $result['decline_limited'],
            'decline_limit_waived_reason' => $waiveReason,
            'values' => $result['values'],
            'previous_values' => $result['previous_values'],
            'status' => MotivationPlanOrder::STATUS_DRAFT,
            'author_id' => $author?->getKey(),
        ])->save();

        return $order->refresh();
    }

    /**
     * Ручная правка значений плана.
     *
     * Повышение против расчётного по основанию перевыполнения прошлого периода
     * запрещено пунктом 5.4: половина перевыполнения уже учтена в расчёте,
     * повторный учёт — это повышение плана за хорошую работу.
     *
     * @param  array<string, float>  $values  месяц Y-m-01 → сумма
     */
    public function override(MotivationPlanOrder $order, array $values, string $comment): MotivationPlanOrder
    {
        if ($order->status !== MotivationPlanOrder::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Утверждённый приказ не редактируется — издайте новую версию.');
        }

        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Отклонение от расчётного значения требует обоснования.');
        }

        $computed = (array) $order->values;
        $raised = false;

        foreach ($values as $month => $value) {
            if ((float) $value > (float) ($computed[$month] ?? 0)) {
                $raised = true;
            }
        }

        if ($raised && $this->mentionsOverperformance($comment)) {
            throw new \InvalidArgumentException(
                'Повышение плана по основанию перевыполнения прошлого периода не допускается (п. 5.4). '
                .'Половина перевыполнения уже учтена в расчёте.',
            );
        }

        $order->forceFill([
            'values' => array_map(fn ($value): float => Money::round((float) $value), $values),
            'comment' => $comment,
        ])->save();

        return $order->refresh();
    }

    /**
     * Утвердить приказ и записать значения в планы продаж.
     */
    public function approve(MotivationPlanOrder $order, User $actor): MotivationPlanOrder
    {
        if ($order->status !== MotivationPlanOrder::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Утвердить можно только черновик приказа.');
        }

        DB::transaction(function () use ($order, $actor): void {
            foreach ((array) $order->values as $month => $amount) {
                $period = CarbonImmutable::parse((string) $month)->startOfMonth();
                $attributes = [
                    'amount' => Money::round((float) $amount),
                    'author_id' => $actor->getKey(),
                    'comment' => sprintf('Приказ о планах на квартал, версия %d', $order->version),
                ];

                // Поиск — скоупами модели: они нормализуют дату так же, как unique-индекс.
                // Прямой updateOrCreate по строке даты не находил существующий план
                // и падал на дубликате.
                $existing = CrmSalesPlan::query()->forPeriod($period)->forManager((int) $order->personal_manager_id)->first();

                if ($existing !== null) {
                    $existing->update($attributes);

                    continue;
                }

                CrmSalesPlan::query()->create($attributes + [
                    'period_month' => $period->toDateString(),
                    'target_type' => PlanTarget::MANAGER->value,
                    'target_id' => $order->personal_manager_id,
                ]);
            }

            $order->forceFill([
                'status' => MotivationPlanOrder::STATUS_APPROVED,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();
        });

        return $order->refresh();
    }

    /**
     * Упоминает ли обоснование перевыполнение как причину повышения.
     *
     * Проверка по словам, а не по смыслу: она не ловит обход, но делает норму
     * видимой в тот момент, когда её нарушают, — этого достаточно, потому что
     * приказ подписывает человек и обоснование читает другой человек.
     */
    private function mentionsOverperformance(string $comment): bool
    {
        $normalized = mb_strtolower($comment);

        foreach (['перевыполн', 'превыси', 'превышени', 'перевыполнил'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
