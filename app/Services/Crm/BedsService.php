<?php

namespace App\Services\Crm;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * «Грядки»: план периода одной картинкой.
 *
 * Витрина, а не расчёт. Площадь плитки и её заливка собираются из уже посчитанного:
 * план и факт — из {@see PlanProgressService} и {@see ClientPlanFactService},
 * оборот и признак «спит» — из сигналов {@see OpportunityService}. Своих запросов
 * к отгрузкам здесь нет ни одного: цифра на плитке обязана совпадать с цифрой
 * в «Планах продаж» для того же клиента, а два движка расчёта совпадать перестают
 * на первой же правке одного из них.
 */
class BedsService
{
    /**
     * Сколько плиток рисуем максимум.
     *
     * Дальше площадь становится меньше подписи, и картинка перестаёт отвечать
     * на вопрос «куда идти» — превращается в мозаику. Хвост уходит в остаток.
     */
    private const MAX_TILES = 60;

    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly PlanProgressService $progress,
    ) {}

    /**
     * Полотно клиентов: плитка на клиента внутри плана скоупа.
     *
     * @return array<string, mixed>
     */
    public function clients(CarbonInterface $month, PlanScope $scope, User $actor): array
    {
        $signals = $this->opportunities->signals($month, $scope);

        $tiles = [];

        foreach ($signals as $row) {
            $tile = $this->clientTile($row);

            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }

        return $this->canvas('clients', $month, $scope, $tiles);
    }

    /**
     * Полотно отдела: плитка на менеджера. Только для того, кто видит отдел целиком —
     * `byManager()` сам вернёт пустой список остальным.
     *
     * @return array<string, mixed>
     */
    public function managers(CarbonInterface $month, PlanScope $scope, User $actor): array
    {
        $tiles = [];

        foreach ($this->progress->byManager($month, $actor) as $row) {
            $plan = $row['plan'] === null ? null : (float) $row['plan'];
            $fact = (float) $row['fact'];

            // Площадь менеджера без плана — его факт: «сколько он весит сейчас».
            // Потенциала за год здесь не считаем — это уже другой вопрос,
            // и ради него пришлось бы поднимать годовые агрегаты по всему отделу.
            $area = $plan !== null && $plan > 0 ? $plan : $fact;

            if ($area <= 0) {
                continue;
            }

            $tiles[] = [
                'id' => (int) $row['manager_id'],
                'name' => (string) $row['name'],
                'area' => round($area, 2),
                'area_source' => $plan !== null && $plan > 0 ? 'plan' : 'fact',
                'plan' => $plan,
                'fact' => $fact,
                'percent' => $row['percent'],
                'lag' => $plan !== null ? round(max(0.0, $plan - $fact), 2) : null,
                'sleeping' => false,
                'clients_count' => (int) $row['clients_count'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $this->canvas('managers', $month, $scope, $tiles);
    }

    /**
     * Плитка клиента или null, если рисовать нечего.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function clientTile(array $row): ?array
    {
        $plan = $row['plan'] === null ? null : (float) $row['plan'];
        $fact = (float) $row['fact'];
        $yearAmount = (float) ($row['year_amount'] ?? 0.0);

        // Потенциал — средний месяц за последние 12. Карточка предполагала лучший
        // месяц, но он требует двенадцати помесячных агрегатов по всему скоупу
        // ради числа, которое задаёт только размер плитки; вдобавок «лучший»
        // скачет от месяца к месяцу, и полотно перекладывалось бы без причины.
        $potential = $yearAmount > 0 ? $yearAmount / 12 : 0.0;

        $area = $plan !== null && $plan > 0 ? $plan : $potential;

        // Ни плана, ни истории — плитки нет. На проде таких большинство, и без
        // этого отсева полотно превращается в песок из нулевых клиентов.
        if ($area <= 0) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'area' => round($area, 2),
            'area_source' => $plan !== null && $plan > 0 ? 'plan' : 'potential',
            'plan' => $plan,
            'fact' => round($fact, 2),
            'percent' => $row['percent'],
            'lag' => $row['lag'],
            // «Заросла»: не покупает дольше обычного цикла — тот же признак,
            // по которому клиент попадает в пресет «спящие» на «Возможностях».
            'sleeping' => (int) ($row['overdue_days'] ?? 0) > 0,
            'overdue_days' => $row['overdue_days'],
            'days_since' => $row['days_since'],
            'last_purchase_at' => $row['last_purchase_at'],
            'cycle_days' => $row['cycle_days'],
            'abc' => $row['abc'],
            'manager' => $row['manager'],
        ];
    }

    /**
     * Общая обёртка полотна: плитки, план периода и нераспределённый остаток.
     *
     * @param  list<array<string, mixed>>  $tiles
     * @return array<string, mixed>
     */
    private function canvas(string $mode, CarbonInterface $month, PlanScope $scope, array $tiles): array
    {
        usort($tiles, fn (array $a, array $b): int => $b['area'] <=> $a['area']);

        $shown = array_slice($tiles, 0, self::MAX_TILES);
        $hidden = array_slice($tiles, self::MAX_TILES);

        $summary = $this->progress->progress($month, $scope);
        $scopePlan = $summary['plan'];

        // Разложено по плиткам — только то, что реально стоит планом. Площадь
        // от потенциала планом не является и в остаток не засчитывается: иначе
        // «распределено» показывало бы больше, чем поставил руководитель.
        $allocated = array_sum(array_map(
            fn (array $t): float => $t['area_source'] === 'plan' ? (float) $t['area'] : 0.0,
            $tiles,
        ));

        return [
            'mode' => $mode,
            'tiles' => $shown,
            'plan' => $scopePlan,
            'fact' => $summary['fact'],
            'percent' => $summary['percent'],
            'allocated' => round($allocated, 2),
            // Отрицательного остатка не показываем: «расписано больше плана» —
            // это про сверку распределения, и она живёт на экране планов.
            'unallocated' => $scopePlan !== null ? round(max(0.0, $scopePlan - $allocated), 2) : null,
            'summary' => [
                'tiles' => count($shown),
                'hidden' => count($hidden),
                'hidden_area' => round(array_sum(array_column($hidden, 'area')), 2),
                'sleeping' => count(array_filter($tiles, fn (array $t): bool => $t['sleeping'])),
                'without_plan' => count(array_filter($tiles, fn (array $t): bool => $t['plan'] === null)),
            ],
        ];
    }
}
