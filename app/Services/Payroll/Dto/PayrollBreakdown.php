<?php

namespace App\Services\Payroll\Dto;

use App\Enums\Payroll\ComponentKind;

/**
 * Разбор дохода за месяц: компоненты в порядке схемы и итог.
 */
final class PayrollBreakdown
{
    /**
     * @param  list<ComponentResult>  $components
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $components,
        public readonly float $total,
        public readonly array $warnings = [],
    ) {}

    public function component(string $key): ?ComponentResult
    {
        foreach ($this->components as $component) {
            if ($component->key === $key) {
                return $component;
            }
        }

        return null;
    }

    /**
     * Рубли по компоненту; отсутствующий или выключенный — ноль.
     */
    public function amountOf(string $key): float
    {
        $component = $this->component($key);

        return $component === null ? 0.0 : (float) ($component->amount ?? 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'components' => array_map(fn (ComponentResult $c): array => $c->toArray(), $this->components),
            'total' => $this->total,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Сумма всех amount-компонентов — то, что попадает в итог.
     *
     * @param  list<ComponentResult>  $components
     */
    public static function sum(array $components): float
    {
        $total = 0.0;

        foreach ($components as $component) {
            if ($component->kind === ComponentKind::AMOUNT) {
                $total += (float) ($component->amount ?? 0.0);
            }
        }

        return round($total, 2);
    }
}
