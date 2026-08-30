<?php

namespace App\Services\Payroll;

use App\Models\PayrollCalculation;

/**
 * Снимок расчёта в форме для экрана: то, что лежит в JSON, плюс короткие сводки.
 *
 * Страница и polling-ответ обязаны показывать одно и то же, поэтому презентер один.
 */
class PayrollCalculationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(PayrollCalculation $calculation): array
    {
        $inputs = (array) $calculation->inputs;
        $breakdown = (array) $calculation->breakdown;
        $params = (array) $calculation->params_effective;

        $plan = isset($inputs['plan']) ? (float) $inputs['plan'] : null;
        $revenue = (float) ($inputs['revenue'] ?? 0);
        $planned = array_values((array) ($inputs['planned_clients'] ?? []));
        $active = array_values(array_filter($planned, fn (array $c): bool => (float) ($c['fact'] ?? 0) > 0));

        $kpi = $this->component($breakdown, 'kpi_bonus');
        $byComponent = (array) ($params['by_component'] ?? []);

        return [
            'id' => (int) $calculation->getKey(),
            'status' => $calculation->status,
            'status_label' => $calculation->statusLabel(),
            'is_frozen' => $calculation->isFrozen(),
            'version' => (int) $calculation->version,
            'computed_at' => $calculation->computed_at?->toIso8601String(),
            'approved_at' => $calculation->approved_at?->toIso8601String(),
            'paid_at' => $calculation->paid_at?->toIso8601String(),
            'comment' => $calculation->comment,
            'total' => (float) $calculation->total,
            'breakdown' => $breakdown,
            'warnings' => array_values((array) ($breakdown['warnings'] ?? [])),
            'kpi' => $kpi === null ? null : [
                'amount' => (float) ($kpi['amount'] ?? 0),
                'performance' => $kpi['meta']['performance'] ?? null,
                'ratio' => $kpi['meta']['ratio'] ?? null,
                'multiplier' => $kpi['meta']['multiplier'] ?? null,
                'penalty' => $kpi['meta']['penalty'] ?? null,
                'adjusted' => $kpi['meta']['adjusted'] ?? null,
                'base' => $kpi['meta']['base'] ?? null,
                'cap' => $kpi['meta']['cap'] ?? null,
                'max_amount' => $kpi['meta']['max_amount'] ?? null,
                'capped' => (bool) ($kpi['meta']['capped'] ?? false),
                'without_penalty' => $kpi['meta']['without_penalty'] ?? null,
                'without_multiplier' => $kpi['meta']['without_multiplier'] ?? null,
            ],
            'inputs' => [
                'plan' => $plan,
                'revenue' => $revenue,
                'percent' => $plan !== null && $plan > 0 ? $revenue / $plan : null,
                'remaining' => $plan !== null ? max(0.0, round($plan - $revenue, 2)) : null,
                'planned_clients' => $planned,
                'planned_count' => count($planned),
                'active_count' => count($active),
                'active_share' => count($planned) > 0 ? count($active) / count($planned) : null,
                'invoices' => array_values((array) ($inputs['invoices'] ?? [])),
                'at_risk_invoices' => array_values((array) ($inputs['at_risk_invoices'] ?? [])),
                'extra_items' => array_values((array) ($inputs['extra_items'] ?? [])),
                'corrections' => array_values((array) ($inputs['corrections'] ?? [])),
                'new_clients' => array_values((array) ($inputs['new_clients'] ?? [])),
                'working_days' => (array) ($inputs['working_days'] ?? []),
                'collected_at' => $inputs['collected_at'] ?? null,
            ],
            'params' => [
                'salary' => (array) ($byComponent['salary'] ?? []),
                'kpi_bonus' => (array) ($byComponent['kpi_bonus'] ?? []),
                'sources' => (array) ($params['sources'] ?? []),
                'enabled' => array_values(array_map(
                    fn (array $entry): string => (string) $entry['key'],
                    array_filter((array) ($params['order'] ?? []), fn ($entry): bool => is_array($entry) && (bool) ($entry['enabled'] ?? false)),
                )),
            ],
            'forecast' => $calculation->forecast,
        ];
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>|null
     */
    private function component(array $breakdown, string $key): ?array
    {
        foreach ((array) ($breakdown['components'] ?? []) as $component) {
            if (is_array($component) && ($component['key'] ?? null) === $key) {
                return $component;
            }
        }

        return null;
    }
}
