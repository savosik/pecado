<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationParameterOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Действующие значения Приложения № 1 на период — единственная точка чтения.
 *
 * Зависит только от каталога, поэтому её можно внедрять в любой сервис домена
 * без кольца: ParameterOrderService тянет калькулятор и расчёты, и через него
 * параметр не прочитать из сборщика входов, который сам вызывается расчётом.
 *
 * Правило одно: config('motivation.default_parameters') — умолчание первой
 * материализации приказа, а не рабочее значение. Сервис, читающий конфиг
 * напрямую, делает приказ руководителя недействующим: экран «Параметры»
 * обещает, что значение действует с периода, а код его не видит. Страховка —
 * MotivationParameterConsumersTest.
 */
class EffectiveParameters
{
    public function __construct(private readonly ParameterCatalog $catalog) {}

    /**
     * Приказ, действующий в расчётном периоде; null — ни одного ещё не издано.
     */
    public function order(CarbonInterface $month): ?MotivationParameterOrder
    {
        return MotivationParameterOrder::effectiveFor($month);
    }

    /**
     * Полный набор на месяц: приказ поверх умолчаний Приложения № 1.
     *
     * @return array<string, mixed>
     */
    public function forMonth(CarbonInterface $month): array
    {
        return $this->catalog->complete((array) ($this->order($month)->values ?? []));
    }

    public function value(string $key, CarbonInterface $month): mixed
    {
        return $this->forMonth($month)[$key] ?? null;
    }

    public function int(string $key, CarbonInterface $month, int $default = 0): int
    {
        $value = $this->value($key, $month);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, CarbonInterface $month, float $default = 0.0): float
    {
        $value = $this->value($key, $month);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Приказ на квартал — тот, что действует на его первый месяц.
     *
     * Квартал — один акт с одними правилами. Исключение — переход: пока ни одного
     * приказа не было, первый изданный действует на квартал, в котором вступает
     * в силу, иначе сезон и прирост доходили бы до плана только со следующего.
     */
    public function quarterOrder(CarbonInterface $quarterStart): ?MotivationParameterOrder
    {
        $start = CarbonImmutable::instance($quarterStart)->startOfQuarter();

        return $this->order($start)
            ?? MotivationParameterOrder::query()
                ->whereDate('effective_from', '>', $start)
                ->whereDate('effective_from', '<', $start->addMonths(3))
                ->orderBy('effective_from')
                ->first();
    }

    /**
     * Полный набор на квартал.
     *
     * @return array<string, mixed>
     */
    public function forQuarter(CarbonInterface $quarterStart): array
    {
        return $this->catalog->complete((array) ($this->quarterOrder($quarterStart)->values ?? []));
    }
}
