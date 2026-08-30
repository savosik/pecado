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
        // Перевыполнение выше плана, предел — выше перевыполнения на цену задержек.
        $this->assertGreaterThanOrEqual($s['optimistic']['total'], $s['stretch']['total']);
        $this->assertGreaterThanOrEqual($s['stretch']['total'], $s['perfect']['total']);

        // Пессимистично: выручка не растёт, обе накладные оплачены в последний рабочий день.
        $this->assertSame(1_000_000.0, $s['pessimistic']['revenue']);
        $this->assertGreaterThan(0, $s['pessimistic']['penalty']);
        // Базово: темп 1 000 000 / 11 × 23.
        $this->assertEqualsWithDelta(2_090_909.09, $s['base']['revenue'], 0.01);
        // Оптимистично: все плановые активны, штраф только известный (нет).
        $this->assertSame(10, $s['optimistic']['active_clients']);
        $this->assertSame(0.0, $s['optimistic']['penalty']);
        $this->assertSame('Если пойдёт как идёт', $s['base']['label']);
        $this->assertStringContainsString('том же темпе', $s['base']['hint']);
        $this->assertStringContainsString('2 свежих долгов', $s['pessimistic']['hint']);
        $this->assertStringContainsString('все 10 плановых клиентов', $s['optimistic']['hint']);
        $this->assertStringContainsString('предел месяца', $s['perfect']['hint']);
        $this->assertStringContainsString('на четверть выше плана', $s['stretch']['hint']);

        $this->assertCount(31, $forecast['curve']);
        $today = array_values(array_filter($forecast['curve'], fn (array $p): bool => $p['is_today']))[0];
        $this->assertSame($current->total, $today['low']);
        $this->assertNotNull($forecast['curve'][0]['earned']);
        $this->assertNull($forecast['curve'][30]['earned']);
        $this->assertSame($s['optimistic']['total'], $forecast['curve'][30]['high']);
    }

    #[Test]
    #[TestDox('Зависший долг в прогноз не идёт: пугать штрафом, которого не будет, нельзя')]
    public function stale_debt_is_out_of_the_forecast(): void
    {
        $params = $this->params();
        $base = $this->inputs();

        // Долг с марта — вчетверо крупнее свежих. Раньше сценарий предполагал,
        // что он придёт в последний день месяца, и штраф ×3 обнулял премию.
        $stale = new InvoiceInput(3, '29УТ-000003', 3, 'Партнёр 3', 1_000_000.0, '2026-03-01', '2026-03-20', null, null, null, null, 'unpaid');
        $inputs = $base->with([
            'at_risk_invoices' => array_map(
                fn (InvoiceInput $i): array => $i->toArray(),
                array_merge($base->atRiskInvoices, [$stale]),
            ),
        ]);

        $current = app(PayrollCalculator::class)->calculate($params, $inputs);
        $forecast = app(PayrollForecaster::class)->forecast($params, $inputs, $current, CarbonImmutable::parse('2026-07-15'));

        // Пессимистичный сценарий не изменился от появления зависшего долга.
        $without = app(PayrollForecaster::class)->forecast($params, $base, $current, CarbonImmutable::parse('2026-07-15'));
        $this->assertSame($without['scenarios']['pessimistic']['total'], $forecast['scenarios']['pessimistic']['total']);

        // Но и не спрятан: показан отдельной строкой.
        $this->assertSame(2, $forecast['basis']['at_risk_count']);
        $this->assertSame(1, $forecast['basis']['deferred_count']);
        $this->assertSame(1_000_000.0, $forecast['basis']['deferred_amount']);
        $this->assertStringContainsString('2 свежих долгов', $forecast['scenarios']['pessimistic']['hint']);
    }

    #[Test]
    #[TestDox('Базовый сценарий не занижает: несобранные долги премию не трогают')]
    public function base_scenario_does_not_charge_unpaid_debt(): void
    {
        $params = $this->params();
        $inputs = $this->inputs();
        $current = app(PayrollCalculator::class)->calculate($params, $inputs);

        $forecast = app(PayrollForecaster::class)->forecast($params, $inputs, $current, CarbonImmutable::parse('2026-07-15'));

        // Выручка по темпу выше факта, штрафа нет — значит и итог не ниже текущего.
        $this->assertSame(0.0, $forecast['scenarios']['base']['penalty']);
        $this->assertGreaterThanOrEqual($current->total, $forecast['scenarios']['base']['total']);
    }

    #[Test]
    #[TestDox('Неоплаченные разложены по срочности: успеть, не дать вырасти, поздно, зависло')]
    public function risk_is_grouped_by_urgency(): void
    {
        $params = $this->params();
        $today = CarbonImmutable::parse('2026-07-15');

        $inputs = $this->inputs()->with(['at_risk_invoices' => array_map(fn (array $r): array => $r, [
            // Срок сегодня — вычета ещё нет.
            (new InvoiceInput(11, '29УТ-000011', 1, 'Успеем', 100_000.0, '2026-07-01', '2026-07-15', null, null, null, null, 'unpaid'))->toArray(),
            // Четыре рабочих дня задержки — первая ступень, ещё не худшая.
            (new InvoiceInput(12, '29УТ-000012', 2, 'Растёт', 200_000.0, '2026-06-25', '2026-07-09', null, null, null, null, 'unpaid'))->toArray(),
            // Три недели — уже худшая ступень, но в горизонте.
            (new InvoiceInput(13, '29УТ-000013', 3, 'Поздно', 300_000.0, '2026-06-10', '2026-06-25', null, null, null, null, 'unpaid'))->toArray(),
            // Полтора месяца — зависший долг.
            (new InvoiceInput(14, '29УТ-000014', 4, 'Зависло', 400_000.0, '2026-04-20', '2026-05-01', null, null, null, null, 'unpaid'))->toArray(),
        ])]);

        $current = app(PayrollCalculator::class)->calculate($params, $inputs);
        $buckets = collect(app(PayrollForecaster::class)->forecast($params, $inputs, $current, $today)['risk_buckets'])
            ->keyBy('key');

        $this->assertSame(['safe', 'rising', 'worst', 'stale'], $buckets->keys()->all());

        // Пока не оплачено — из выручки не вычтено ничего; цена опоздания ×3.
        $this->assertSame(0.0, $buckets['safe']['penalty_now']);
        $this->assertSame(300_000.0, $buckets['safe']['penalty_worst']);

        // Растущая группа — единственная, где промедление ещё меняет сумму.
        $this->assertSame(300_000.0, $buckets['rising']['penalty_now']);
        $this->assertSame(600_000.0, $buckets['rising']['penalty_worst']);
        $this->assertNotNull($buckets['rising']['deadline']);

        // В худшей ступени и в зависших торопиться уже некуда.
        $this->assertSame($buckets['worst']['penalty_now'], $buckets['worst']['penalty_worst']);
        $this->assertNull($buckets['stale']['deadline']);
        $this->assertSame(400_000.0, $buckets['stale']['amount']);
    }

    #[Test]
    #[TestDox('Крайний срок не показывается прошедшей датой')]
    public function deadline_is_never_in_the_past(): void
    {
        $params = $this->params();
        $today = CarbonImmutable::parse('2026-07-15');

        // Срок прошёл, но после него были только выходные — накладная ещё в льготе,
        // а её дедлайн выпал на вчера.
        $inputs = $this->inputs()->with(['at_risk_invoices' => [
            (new InvoiceInput(21, '29УТ-000021', 1, 'В льготе', 50_000.0, '2026-07-05', '2026-07-10', null, null, null, null, 'unpaid'))->toArray(),
        ]]);

        $current = app(PayrollCalculator::class)->calculate($params, $inputs);
        $buckets = app(PayrollForecaster::class)->forecast($params, $inputs, $current, $today)['risk_buckets'];

        foreach ($buckets as $bucket) {
            if ($bucket['deadline'] !== null) {
                $this->assertGreaterThanOrEqual($today->toDateString(), $bucket['deadline']);
            }
        }
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
