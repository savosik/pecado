<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;

/**
 * «Сколько будет, если…» — гипотетические входы для калькулятора ползунков.
 *
 * Считает всё тот же PayrollCalculator, поэтому калькулятор и зарплата не могут
 * разойтись. Кривые дохода отсюда убраны вместе с графиками, которые их рисовали.
 */
class PayrollWhatIfService
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    /**
     * Пометить N плановых клиентов купившими — крупные по плану первыми.
     *
     * @return list<array<string, mixed>>
     */
    public function withActive(PayrollInputs $inputs, int $count): array
    {
        $rows = $inputs->plannedClients;
        usort($rows, fn (PlannedClientInput $a, PlannedClientInput $b): int => ($b->plan ?? 0) <=> ($a->plan ?? 0));

        $result = [];
        foreach ($rows as $index => $client) {
            $result[] = (new PlannedClientInput(
                $client->id,
                $client->name,
                $client->plan,
                $index < $count ? max(1.0, (float) ($client->plan ?? 1.0)) : 0.0,
            ))->toArray();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function factorMeta(PayrollBreakdown $breakdown): array
    {
        $kpi = $breakdown->component('kpi_bonus');

        foreach ($kpi === null ? [] : $kpi->children as $child) {
            if ($child->key === 'active_clients') {
                return $child->meta;
            }
        }

        return [];
    }
}
