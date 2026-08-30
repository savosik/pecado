<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Contracts\PayrollComponent;

/**
 * Каталог компонентов дохода и факторов KPI — обёртка над config/payroll.php.
 *
 * Ключ, которого здесь нет, схема применить не может: это и реестр, и граница
 * того, что вообще умеет считать система.
 */
class PayrollCatalog
{
    /** @var array<string, PayrollComponent> */
    private array $instances = [];

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('payroll.components', []));
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, (array) config('payroll.components', []));
    }

    public function component(string $key): PayrollComponent
    {
        return $this->resolve('payroll.components', $key);
    }

    /**
     * @return list<string>
     */
    public function factorKeys(): array
    {
        return array_keys((array) config('payroll.kpi_factors', []));
    }

    public function factor(string $key): PayrollComponent
    {
        return $this->resolve('payroll.kpi_factors', $key);
    }

    /**
     * Тексты для экрана: что означает каждый показатель и как он считается.
     *
     * @return array<string, array{label: string, description: string, how_computed: string, kind: string}>
     */
    public function explanations(): array
    {
        $rows = [];

        foreach ($this->keys() as $key) {
            $rows[$key] = $this->describe($this->component($key));
        }

        foreach ($this->factorKeys() as $key) {
            $rows[$key] = $this->describe($this->factor($key));
        }

        return $rows;
    }

    /**
     * @return array{label: string, description: string, how_computed: string, kind: string}
     */
    private function describe(PayrollComponent $component): array
    {
        return [
            'label' => $component->label(),
            'description' => $component->description(),
            'how_computed' => $component->howComputed(),
            'kind' => $component->kind()->value,
        ];
    }

    private function resolve(string $configKey, string $key): PayrollComponent
    {
        $cacheKey = $configKey.':'.$key;

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        $class = config($configKey.'.'.$key);

        if (! is_string($class) || ! class_exists($class)) {
            throw new \InvalidArgumentException("Компонент зарплаты «{$key}» не зарегистрирован в config/payroll.php");
        }

        $instance = app($class);

        if (! $instance instanceof PayrollComponent) {
            throw new \InvalidArgumentException("Класс {$class} не реализует PayrollComponent");
        }

        return $this->instances[$cacheKey] = $instance;
    }
}
