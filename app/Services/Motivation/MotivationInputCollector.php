<?php

namespace App\Services\Motivation;

use App\Models\ManagerAbsence;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\ProductReturn;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Motivation\Dto\MotivationInputs;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Сбор входов переменной части за месяц (эпик mot-00, карточка mot-22).
 *
 * Выручка — только через {@see ShipmentAnalyticsService}, тремя обращениями
 * с разными фильтрами: партнёры базы, партнёры в Периоде новизны, позиции
 * Фокус-перечня. Свой SUM по отгрузкам был бы вторым движком выручки, и цифры
 * на «Моём месяце» разошлись бы с «Планами продаж» без объяснимой причины.
 *
 * Первые две группы взаимоисключающи (п. 6.3.3): партнёр либо в базе, либо
 * в новизне. Третья пересекается с обеими (п. 6.4.2) и считается по всем
 * партнёрам работника — это не двойной счёт, а разные показатели: за объём
 * и за состав проданного.
 */
class MotivationInputCollector
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly FocusRangeResolver $focus,
        private readonly PartnerAttributionResolver $attribution,
        private readonly OverdueDebtIntegrator $debts,
        private readonly WorkingCalendar $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $params  действующие параметры переменной части
     */
    public function collect(int $managerId, CarbonInterface $month, array $params = []): MotivationInputs
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $names = $this->attribution->partnersOf($managerId, $period->endOfMonth());
        $partnerIds = array_keys($names);

        [$basePartners, $newPartners] = $this->splitByNovelty($partnerIds, $period);

        $returns = $this->returns($basePartners, $newPartners, $period);
        $focusItems = $this->focus->itemsFor($period);

        $baseRevenue = $this->revenue($basePartners, $period) - $returns['base'];
        $newRevenue = $this->revenue($newPartners, $period) - $returns['new'];
        // Пустой перечень — это ноль, а не «все товары»: фильтр по пустому списку
        // товаров в аналитике означает отсутствие ограничения, и П3 молча стал бы
        // равен всей выручке.
        $focusRevenue = $focusItems === []
            ? 0.0
            : $this->revenue($partnerIds, $period, array_keys($focusItems)) - $returns['focus'];

        $overdue = $this->debts->forMonth(
            $partnerIds,
            $period,
            $names,
            (int) ($params['grace_working_days'] ?? config('motivation.default_parameters.grace_working_days', 5)),
        );

        $days = $this->days($managerId, $period);

        return new MotivationInputs(
            baseRevenue: Money::round(max(0.0, $baseRevenue)),
            newPartnersRevenue: Money::round(max(0.0, $newRevenue)),
            newPartnersCount: count($newPartners),
            focusRevenue: Money::round(max(0.0, $focusRevenue)),
            overdueIntegral: $overdue['integral'],
            workingDaysTotal: $days['total'],
            workedDays: $days['worked'],
            substitutionDays: $days['substitution'],
            guaranteeBase: null,
            baseRows: $this->partnerRows($basePartners, $names, $period),
            newRows: $this->partnerRows($newPartners, $names, $period),
            focusRows: $this->focusRows($partnerIds, $focusItems, $period),
            overdueRows: $overdue['rows'],
            overdueExcludedRows: $overdue['excluded'],
            returns: $returns,
        );
    }

    /**
     * Разделение партнёров на Закреплённую базу и находящихся в Периоде новизны.
     *
     * @param  list<int>  $partnerIds
     * @return array{0: list<int>, 1: list<int>}
     */
    private function splitByNovelty(array $partnerIds, CarbonImmutable $period): array
    {
        if ($partnerIds === []) {
            return [[], []];
        }

        $new = MotivationPartnerNovelty::query()
            ->whereIn('user_id', $partnerIds)
            ->newInMonth($period)
            ->pluck('user_id')
            ->map('intval')
            ->all();

        $base = array_values(array_diff($partnerIds, $new));

        return [$base, array_values($new)];
    }

    /**
     * Выручка через единственный источник — сервис аналитики отгрузок.
     *
     * @param  list<int>  $partnerIds
     * @param  list<int>  $productIds  пусто — все товары
     */
    private function revenue(array $partnerIds, CarbonImmutable $period, array $productIds = []): float
    {
        if ($partnerIds === []) {
            return 0.0;
        }

        $ctx = AnalyticsContext::forScope($partnerIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return 0.0;
        }

        $metrics = $this->analytics->metrics($ctx, new AnalyticsFilters(
            dateFrom: $period->startOfDay(),
            dateTo: $period->endOfMonth()->endOfDay(),
            productIds: $productIds,
        ));

        return round((float) $metrics['total_amount'], 2);
    }

    /**
     * Возвраты периода по группам (пп. 2.12, 6.2.1, 6.3.2, 6.4.1).
     *
     * У возвратов нет даты документа 1С — только дата записи, поэтому периодом
     * считается месяц оформления. На живых данных возвраты редки (за апрель–август
     * 2026 их четыре), но норма требует их вычитать, и вычитаются они из той же
     * группы, к которой отнесён партнёр.
     *
     * @param  list<int>  $basePartners
     * @param  list<int>  $newPartners
     * @return array{base: float, new: float, focus: float}
     */
    private function returns(array $basePartners, array $newPartners, CarbonImmutable $period): array
    {
        $sum = function (array $partnerIds) use ($period): float {
            if ($partnerIds === []) {
                return 0.0;
            }

            return Money::round((float) ProductReturn::query()
                ->whereIn('user_id', $partnerIds)
                ->where('status', \App\Enums\ReturnStatus::COMPLETED->value)
                ->whereBetween('created_at', [$period->startOfDay(), $period->endOfMonth()->endOfDay()])
                ->sum('total_amount'));
        };

        return [
            'base' => $sum($basePartners),
            'new' => $sum($newPartners),
            // Возврат фокусной позиции требует разбора по строкам возврата;
            // строки хранят product_id, но не привязаны к периоду перечня.
            // До появления такого разбора группа не уменьшается — это завышает
            // П3 на величину возвратов фокусных товаров и должно быть видно.
            'focus' => 0.0,
        ];
    }

    /**
     * Рабочие дни месяца, отработанные и дни замещения — по табелю отсутствий.
     *
     * @return array{total: int, worked: int, substitution: int}
     */
    private function days(int $managerId, CarbonImmutable $period): array
    {
        $total = $this->calendar->monthDays($period)['total'];

        $absent = $this->workingDaysOf(
            ManagerAbsence::query()->where('personal_manager_id', $managerId),
            $period,
        );

        $substitution = $this->workingDaysOf(
            ManagerAbsence::query()->where('substitute_manager_id', $managerId),
            $period,
        );

        return [
            'total' => $total,
            'worked' => max(0, $total - $absent),
            'substitution' => $substitution,
        ];
    }

    /**
     * Рабочих дней месяца, покрытых записями табеля.
     *
     * Считается по дням, а не по разнице дат: пересекающиеся записи не должны
     * складываться, а выходные внутри отпуска — уменьшать план.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ManagerAbsence>  $query
     */
    private function workingDaysOf($query, CarbonImmutable $period): int
    {
        $monthEnd = $period->endOfMonth()->startOfDay();

        $ranges = $query
            ->whereDate('starts_on', '<=', $monthEnd)
            ->whereDate('ends_on', '>=', $period)
            ->get(['starts_on', 'ends_on']);

        if ($ranges->isEmpty()) {
            return 0;
        }

        $count = 0;

        for ($day = $period; $day->lte($monthEnd); $day = $day->addDay()) {
            if (! $this->calendar->isWorkingDay($day)) {
                continue;
            }

            foreach ($ranges as $range) {
                $from = CarbonImmutable::instance($range->starts_on)->startOfDay();
                $to = CarbonImmutable::instance($range->ends_on)->startOfDay();

                if ($day->betweenIncluded($from, $to)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Улики П1 и П2: партнёры и их отгрузки за месяц.
     *
     * @param  list<int>  $partnerIds
     * @param  array<int, string>  $names
     * @return list<array<string, mixed>>
     */
    private function partnerRows(array $partnerIds, array $names, CarbonImmutable $period): array
    {
        if ($partnerIds === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($partnerIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        return $this->analytics
            ->byPartner($ctx, new AnalyticsFilters(
                dateFrom: $period->startOfDay(),
                dateTo: $period->endOfMonth()->endOfDay(),
            ), null)
            ->map(fn (array $row): array => [
                'partner_id' => $row['partner_id'] === null ? null : (int) $row['partner_id'],
                'partner_name' => (string) ($names[(int) ($row['partner_id'] ?? 0)] ?? $row['label'] ?? ''),
                'amount' => round((float) ($row['amount'] ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Улики П3: позиции перечня с отгрузками за месяц и действовавшей ставкой.
     *
     * @param  list<int>  $partnerIds
     * @param  array<int, float|null>  $focusItems
     * @return list<array<string, mixed>>
     */
    private function focusRows(array $partnerIds, array $focusItems, CarbonImmutable $period): array
    {
        if ($partnerIds === [] || $focusItems === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($partnerIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($this->analytics->byProduct($ctx, new AnalyticsFilters(
            dateFrom: $period->startOfDay(),
            dateTo: $period->endOfMonth()->endOfDay(),
            productIds: array_keys($focusItems),
        ), null) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);

            $rows[] = array_filter([
                'product_id' => $productId,
                'name' => (string) ($row['label'] ?? ''),
                'sku' => $row['sku'] ?? null,
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                // Ставка указывается, только если у позиции она своя: иначе
                // компонент применит общую ставку приказа.
                'rate' => $focusItems[$productId] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $rows;
    }
}
