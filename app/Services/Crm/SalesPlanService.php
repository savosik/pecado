<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Месячные планы продаж: хранение и ввод.
 *
 * Расчёт факта, выполнения и burndown здесь намеренно отсутствует — он живёт
 * в {@see PlanProgressService} поверх `ShipmentAnalyticsService`. Второй движок
 * расчёта продаж означал бы, что `/crm/plans` и `/crm/analytics` рано или поздно
 * разойдутся в цифрах, и объяснить это расхождение будет нечем.
 */
class SalesPlanService
{
    /**
     * Русские названия месяцев.
     *
     * Локаль приложения — `en`, поэтому `translatedFormat()` дал бы «August»
     * в интерфейсе, который обязан быть русским.
     *
     * @var list<string>
     */
    private const MONTHS = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    /**
     * Планы, доступные актору на чтение.
     *
     * Менеджер видит план отдела (общая цель), свой собственный план и планы своих
     * партнёров. Планы соседних менеджеров и их партнёров — только у того, кто видит
     * весь отдел: чужая цифра выручки не его дело.
     *
     * @return Builder<CrmSalesPlan>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmSalesPlan::query();

        if ($actor->can('crm-clients-all.view')) {
            return $query;
        }

        $managerId = $actor->managerProfile?->id;

        return $query->where(fn (Builder $inner) => $inner
            ->where('target_type', PlanTarget::DEPARTMENT->value)
            ->when($managerId !== null, fn (Builder $q) => $q->orWhere(fn (Builder $mine) => $mine
                ->where('target_type', PlanTarget::MANAGER->value)
                ->where('target_id', $managerId))));
    }

    /**
     * Может ли актор ставить план этой цели с экрана «Планы продаж».
     *
     * С экрана ставится только план отдела — тем, кто отвечает за отдел целиком.
     * План менеджера пишет приказ на квартал ({@see \App\Services\Motivation\PlanOrderService}),
     * планов на партнёра больше нет: они были инструментом методики «сверху вниз»
     * и из расчёта оплаты исключены (решение РОПа 14.09.2026).
     */
    public function canManage(User $actor, PlanTarget $type, ?int $targetId): bool
    {
        return $type === PlanTarget::DEPARTMENT
            && $actor->can('crm-plans.edit')
            && $actor->can('crm-clients-all.view');
    }

    /**
     * Поставить или обновить план.
     *
     * `$amount === null` — снять план: пустая ячейка в сетке означает «плана нет»,
     * а не «план ноль». Ноль — это осознанное «в этом месяце не продаём».
     */
    public function set(
        PlanTarget $type,
        ?int $targetId,
        CarbonInterface $month,
        ?float $amount,
        User $actor,
        ?string $comment = null,
    ): ?CrmSalesPlan {
        $keys = [
            'period_month' => CrmSalesPlan::normalizeMonth($month),
            'target_type' => $type->value,
            'target_id' => CrmSalesPlan::targetKey($type, $targetId),
        ];

        if ($amount === null) {
            CrmSalesPlan::query()->where($keys)->delete();

            return null;
        }

        return CrmSalesPlan::query()->updateOrCreate($keys, [
            'amount' => round($amount, 2),
            'author_id' => (int) $actor->getKey(),
            'comment' => $comment,
        ]);
    }

