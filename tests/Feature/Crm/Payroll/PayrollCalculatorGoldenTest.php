<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollScheme;
use App\Services\Payroll\Dto\AdjustmentInput;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollParamsResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Golden-пример заказчика: Excel РОПа за июль 2026 должен сходиться до копейки.
 *
 * БД не нужна: схема собирается из config/payroll.php, входы — руками.
 */
class PayrollCalculatorGoldenTest extends TestCase
{
    private function params(): EffectiveParams
    {
        $scheme = new PayrollScheme(['components' => config('payroll.default_scheme.components')]);

        return app(PayrollParamsResolver::class)->fromScheme($scheme);
    }

    /**
     * @return list<PlannedClientInput>
     */
    private function plannedClients(int $planned, int $active): array
    {
        $rows = [];
        for ($i = 1; $i <= $planned; $i++) {
            $rows[] = new PlannedClientInput($i, "Партнёр {$i}", 100000.0, $i <= $active ? 50000.0 : 0.0);
        }

        return $rows;
    }

    private function invoice(int $id, float $amount, ?int $delayWorkingDays): InvoiceInput
    {
        return new InvoiceInput(
            shipmentId: $id,
            erpNumber: sprintf('29УТ-%06d', $id),
            partnerId: $id,
            partnerName: "Партнёр {$id}",
            amount: $amount,
            shippedOn: '2026-06-30',
            dueOn: '2026-07-10',
            settledOn: '2026-07-20',
            delayWorkingDays: $delayWorkingDays,
            delayCalendarDays: $delayWorkingDays === null ? null : $delayWorkingDays + 2,
            source: 'matched',
            paymentStatus: 'paid',
        );
    }

    private function goldenInputs(): PayrollInputs
    {
        return new PayrollInputs(
            managerId: 1,
            month: '2026-07-01',
            plan: 5_000_000.0,
            revenue: 4_900_000.0,
            plannedClients: $this->plannedClients(35, 25),
            invoices: [
                $this->invoice(1, 12_000.0, 5),
                $this->invoice(2, 400_000.0, 10),
            ],
            extraItems: [new AdjustmentInput(null, 'ТГ-каналы', 1.0, 5000.0, 5000.0)],
            workingDays: ['total' => 23, 'passed' => 23, 'left' => 0],
        );
    }

    #[Test]
    #[TestDox('Пример из Excel: KPI 50 075,20 и итог 125 075,20')]
    public function golden_example_matches_excel_to_the_kopeck(): void
    {
        $breakdown = app(PayrollCalculator::class)->calculate($this->params(), $this->goldenInputs());

        $this->assertSame(70000.0, $breakdown->amountOf('salary'));
        $this->assertSame(50075.2, $breakdown->amountOf('kpi_bonus'));
        $this->assertSame(5000.0, $breakdown->amountOf('extra_income'));
        $this->assertSame(0.0, $breakdown->amountOf('manual_correction'));
        $this->assertSame(125075.2, $breakdown->total);
        $this->assertSame([], $breakdown->warnings);

        $kpi = $breakdown->component('kpi_bonus');
        $this->assertNotNull($kpi);
        $this->assertEqualsWithDelta(0.58912, $kpi->value, 1e-9);
        $this->assertSame(3682000.0, $kpi->meta['adjusted']);
        $this->assertSame(0.8, $kpi->meta['multiplier']);
        $this->assertStringContainsString('4 900 000 ₽', $kpi->explanation);
        $this->assertStringContainsString('1 218 000 ₽', $kpi->explanation);
        $this->assertStringContainsString('50 075,20 ₽', $kpi->explanation);
    }

    #[Test]
    #[TestDox('Эффект факторов в рублях: штраф и множитель считаются what-if-изоляцией')]
    public function factor_effects_are_computed_by_isolation(): void
    {
        $kpi = app(PayrollCalculator::class)->calculate($this->params(), $this->goldenInputs())->component('kpi_bonus');

        $children = [];
        foreach ($kpi->children as $child) {
            $children[$child->key] = $child;
        }

        // Без штрафа: 85 000 × 0,8 × 0,98 = 66 640 → штраф стоил 16 564,80.
        $this->assertEqualsWithDelta(-16564.8, $children['discipline_penalty']->effectRub, 0.001);
        // Без множителя: 85 000 × 0,7364 = 62 594 → множитель стоил 12 518,80.
        $this->assertEqualsWithDelta(-12518.8, $children['active_clients']->effectRub, 0.001);
        $this->assertSame(1218000.0, $children['discipline_penalty']->value);
        $this->assertSame(2, $children['discipline_penalty']->meta['penalized_count']);
        $this->assertSame(25, $children['active_clients']->meta['active']);
        $this->assertSame(35, $children['active_clients']->meta['planned']);
        $this->assertSame(3, $children['active_clients']->meta['next_step']['clients_needed']);
    }

    #[Test]
    #[TestDox('Границы ступеней штрафа: 2 дня — ничего, 3 и 7 — ×1,5, 8 — ×3')]
    public function penalty_tier_boundaries(): void
    {
        foreach ([2 => 0.0, 3 => 15000.0, 7 => 15000.0, 8 => 30000.0, 30 => 30000.0] as $delay => $expectedPenalty) {
            $inputs = $this->goldenInputs()->with([
                'invoices' => [$this->invoice(9, 10000.0, $delay)->toArray()],
            ]);

            $penalty = app(PayrollCalculator::class)->calculate($this->params(), $inputs)
                ->component('kpi_bonus')->children[1];

            $this->assertSame($expectedPenalty, $penalty->value, "задержка {$delay} дн.");
        }
    }

