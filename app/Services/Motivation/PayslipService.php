<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationObjection;
use App\Models\PayrollCalculation;
use App\Models\User;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Расчётный лист по Положению 2.2 (карточка mot-31; п. 11.2–11.3).
 *
 * Читается из снимка и только из него: утверждённый лист не меняется, спор
 * о цифре решается чтением, а не пересчётом. Все версии месяца сохраняются
 * и переключаются; исправление утверждённого — только новой версией.
 *
 * Возражение доступно после утверждения в срок из приказа (рабочих дней
 * от даты утверждения — решение заказчика от 08.09.2026: срок идёт от даты
 * утверждения, а не от пятого числа). Оно не меняет сумму: адресуется
 * руководителю, который либо переоткрывает месяц, либо отвечает отказом.
 */
class PayslipService
{
    public function __construct(
        private readonly MotivationPresenter $presenter,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * Снимок нужной версии месяца; null — версии нет.
     */
    public function version(int $managerId, CarbonInterface $month, ?int $version): ?PayrollCalculation
    {
        $query = PayrollCalculation::query()->forManager($managerId)->forPeriod($month);

        return $version === null
            ? $query->orderByDesc('version')->first()
            : $query->where('version', $version)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PayrollCalculation $calculation): array
    {
        $presented = $this->presenter->present($calculation);
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;

        $documents = $motivation->documents ?? [];
        $groups = ['base' => [], 'new' => []];

        foreach ($documents as $document) {
            $group = ($document['group'] ?? 'base') === 'new' ? 'new' : 'base';
            $partnerId = (int) ($document['partner_id'] ?? 0);

            $groups[$group][$partnerId] ??= [
                'partner_id' => $partnerId,
                'partner_name' => (string) ($document['partner_name'] ?? ''),
                'amount' => 0.0,
                'documents' => [],
            ];
            $groups[$group][$partnerId]['amount'] += (float) ($document['amount'] ?? 0);
            $groups[$group][$partnerId]['documents'][] = [
                'number' => (string) ($document['number'] ?? ''),
                'date' => $document['date'] ?? null,
                'amount' => (float) ($document['amount'] ?? 0),
            ];
        }

        foreach ($groups as &$partners) {
            foreach ($partners as &$partner) {
                $partner['amount'] = Money::round($partner['amount']);
            }
            unset($partner);
            usort($partners, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        }
        unset($partners);

        return [
            'calculation' => [
                'id' => (int) $calculation->getKey(),
                'version' => (int) $calculation->version,
                'status' => $calculation->status,
                'status_label' => $calculation->statusLabel(),
                'frozen' => $calculation->isFrozen(),
                'on_scheme_v2' => $presented['on_scheme_v2'],
                'computed_at' => $calculation->computed_at?->toIso8601String(),
                'approved_at' => $calculation->approved_at?->toIso8601String(),
                'paid_at' => $calculation->paid_at?->toIso8601String(),
                'comment' => $calculation->comment,
                'total' => (float) $calculation->total,
            ],
            'versions' => $this->versions($calculation),
            'lines' => $presented['lines'],
            'plan' => $presented['plan'],
            'days' => $presented['days'],
            'shipments' => [
                'base' => array_values($groups['base']),
                'new' => array_values($groups['new']),
                'documents_count' => count($documents),
            ],
            'returns' => $motivation->returnRows ?? [],
            'overdue' => $motivation->overdueRows ?? [],
            'excluded' => $motivation->overdueExcludedRows ?? [],
            'corrections' => array_map(fn ($row): array => (array) $row, $inputs->corrections === [] ? [] : array_map(fn ($c) => $c->toArray(), $inputs->corrections)),
            'objection' => $this->objection($calculation),
            'warnings' => $presented['warnings'],
        ];
    }

    /**
     * Возражение: можно ли подать и что уже подано.
     *
     * @return array<string, mixed>
     */
    public function objection(PayrollCalculation $calculation, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        $days = max(0, (int) config('motivation.default_parameters.objection_working_days', 5));
        $items = MotivationObjection::query()
            ->where('calculation_id', $calculation->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (MotivationObjection $row): array => [
                'id' => (int) $row->getKey(),
                'reason' => $row->reason,
                'status' => $row->status,
                'status_label' => $row->statusLabel(),
                'created_at' => $row->created_at?->toIso8601String(),
                'response' => $row->response,
                'responded_at' => $row->responded_at?->toIso8601String(),
            ])
            ->all();

        $hasOpen = collect($items)->contains(fn (array $row): bool => $row['status'] === MotivationObjection::STATUS_OPEN);

        if (! $calculation->isFrozen() || $calculation->approved_at === null) {
            return ['can_object' => false, 'deadline_on' => null, 'reason' => 'Возражение подаётся после утверждения расчёта.', 'items' => $items];
        }

        $deadline = $this->deadline(CarbonImmutable::instance($calculation->approved_at)->startOfDay(), $days);

        if ($hasOpen) {
            return ['can_object' => false, 'deadline_on' => $deadline->toDateString(), 'reason' => 'Возражение уже подано и ждёт ответа.', 'items' => $items];
        }

        if ($today->greaterThan($deadline)) {
            return ['can_object' => false, 'deadline_on' => $deadline->toDateString(), 'reason' => sprintf('Срок возражения — %d рабочих дней после утверждения — истёк %s.', $days, $deadline->format('d.m.Y')), 'items' => $items];
        }

        return ['can_object' => true, 'deadline_on' => $deadline->toDateString(), 'reason' => null, 'items' => $items];
    }

    /**
     * Подать возражение.
     *
     * @throws \InvalidArgumentException
     */
    public function object(PayrollCalculation $calculation, User $actor, string $reason): MotivationObjection
    {
        $state = $this->objection($calculation);

        if (! $state['can_object']) {
            throw new \InvalidArgumentException((string) $state['reason']);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new \InvalidArgumentException('Опишите причину возражения — хотя бы одним предложением.');
        }

        return MotivationObjection::query()->create([
            'calculation_id' => $calculation->getKey(),
            'personal_manager_id' => $calculation->personal_manager_id,
            'author_id' => $actor->getKey(),
            'reason' => trim($reason),
            'status' => MotivationObjection::STATUS_OPEN,
        ]);
    }

    /**
     * Последний день, когда возражение ещё принимается: N рабочих дней после утверждения.
     */
    private function deadline(CarbonImmutable $approvedOn, int $workingDays): CarbonImmutable
    {
        $day = $approvedOn;

        for ($counted = 0; $counted < $workingDays;) {
            $day = $day->addDay();

            if ($this->calendar->isWorkingDay($day)) {
                $counted++;
            }
        }

        return $day;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versions(PayrollCalculation $calculation): array
    {
        return PayrollCalculation::query()
            ->forManager((int) $calculation->personal_manager_id)
            ->forPeriod($calculation->period_month)
            ->orderByDesc('version')
            ->get(['id', 'version', 'status', 'approved_at', 'total', 'comment'])
            ->map(fn (PayrollCalculation $row): array => [
                'id' => (int) $row->getKey(),
                'version' => (int) $row->version,
                'status' => $row->status,
                'status_label' => $row->statusLabel(),
                'approved_at' => $row->approved_at?->toIso8601String(),
                'total' => (float) $row->total,
                'comment' => $row->comment,
                'is_current' => (int) $row->getKey() === (int) $calculation->getKey(),
            ])
            ->all();
    }
}
