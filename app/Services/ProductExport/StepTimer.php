<?php

namespace App\Services\ProductExport;

/**
 * Аккумулирует длительности этапов генерации выгрузки.
 *
 * Why: чтобы понять, на что уходит время («все товары» долго), нужны замеры
 * по шагам — query / eager_load / price_map / stock_map / map_rows / write_format.
 * Без них любые оптимизации — догадки. Дампится в product_export_runs.steps_json
 * после успешной (или упавшей) генерации.
 *
 * Использование:
 *   $timer = new StepTimer;
 *   $timer->measure('query', fn () => $builder->get());
 *   $end = $timer->start('map_rows');
 *   …
 *   $end();
 *   $timer->toArray(); // ['query' => 120, 'map_rows' => 9500]
 *
 * Все значения — миллисекунды (int). Параллельные таймеры одного ключа
 * аккумулируются (sum), так что вызовы внутри chunk-цикла дают суммарное
 * время по всем итерациям.
 */
class StepTimer
{
    /** @var array<string, int> миллисекунды */
    protected array $totals = [];

    /**
     * Начать замер шага. Возвращает callable, который завершит замер и
     * добавит дельту в общий счёт ключа.
     *
     * Удобно внутри chunk-цикла:
     *   $end = $timer->start('eager_load');
     *   $products = $query->with(...)->get();
     *   $end();
     */
    public function start(string $key): callable
    {
        $startedAt = microtime(true);

        return function () use ($key, $startedAt): void {
            $delta = (int) round((microtime(true) - $startedAt) * 1000);
            $this->totals[$key] = ($this->totals[$key] ?? 0) + $delta;
        };
    }

    /**
     * Засечь время выполнения колбэка и вернуть его результат.
     */
    public function measure(string $key, callable $callback): mixed
    {
        $end = $this->start($key);
        try {
            return $callback();
        } finally {
            $end();
        }
    }

    /**
     * Добавить произвольную дельту в миллисекундах. Нужно для случаев,
     * когда время уже измерено снаружи (например, queued_for_ms).
     */
    public function add(string $key, int $milliseconds): void
    {
        $this->totals[$key] = ($this->totals[$key] ?? 0) + $milliseconds;
    }

    /**
     * Текущее значение по ключу.
     */
    public function get(string $key): int
    {
        return $this->totals[$key] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->totals;
    }
}
