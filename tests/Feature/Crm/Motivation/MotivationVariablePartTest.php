<?php

namespace Tests\Feature\Crm\Motivation;

use App\Services\Motivation\Dto\MotivationInputs;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\QuarterlyBonusCalculator;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollParamsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Контрольные примеры раздела 02 ТЗ (карточка mot-21).
 *
 * Примеры 1–3 взяты из Приложения № 3 Положения и не меняются без правки
 * Положения. Пример 8 — сквозной на живых данных августа 2026; он пересчитывается
 * при изменении любой ставки и служит регрессией на реальных величинах.
 *
 * Реализация считается корректной, только если сходится с каждым примером
 * до копейки.
 */
class MotivationVariablePartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Расчёт по схеме v2 с параметрами Приложения № 1.
     *
     * @param  array<string, mixed>  $overrides  точечная замена параметров переменной части
     * @param  array<string, mixed>  $componentOverrides  замена параметров других компонентов
     */
    private function calculate(
        ?float $plan,
        MotivationInputs $motivation,
        array $overrides = [],
        array $componentOverrides = [],
    ): \App\Services\Payroll\Dto\PayrollBreakdown {
        $scheme = app(MotivationSchemeInstaller::class)->install(CarbonImmutable::parse('2026-10-01'));
        $params = app(PayrollParamsResolver::class)->fromScheme($scheme);

        if ($overrides !== []) {
            $params = $params->withComponent('motivation_variable', array_replace(
                $params->for('motivation_variable'),
                $overrides,
            ));
        }

        foreach ($componentOverrides as $key => $values) {
            $params = $params->withComponent($key, array_replace($params->for($key), $values));
        }

        $inputs = new PayrollInputs(
            managerId: 1,
            month: '2026-10-01',
            plan: $plan,
            revenue: $motivation->baseRevenue + $motivation->newPartnersRevenue,
            motivation: $motivation,
        );

        return app(PayrollCalculator::class)->calculate($params, $inputs);
    }

    /**
     * @return array<string, float>
     */
    private function parts(\App\Services\Payroll\Dto\PayrollBreakdown $breakdown): array
    {
        foreach ($breakdown->components as $component) {
            if ($component->key === 'motivation_variable') {
                return [
                    'p1' => (float) $component->meta['p1'],
                    'p2' => (float) $component->meta['p2'],
                    'p3' => (float) $component->meta['p3'],
                    'k1' => (float) $component->meta['k1'],
                    'variable' => (float) $component->amount,
                    'threshold' => (float) $component->meta['threshold'],
                ];
            }
        }

        $this->fail('В разборе нет переменной части');
    }

    #[Test]
    #[TestDox('Пример 1: базовый расчёт Приложения № 3 — 120 400 ₽')]
    public function example_1_appendix_three(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 6_000_000,
            newPartnersRevenue: 210_000,
            newPartnersCount: 3,
            focusRevenue: 150_000,
            overdueIntegral: 800_000 * 20,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(3_600_000.0, $parts['threshold'], 'Порог оплаты — 60 % личного плана');
        $this->assertSame(45_600.0, $parts['p1']);
        $this->assertSame(6_300.0, $parts['p2']);
        $this->assertSame(1_500.0, $parts['p3']);
        $this->assertSame(8_000.0, $parts['k1']);
        $this->assertSame(45_400.0, $parts['variable']);
        $this->assertSame(120_400.0, $breakdown->total, 'Оклад 70 000 + надбавка 5 000 + переменная 45 400');
    }

    #[Test]
    #[TestDox('Пример 2: долг погашен на десятый день — вычет вдвое меньше')]
    public function example_2_debt_settled_mid_period(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 6_000_000,
            newPartnersRevenue: 210_000,
            newPartnersCount: 3,
            focusRevenue: 150_000,
            overdueIntegral: 800_000 * 10,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(4_000.0, $parts['k1'], 'Начисление прекращается со дня оплаты (п. 6.5.2)');
        $this->assertSame(49_400.0, $parts['variable']);
        $this->assertSame(124_400.0, $breakdown->total);
    }

    #[Test]
    #[TestDox('Пример 3: отгрузки на уровне порога — П1 нет, но П2 и П3 начислены, а итог не уходит в минус')]
    public function example_3_below_payment_threshold(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 3_600_000,
            newPartnersRevenue: 210_000,
            newPartnersCount: 3,
            focusRevenue: 150_000,
            overdueIntegral: 800_000 * 20,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(0.0, $parts['p1'], 'Отгрузки не превысили порог — П1 не начисляется (п. 6.2.4)');
        $this->assertSame(6_300.0, $parts['p2'], 'Порог к П2 не применяется');
        $this->assertSame(1_500.0, $parts['p3'], 'Порог к П3 не применяется');
        $this->assertSame(8_000.0, $parts['k1']);
        $this->assertSame(0.0, $parts['variable'], 'Вычет больше показателей, но ниже нуля не опускаемся (п. 6.6.1)');
        $this->assertSame(
            75_000.0,
            $breakdown->total,
            'Остаются оклад 70 000 и надбавка 5 000. Пояснение 2 к Приложению № 3 Положения называло '
            .'74 800 ₽: остаток 7 800 − 8 000 был вычтен из оклада вопреки п. 6.6.1. Документ исправлен 08.09.2026',
        );
    }

    #[Test]
    #[TestDox('Пример 4: предельный размер переменной части — 200 000 ₽')]
    public function example_4_variable_part_cap(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 18_000_000,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(273_600.0, $parts['p1'], 'До ограничения показатель равен 273 600 ₽');
        $this->assertSame(200_000.0, $parts['variable'], 'Предельный размер применяется к сумме (п. 6.6.2)');
        $this->assertSame(275_000.0, $breakdown->total);
    }

    #[Test]
    #[TestDox('Пример 5: отсутствие работника — план уменьшается до вычисления порога')]
    public function example_5_absence_reduces_plan_before_threshold(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 3_000_000,
            workingDaysTotal: 21,
            workedDays: 11,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(1_885_714.29, $parts['threshold'], 'Порог считается от уменьшенного плана (п. 10.1)');
        $this->assertSame(21_171.43, $parts['p1']);
    }

    #[Test]
    #[TestDox('Пример 5а: без уменьшения плана тот же месяц дал бы ноль')]
    public function example_5a_without_absence_the_same_month_pays_nothing(): void
    {
        $breakdown = $this->calculate(6_000_000, new MotivationInputs(
            baseRevenue: 3_000_000,
        ));

        $this->assertSame(0.0, $this->parts($breakdown)['p1'], 'Без учёта отпуска работник наказан за законное отсутствие');
    }

    #[Test]
    #[TestDox('Пример 6: доплата до гарантии переходного периода — отдельной строкой')]
    public function example_6_transition_guarantee(): void
    {
        $breakdown = $this->calculate(
            6_000_000,
            new MotivationInputs(baseRevenue: 4_500_000),
            componentOverrides: ['motivation_guarantee' => ['base' => 130_000]],
        );

        $parts = $this->parts($breakdown);
        $guarantee = null;
        foreach ($breakdown->components as $component) {
            if ($component->key === 'motivation_guarantee') {
                $guarantee = $component;
            }
        }

        $this->assertNotNull($guarantee, 'Доплата обязана быть отдельной строкой расчётного листа');
        $this->assertSame(117_000.0, (float) $guarantee->meta['minimum'], '90 % от среднего 130 000 ₽');
        $this->assertSame(17_100.0, $parts['variable'], 'Переменная часть доплатой не изменяется');
        $this->assertSame(24_900.0, (float) $guarantee->amount);
        $this->assertSame(117_000.0, $breakdown->total, 'Итог поднят ровно до гарантированного минимума');
    }

    #[Test]
    #[TestDox('Пример 6а: расчёт выше минимума — доплаты нет')]
    public function example_6a_no_top_up_when_above_minimum(): void
    {
        $breakdown = $this->calculate(
            6_000_000,
            new MotivationInputs(baseRevenue: 8_000_000),
            componentOverrides: ['motivation_guarantee' => ['base' => 130_000]],
        );

        foreach ($breakdown->components as $component) {
            if ($component->key === 'motivation_guarantee') {
                $this->assertSame(0.0, (float) $component->amount);
            }
        }
    }

    #[Test]
    #[TestDox('Пример 7: поклиентный зачёт — 4 квалифицированных из 12, ступень не достигнута')]
    public function example_7_quarterly_qualification_is_per_partner(): void
    {
        $partners = [];
        for ($i = 1; $i <= 4; $i++) {
            $partners[] = ['user_id' => $i, 'amount' => 150_000];
        }
        for ($i = 5; $i <= 12; $i++) {
            $partners[] = ['user_id' => $i, 'amount' => 40_000];
        }

        $defaults = (array) config('motivation.default_parameters');
        $result = app(QuarterlyBonusCalculator::class)->evaluate(
            $partners,
            (float) $defaults['quarterly_qualification_amount'],
            $defaults['quarterly_steps'],
        );

        $this->assertSame(920_000.0, $result['total']);
        $this->assertSame(4, $result['qualified_count'], 'Порог проверяется по каждому партнёру отдельно (п. 7.3)');
        $this->assertSame(0, $result['step'], 'Первая ступень — 8 партнёров, она не достигнута');
        $this->assertSame(0.0, $result['amount']);

        $this->assertSame(
            9,
            (int) floor($result['total'] / (float) $defaults['quarterly_qualification_amount']),
            'Деление совокупного объёма дало бы 9 — ровно та ошибка, от которой защищает поклиентный зачёт',
        );

        $next = app(QuarterlyBonusCalculator::class)->nextStep(4, $defaults['quarterly_steps']);
        $this->assertSame(4, $next['count'], 'До первой ступени не хватает четырёх партнёров');
        $this->assertSame(40_000.0, $next['amount']);
    }

    #[Test]
    #[TestDox('Пример 8: сквозной на живых данных августа 2026 — Сухов, 161 684,96 ₽')]
    public function example_8_real_august_data(): void
    {
        $breakdown = $this->calculate(4_762_880, new MotivationInputs(
            baseRevenue: 7_061_020,
            newPartnersRevenue: 732_916,
            newPartnersCount: 4,
            focusRevenue: 193_293,
            overdueIntegral: 54_196_000,
            workingDaysTotal: 21,
            workedDays: 21,
            substitutionDays: 10,
        ));

        $parts = $this->parts($breakdown);

        $this->assertSame(2_857_728.0, $parts['threshold']);
        $this->assertSame(79_862.55, $parts['p1']);
        $this->assertSame(21_987.48, $parts['p2']);
        $this->assertSame(1_932.93, $parts['p3']);
        $this->assertSame(27_098.0, $parts['k1']);
        $this->assertSame(76_684.96, $parts['variable']);
        $this->assertSame(161_684.96, $breakdown->total, 'Оклад 70 000 + каналы 5 000 + замещение 10 000 + переменная');
    }

    #[Test]
    #[TestDox('Ставку показателя можно задать по работнику, не меняя приказ на отдел')]
    public function personal_rate_override_applies_to_one_worker_only(): void
    {
        $common = $this->calculate(6_000_000, new MotivationInputs(newPartnersRevenue: 210_000, newPartnersCount: 3));
        $personal = $this->calculate(
            6_000_000,
            new MotivationInputs(newPartnersRevenue: 210_000, newPartnersCount: 3),
            overrides: ['rate_p2' => 0.05],
        );

        $this->assertSame(6_300.0, $this->parts($common)['p2'], 'Общая ставка приказа — 3 %');
        $this->assertSame(10_500.0, $this->parts($personal)['p2'], 'Персональное отклонение — 5 %');
    }

    #[Test]
    #[TestDox('Показатели считаются нулевыми и с предупреждением, если входы не собраны')]
    public function missing_motivation_inputs_produce_a_warning(): void
    {
        $scheme = app(MotivationSchemeInstaller::class)->install(CarbonImmutable::parse('2026-10-01'));
        $params = app(PayrollParamsResolver::class)->fromScheme($scheme);

        $breakdown = app(PayrollCalculator::class)->calculate($params, new PayrollInputs(
            managerId: 1,
            month: '2026-10-01',
            plan: 6_000_000,
            revenue: 6_000_000,
        ));

        $this->assertSame(0.0, $this->parts($breakdown)['variable']);
        $this->assertNotEmpty($breakdown->warnings, 'Молча выдать ноль нельзя — это неотличимо от честного нуля');
    }

    #[Test]
    #[TestDox('Входы переменной части переживают запись в снимок и чтение обратно')]
    public function motivation_inputs_survive_snapshot_round_trip(): void
    {
        $inputs = new PayrollInputs(
            managerId: 1,
            month: '2026-10-01',
            plan: 4_762_880,
            revenue: 7_793_936,
            motivation: new MotivationInputs(
                baseRevenue: 7_061_020,
                newPartnersRevenue: 732_916,
                newPartnersCount: 4,
                focusRevenue: 193_293,
                overdueIntegral: 54_196_000,
                workingDaysTotal: 21,
                workedDays: 11,
                substitutionDays: 10,
                overdueRows: [['document' => 'РЕ-1', 'balance' => 100_000, 'days' => 12]],
                returns: ['base' => 5_000.0, 'new' => 0.0, 'focus' => 0.0],
            ),
        );

        $restored = PayrollInputs::fromArray($inputs->toArray());

        $motivation = $restored->motivation;

        $this->assertSame($inputs->hash(), $restored->hash());
        $this->assertNotNull($motivation, 'Входы переменной части не должны теряться при чтении снимка');
        $this->assertSame(7_061_020.0, $motivation->baseRevenue);
        $this->assertSame(11, $motivation->workedDays);
        $this->assertSame(5_000.0, $motivation->returns['base']);
        $this->assertSame([['document' => 'РЕ-1', 'balance' => 100_000, 'days' => 12]], $motivation->overdueRows);
    }

    #[Test]
    #[TestDox('Схема v1 остаётся действующей до месяца ввода схемы v2')]
    public function scheme_v1_stays_in_force_until_v2_starts(): void
    {
        $installed = app(MotivationSchemeInstaller::class)->install(CarbonImmutable::parse('2026-10-01'));
        $repository = app(\App\Services\Payroll\PayrollSchemeRepository::class);

        $before = $repository->forMonth(CarbonImmutable::parse('2026-09-01'));
        $after = $repository->forMonth(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $before->version, 'Сентябрь считается по прежней схеме');
        $this->assertSame($installed->id, $after->id, 'Октябрь — по новой');
        $this->assertContains('kpi_bonus', array_column($before->orderedComponents(), 'key'));
        $this->assertNotContains('kpi_bonus', array_column($after->orderedComponents(), 'key'), 'В v2 прежняя премия выключена');
    }
}