    #[Test]
    #[TestDox('Накладная без восстановленной задержки штрафа не даёт')]
    public function invoice_without_delay_is_not_penalized(): void
    {
        $inputs = $this->goldenInputs()->with([
            'invoices' => [$this->invoice(9, 10000.0, null)->toArray()],
        ]);

        $penalty = app(PayrollCalculator::class)->calculate($this->params(), $inputs)
            ->component('kpi_bonus')->children[1];

        $this->assertSame(0.0, $penalty->value);
    }

    #[Test]
    #[TestDox('Лестница множителя по доле активных клиентов')]
    public function active_clients_ladder_steps(): void
    {
        foreach ([[35, 20, 0.8], [35, 28, 0.9], [35, 32, 1.0], [10, 10, 1.0], [10, 9, 1.0], [10, 8, 0.9], [10, 7, 0.8]] as [$planned, $active, $expected]) {
            $inputs = $this->goldenInputs()->with([
                'planned_clients' => array_map(fn (PlannedClientInput $c) => $c->toArray(), $this->plannedClients($planned, $active)),
            ]);

            $multiplier = app(PayrollCalculator::class)->calculate($this->params(), $inputs)
                ->component('kpi_bonus')->children[2];

            $this->assertSame($expected, $multiplier->value, "{$active} из {$planned}");
        }
    }

    #[Test]
    #[TestDox('Потолок: выполнение выше 200 % даёт ровно двойную базу')]
    public function cap_limits_bonus_to_double_base(): void
    {
        $inputs = $this->goldenInputs()->with([
            'revenue' => 20_000_000.0,
            'invoices' => [],
            'planned_clients' => array_map(fn (PlannedClientInput $c) => $c->toArray(), $this->plannedClients(35, 35)),
        ]);

        $kpi = app(PayrollCalculator::class)->calculate($this->params(), $inputs)->component('kpi_bonus');

        $this->assertSame(170000.0, $kpi->amount);
        $this->assertTrue($kpi->meta['capped']);
        $this->assertStringContainsString('потолок 200 %', $kpi->explanation);
    }

    #[Test]
    #[TestDox('Без плана выручки премия 0 и предупреждение')]
    public function missing_plan_gives_zero_and_warning(): void
    {
        $breakdown = app(PayrollCalculator::class)->calculate($this->params(), $this->goldenInputs()->with(['plan' => null]));

        $this->assertSame(0.0, $breakdown->amountOf('kpi_bonus'));
        $this->assertSame(75000.0, $breakdown->total);
        $this->assertNotEmpty($breakdown->warnings);
        $this->assertStringContainsString('План выручки', $breakdown->warnings[0]);
    }

    #[Test]
    #[TestDox('Без плановых клиентов множитель 1,0 и предупреждение')]
    public function no_planned_clients_means_neutral_multiplier(): void
    {
        $breakdown = app(PayrollCalculator::class)->calculate(
            $this->params(),
            $this->goldenInputs()->with(['planned_clients' => [], 'invoices' => []]),
        );

        // 85 000 × 4 900 000 / 5 000 000 = 83 300.
        $this->assertSame(83300.0, $breakdown->amountOf('kpi_bonus'));
        $this->assertStringContainsString('Плановые клиенты', $breakdown->warnings[0]);
    }

    #[Test]
    #[TestDox('Штраф может обнулить премию, но не увести её в минус')]
    public function penalty_cannot_make_bonus_negative(): void
    {
        $inputs = $this->goldenInputs()->with([
            'revenue' => 100_000.0,
            'invoices' => [$this->invoice(9, 1_000_000.0, 10)->toArray()],
        ]);

        $breakdown = app(PayrollCalculator::class)->calculate($this->params(), $inputs);

        $this->assertSame(0.0, $breakdown->amountOf('kpi_bonus'));
        $this->assertSame(75000.0, $breakdown->total);
    }

    #[Test]
    #[TestDox('Корректировка РОПа со знаком минус уменьшает итог')]
    public function manual_correction_reduces_total(): void
    {
        $inputs = $this->goldenInputs()->with([
            'corrections' => [['label' => 'Удержание', 'qty' => 1, 'price' => -3000, 'amount' => -3000]],
        ]);

        $breakdown = app(PayrollCalculator::class)->calculate($this->params(), $inputs);

        $this->assertSame(-3000.0, $breakdown->amountOf('manual_correction'));
        $this->assertSame(122075.2, $breakdown->total);
    }

    #[Test]
    #[TestDox('Входы переживают сериализацию: снимок читается тем же калькулятором')]
    public function inputs_round_trip_through_array(): void
    {
        $inputs = $this->goldenInputs();
        $restored = PayrollInputs::fromArray(json_decode((string) json_encode($inputs->toArray()), true));

        $this->assertSame($inputs->hash(), $restored->hash());
        $this->assertSame(
            app(PayrollCalculator::class)->calculate($this->params(), $inputs)->total,
            app(PayrollCalculator::class)->calculate($this->params(), $restored)->total,
        );
    }
}
