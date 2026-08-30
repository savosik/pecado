<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollScheme;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\PayrollAdvisor;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollForecaster;
use App\Services\Payroll\PayrollParamsResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Прогноз и советы — тот же калькулятор на гипотетических входах. БД не нужна.
 */
class PayrollForecastTest extends TestCase
{
    private function params(): EffectiveParams
    {
        $scheme = new PayrollScheme(['components' => config('payroll.default_scheme.components')]);

        return app(PayrollParamsResolver::class)->fromScheme($scheme);
    }

    /**
     * Середина июля 2026: 15 июля — среда, прошло 11 рабочих дней из 23.
     */
    private function inputs(): PayrollInputs
    {
        $planned = [];
        for ($i = 1; $i <= 10; $i++) {
            $planned[] = new PlannedClientInput($i, "Партнёр {$i}", 100000.0 * (11 - $i), $i <= 6 ? 50000.0 : 0.0);
        }

        return new PayrollInputs(
            managerId: 1,
            month: '2026-07-01',
            plan: 2_000_000.0,
            revenue: 1_000_000.0,
            plannedClients: $planned,
            invoices: [],
            atRiskInvoices: [
                new InvoiceInput(1, '29УТ-000001', 1, 'Партнёр 1', 200_000.0, '2026-06-20', '2026-07-10', null, null, null, null, 'unpaid'),   // срок прошёл
                new InvoiceInput(2, '29УТ-000002', 2, 'Партнёр 2', 50_000.0, '2026-07-01', '2026-07-28', null, null, null, null, 'unpaid'),    // срок впереди
            ],
            workingDays: ['total' => 23, 'passed' => 11, 'left' => 12],
        );
    }

    #[Test]
    #[TestDox('Три сценария упорядочены и считаются тем же калькулятором')]
    public function scenarios_are_ordered(): void
    {
        $params = $this->params();
        $inputs = $this->inputs();
        $current = app(PayrollCalculator::class)->calculate($params, $inputs);

        $forecast = app(PayrollForecaster::class)->forecast($params, $inputs, $current, CarbonImmutable::parse('2026-07-15'));
        $s = $forecast['scenarios'];

        $this->assertLessThanOrEqual($s['base']['total'], $s['pessimistic']['total']);
        $this->assertLessThanOrEqual($s['optimistic']['total'], $s['base']['total']);
        // Предел месяца — выше оптимистичного ровно на цену уже случившихся задержек.
        $this->assertGreaterThanOrEqual($s['optimistic']['total'], $s['perfect']['total']);

        // Пессимистично: выручка не растёт, обе накладные оплачены в последний рабочий день.
        $this->assertSame(1_000_000.0, $s['pessimistic']['revenue']);
        $this->assertGreaterThan(0, $s['pessimistic']['penalty']);
        // Базово: темп 1 000 000 / 11 × 23.
        $this->assertEqualsWithDelta(2_090_909.09, $s['base']['revenue'], 0.01);
        // Оптимистично: все плановые активны, штраф только известный (нет).
        $this->assertSame(10, $s['optimistic']['active_clients']);
        $this->assertSame(0.0, $s['optimistic']['penalty']);
        $this->assertSame('Если пойдёт как идёт', $s['base']['label']);
        $this->assertStringContainsString('темпе месяца', $s['base']['hint']);
        $this->assertStringContainsString('2 неоплаченных накладных', $s['pessimistic']['hint']);
        $this->assertStringContainsString('все 10 плановых клиентов', $s['optimistic']['hint']);
        $this->assertStringContainsString('предел месяца', $s['perfect']['hint']);

        $this->assertCount(31, $forecast['curve']);
        $today = array_values(array_filter($forecast['curve'], fn (array $p): bool => $p['is_today']))[0];
        $this->assertSame($current->total, $today['low']);
        $this->assertNotNull($forecast['curve'][0]['earned']);
        $this->assertNull($forecast['curve'][30]['earned']);
        $this->assertSame($s['optimistic']['total'], $forecast['curve'][30]['high']);
    }

    #[Test]
    #[TestDox('Закрытый месяц: сценарии совпадают с фактом, советов нет')]
    public function closed_month_has_flat_forecast(): void
    {
        $params = $this->params();
        $inputs = $this->inputs()->with(['working_days' => ['total' => 23, 'passed' => 23, 'left' => 0]]);
        $current = app(PayrollCalculator::class)->calculate($params, $inputs);

        $forecast = app(PayrollForecaster::class)->forecast($params, $inputs, $current, CarbonImmutable::parse('2026-08-05'));

        $this->assertTrue($forecast['basis']['closed']);
        $this->assertSame($current->total, $forecast['scenarios']['pessimistic']['total']);
        $this->assertSame($current->total, $forecast['scenarios']['optimistic']['total']);
        $this->assertSame([], app(PayrollAdvisor::class)->advise($params, $inputs, $current, CarbonImmutable::parse('2026-08-05')));
    }

    #[Test]
    #[TestDox('Советы: ступень активных клиентов, накладные под риском, добить план, цена 100 000 ₽; сортировка по выигрышу')]
    public function advice_is_computed_and_sorted(): void
    {
        $params = $this->params();
        $inputs = $this->inputs();
        $current = app(PayrollCalculator::class)->calculate($params, $inputs);

        $advice = app(PayrollAdvisor::class)->advise($params, $inputs, $current, CarbonImmutable::parse('2026-07-15'));
        $byKey = [];
        foreach ($advice as $row) {
            $byKey[$row['key']] = $row;
        }

        // 6 из 10 = 60 % → до ступени 80 % не хватает 2 клиентов; множитель 0,8 → 0,9.
        $this->assertSame(2, count($byKey['active_clients']['target']['ids']));
        $this->assertStringContainsString('Ещё 2 плановых клиента', $byKey['active_clients']['title']);
        $expectedGain = 85000 * (0.9 - 0.8) * (1_000_000 / 2_000_000);
        $this->assertEqualsWithDelta($expectedGain, $byKey['active_clients']['gain'], 0.01);

        // Накладная с прошедшим сроком: задержка уже 3 раб. дн. (13, 14, 15 июля) — «каждый день дороже».
        $this->assertStringContainsString('уже с задержкой 3 раб. дн.', $byKey['invoice:1']['title']);
        $this->assertEqualsWithDelta(85000 * 0.8 * (200_000 * 3) / 2_000_000, $byKey['invoice:1']['gain'], 0.01);

        // Срок впереди: оплатить до срока + 2 рабочих дня (28 июля вт → 30 июля чт).
        $this->assertStringContainsString('до 30 июля', $byKey['invoice:2']['title']);

        $this->assertStringContainsString('1 000 000 ₽', $byKey['plan_gap']['title']);
        $this->assertEqualsWithDelta(85000 * 0.8 * 0.5, $byKey['plan_gap']['gain'], 0.01);

        $this->assertEqualsWithDelta(85000 * 0.8 * 0.05, $byKey['revenue_step']['gain'], 0.01);

        $gains = array_map(fn (array $row): float => $row['gain'], $advice);
        $sorted = $gains;
        rsort($sorted);
        $this->assertSame($sorted, $gains);
    }
}
