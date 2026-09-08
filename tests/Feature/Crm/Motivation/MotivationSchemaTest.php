<?php

namespace Tests\Feature\Crm\Motivation;

use App\Console\Commands\BiSyncGrants;
use App\Models\Motivation\MotivationDebtExclusion;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\Motivation\MotivationParameterOrder;
use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use Tests\TestCase;

/**
 * Каркас домена «Мотивация 2.0» (карточка mot-20).
 *
 * Проверяется не наличие таблиц как таковых, а те их свойства, на которые
 * опирается расчёт: единственность приказа на период, воспроизводимость снимка
 * перечня, неразмножение строк новизны и закрытость домена для BI-агента.
 */
class MotivationSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[TestDox('Все таблицы домена созданы')]
    public function tables_exist(): void
    {
        foreach ([
            'motivation_parameter_orders',
            'motivation_focus_rules',
            'motivation_focus_snapshot_items',
            'motivation_partner_novelty',
            'motivation_partner_assignments',
            'motivation_pool_packages',
            'motivation_pool_package_items',
            'motivation_quarterly_bonuses',
            'motivation_quarterly_qualifications',
            'motivation_quarterly_shares',
            'motivation_plan_orders',
            'motivation_debt_exclusions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Нет таблицы {$table}");
        }
    }

    #[Test]
    #[TestDox('На один период действует ровно один приказ о параметрах')]
    public function parameter_order_is_unique_per_period(): void
    {
        MotivationParameterOrder::factory()->create(['effective_from' => '2026-10-01']);

        $this->expectException(QueryException::class);

        MotivationParameterOrder::factory()->create(['effective_from' => '2026-10-01']);
    }

    #[Test]
    #[TestDox('Действующий приказ — последний, начавший действовать не позже расчётного периода')]
    public function effective_order_is_the_latest_not_after_period(): void
    {
        MotivationParameterOrder::factory()->create(['effective_from' => '2026-10-01', 'order_number' => 'первый']);
        MotivationParameterOrder::factory()->create(['effective_from' => '2027-01-01', 'order_number' => 'второй']);

        $this->assertNull(MotivationParameterOrder::effectiveFor(Carbon::parse('2026-09-15')));
        $this->assertSame('первый', MotivationParameterOrder::effectiveFor(Carbon::parse('2026-12-31'))?->order_number);
        $this->assertSame('второй', MotivationParameterOrder::effectiveFor(Carbon::parse('2027-02-01'))?->order_number);
    }

    #[Test]
    #[TestDox('Умолчания Приложения № 1 доступны для первой материализации приказа')]
    public function appendix_one_defaults_are_available(): void
    {
        $defaults = (array) config('motivation.default_parameters');

        $this->assertSame(70000, $defaults['salary']);
        $this->assertSame(0.6, $defaults['payment_threshold']);
        $this->assertSame(0.019, $defaults['rate_p1']);
        $this->assertSame(0.03, $defaults['rate_p2']);
        $this->assertSame(0.01, $defaults['rate_p3']);
        $this->assertSame(0.0005, $defaults['rate_k1_per_day']);
        $this->assertSame(200000, $defaults['variable_cap']);
        $this->assertSame(100000, $defaults['quarterly_qualification_amount']);
        $this->assertSame([8, 15, 22], array_column($defaults['quarterly_steps'], 'count'));
        $this->assertSame(0.9, $defaults['transition_guarantee_share']);
    }

    #[Test]
    #[TestDox('Снимок Фокус-перечня хранит позицию один раз на период')]
    public function focus_snapshot_keeps_one_row_per_product_and_period(): void
    {
        $item = MotivationFocusSnapshotItem::factory()->create(['period_month' => '2026-10-01']);

        $this->expectException(QueryException::class);

        MotivationFocusSnapshotItem::factory()->create([
            'period_month' => '2026-10-01',
            'product_id' => $item->product_id,
        ]);
    }

    #[Test]
    #[TestDox('Правило перечня действует только в свой период')]
    public function focus_rule_is_active_only_within_its_period(): void
    {
        MotivationFocusRule::factory()->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-12-31']);

        $this->assertSame(0, MotivationFocusRule::query()->activeOn(Carbon::parse('2026-09-30'))->count());
        $this->assertSame(1, MotivationFocusRule::query()->activeOn(Carbon::parse('2026-11-15'))->count());
        $this->assertSame(0, MotivationFocusRule::query()->activeOn(Carbon::parse('2027-01-01'))->count());
    }

    #[Test]
    #[TestDox('Новизна партнёра — одна строка на партнёра, период проверяется по дню')]
    public function novelty_is_one_row_per_partner(): void
    {
        $partner = User::factory()->create();

        MotivationPartnerNovelty::factory()->withinNovelty('2026-09-01', '2027-02-28')->create(['user_id' => $partner->id]);

        $this->assertSame(1, MotivationPartnerNovelty::query()->count());

        $novelty = MotivationPartnerNovelty::query()->findOrFail($partner->id);

        $this->assertFalse($novelty->isNewOn(Carbon::parse('2026-08-31')));
        $this->assertTrue($novelty->isNewOn(Carbon::parse('2026-11-01')));
        $this->assertFalse($novelty->isNewOn(Carbon::parse('2027-03-01')));
    }

    #[Test]
    #[TestDox('Партнёр без единой отгрузки не в Периоде новизны, пока не купил')]
    public function partner_without_shipments_is_not_new_yet(): void
    {
        $novelty = MotivationPartnerNovelty::factory()->create();

        $this->assertFalse($novelty->isNewOn(Carbon::parse('2026-11-01')));
    }

    #[Test]
    #[TestDox('Флаг неполной истории хранится и не влияет на признание новым')]
    public function history_incomplete_flag_is_stored(): void
    {
        $novelty = MotivationPartnerNovelty::factory()
            ->withinNovelty('2026-01-12', '2026-06-30')
            ->create(['history_incomplete' => true]);

        $this->assertTrue($novelty->fresh()->history_incomplete);
        $this->assertTrue($novelty->isNewOn(Carbon::parse('2026-03-01')));
    }

    #[Test]
    #[TestDox('Реестр закрепления хранит историю: закрытая запись и действующая')]
    public function assignment_registry_keeps_history(): void
    {
        $partner = User::factory()->create();
        $first = PersonalManager::factory()->create();
        $second = PersonalManager::factory()->create();

        MotivationPartnerAssignment::factory()->create([
            'user_id' => $partner->id,
            'personal_manager_id' => $first->id,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-09-30',
        ]);
        MotivationPartnerAssignment::factory()->create([
            'user_id' => $partner->id,
            'personal_manager_id' => $second->id,
            'starts_on' => '2026-10-01',
            'reason' => MotivationPartnerAssignment::REASON_TRANSFER,
        ]);

        $inSeptember = MotivationPartnerAssignment::query()->activeOn(Carbon::parse('2026-09-15'))->sole();
        $inOctober = MotivationPartnerAssignment::query()->activeOn(Carbon::parse('2026-10-15'))->sole();

        $this->assertSame($first->id, $inSeptember->personal_manager_id);
        $this->assertSame($second->id, $inOctober->personal_manager_id);
    }

    #[Test]
    #[TestDox('Исключение долга действует с даты и закрывается датой, а не удалением')]
    public function debt_exclusion_is_closed_by_date(): void
    {
        $exclusion = MotivationDebtExclusion::factory()->create([
            'excluded_from' => '2026-10-01',
            'excluded_until' => null,
        ]);

        $this->assertSame(0, MotivationDebtExclusion::query()->activeOn(Carbon::parse('2026-09-30'))->count());
        $this->assertSame(1, MotivationDebtExclusion::query()->activeOn(Carbon::parse('2026-12-01'))->count());

        $exclusion->update(['excluded_until' => '2026-10-31']);

        $this->assertSame(0, MotivationDebtExclusion::query()->activeOn(Carbon::parse('2026-12-01'))->count());
        $this->assertSame(1, MotivationDebtExclusion::query()->count(), 'Закрытие исключения не должно удалять строку');
    }

    #[Test]
    #[TestDox('Доля в квартальной премии заводится на работника один раз')]
    public function quarterly_share_is_unique_per_manager(): void
    {
        $bonus = MotivationQuarterlyBonus::factory()->create();
        $manager = PersonalManager::factory()->create();

        MotivationQuarterlyShare::factory()->create([
            'bonus_id' => $bonus->id,
            'personal_manager_id' => $manager->id,
        ]);

        $this->expectException(QueryException::class);

        MotivationQuarterlyShare::factory()->create([
            'bonus_id' => $bonus->id,
            'personal_manager_id' => $manager->id,
        ]);
    }

    #[Test]
    #[TestDox('Таблицы домена не выдаются аналитическому агенту')]
    public function motivation_tables_are_confidential_for_bi(): void
    {
        $prefixes = (new ReflectionClass(BiSyncGrants::class))
            ->getConstant('CONFIDENTIAL_TABLE_PREFIXES');

        $this->assertContains('motivation_', $prefixes);
        $this->assertContains('payroll_', $prefixes, 'Зарплатные таблицы обязаны оставаться закрытыми');
    }
}