    /**
     * Массовое сохранение сетки.
     *
     * Строки, которые актору не разрешены, тихо пропускаются, а не роняют запрос:
     * сетка отправляется целиком, и одна чужая ячейка не должна отменять правку
     * остальных двадцати. Что именно сохранилось, возвращается вызывающему.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{saved: int, removed: int, skipped: int}
     */
    public function bulkSet(array $rows, User $actor, CarbonInterface $defaultMonth): array
    {
        $saved = 0;
        $removed = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $actor, $defaultMonth, &$saved, &$removed, &$skipped): void {
            foreach ($rows as $row) {
                $type = PlanTarget::tryFrom((string) ($row['target_type'] ?? ''));

                if ($type === null) {
                    $skipped++;

                    continue;
                }

                $targetId = isset($row['target_id']) ? (int) $row['target_id'] : null;

                if (! $this->canManage($actor, $type, $targetId)) {
                    $skipped++;

                    continue;
                }

                $month = isset($row['month'])
                    ? $this->parseMonth((string) $row['month'])
                    : $defaultMonth;

                $amount = $this->parseAmount($row['amount'] ?? null);
                $comment = isset($row['comment']) ? (string) $row['comment'] : null;

                $plan = $this->set($type, $targetId, $month, $amount, $actor, $comment);

                $plan === null ? $removed++ : $saved++;
            }
        });

        return ['saved' => $saved, 'removed' => $removed, 'skipped' => $skipped];
    }

    /**
     * Планы периода, разложенные по цели: 'department' => сумма, 'manager:7' => сумма.
     *
     * @return array<string, CrmSalesPlan>
     */
    public function indexedByTarget(User $actor, CarbonInterface $month): array
    {
        return $this->visibleTo($actor)
            ->forPeriod($month)
            ->get()
            ->keyBy(fn (CrmSalesPlan $plan): string => $this->planKey($plan))
            ->all();
    }

    /**
     * Строки менеджеров: план месяца, план прошлого месяца и приказ, которым он поставлен.
     *
     * Правится план менеджера только приказом на квартал — здесь он читается.
     *
     * @param  array<string, CrmSalesPlan>  $plans
     * @param  array<string, CrmSalesPlan>  $previous
     * @return list<array<string, mixed>>
     */
    public function managerRows(User $actor, CarbonInterface $month, array $plans, array $previous): array
    {
        // 0 вместо отсутствующей карточки менеджера: сотрудник без привязки
        // к personal_managers не должен увидеть строки всего отдела.
        $ownManagerId = $actor->managerProfile->id ?? 0;

        $managers = PersonalManager::query()
            ->select('id', 'name')
            // Скрытые карточки в сетку не попадают: ставить план уволившемуся
            // и техническому дублю — ровно тот мусор, ради которого флаг заведён.
            // Карточки без расчёта оплаты (РОП с пулом ничейных) — тоже: личный
            // план есть только у того, кому он идёт в оплату.
            ->active()
            ->where('payroll_enabled', true)
            ->when(
                ! $actor->can('crm-clients-all.view'),
                fn (Builder $query) => $query->whereKey($ownManagerId),
            )
            ->orderBy('name')
            ->get();

        $quarter = CarbonImmutable::instance($month)->startOfQuarter();
        $orders = MotivationPlanOrder::query()
            ->forQuarter($quarter)
            ->where('status', MotivationPlanOrder::STATUS_APPROVED)
            ->orderBy('version')
            ->get()
            ->keyBy('personal_manager_id');

        return $managers->map(function (PersonalManager $manager) use ($plans, $previous, $orders, $quarter): array {
            $key = PlanTarget::MANAGER->value.':'.$manager->id;
            $order = $orders[$manager->id] ?? null;

            return [
                'id' => $manager->id,
                'name' => $manager->name,
                'amount' => isset($plans[$key]) ? $plans[$key]->amountValue() : null,
                'previous_amount' => isset($previous[$key]) ? $previous[$key]->amountValue() : null,
                'comment' => $plans[$key]->comment ?? null,
                'order' => $order === null ? null : [
                    'id' => (int) $order->getKey(),
                    'version' => (int) $order->version,
                    'approved_at' => $order->approved_at?->toIso8601String(),
                    'quarter' => $quarter->format('Y-m'),
                ],
            ];
        })->all();
    }

    /**
     * Разбор месяца из адреса: '2026-08' или '2026-08-01'. Мусор — текущий месяц.
     */
    public function parseMonth(?string $value): Carbon
    {
        if ($value === null || trim($value) === '') {
            return CrmSalesPlan::normalizeMonth(Carbon::now());
        }

        try {
            $parsed = Carbon::parse(strlen(trim($value)) === 7 ? trim($value).'-01' : trim($value));
        } catch (\Throwable) {
            return CrmSalesPlan::normalizeMonth(Carbon::now());
        }

        return CrmSalesPlan::normalizeMonth($parsed);
    }

    public function monthLabel(CarbonInterface $month): string
    {
        return self::MONTHS[$month->month - 1].' '.$month->year;
    }

    /**
     * Ключ цели плана: 'department', 'manager:7'.
     */
    private function planKey(CrmSalesPlan $plan): string
    {
        return $plan->target_type->needsTarget()
            ? $plan->target_type->value.':'.$plan->target_id
            : $plan->target_type->value;
    }

    /**
     * Сумма из формы: пустая строка — снять план, а не поставить ноль.
     */
    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
