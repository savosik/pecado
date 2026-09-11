<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Motivation\GuaranteeBaseService;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollParamsResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Гарантия переходного периода (карточка mot-40; п. 12.3): фиксация базы и доплата.
 */
class MotivationGuaranteeTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $profile;

    private CarbonImmutable $effective;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->effective = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        app(MotivationSchemeInstaller::class)->install($this->effective);

        foreach ([3 => 100_000, 2 => 120_000, 1 => 140_000] as $back => $total) {
            PayrollCalculation::factory()->approved()->create([
                'personal_manager_id' => $this->profile->id,
                'period_month' => $this->effective->subMonths($back)->toDateString(),
                'total' => $total,
            ]);
        }
    }

    #[Test]
    #[TestDox('База — среднее за три периода до введения, срок — первый квартал; повтор без --overwrite не трогает')]
    public function base_is_fixed_once(): void
    {
        $result = app(GuaranteeBaseService::class)->fix($this->effective, $this->head);

        $row = $result['rows'][0];
        $this->assertSame(120_000.0, $row['average']);
        $this->assertSame(108_000.0, $row['minimum'], '90 % от среднего');
        $this->assertFalse($row['skipped']);
        $this->assertSame($this->effective->addMonths(3)->toDateString(), $result['until']);

        $layer = app(PayrollParamsResolver::class)->layer($this->profile->id, null)['motivation_guarantee'];
        $this->assertSame(120_000.0, (float) $layer['base']);
        $this->assertSame($this->effective->addMonths(3)->toDateString(), $layer['until']);

        PayrollCalculation::latestFor($this->profile->id, $this->effective->subMonth())?->forceFill(['total' => 999_000])->save();
        $again = app(GuaranteeBaseService::class)->fix($this->effective, $this->head);
        $this->assertTrue($again['rows'][0]['skipped'], 'Зафиксированная база не пересчитывается');
        $this->assertSame(120_000.0, (float) app(PayrollParamsResolver::class)->layer($this->profile->id, null)['motivation_guarantee']['base']);

        $overwritten = app(GuaranteeBaseService::class)->fix($this->effective, $this->head, overwrite: true);
        $this->assertFalse($overwritten['rows'][0]['skipped']);
        $this->assertNotSame(120_000.0, $overwritten['rows'][0]['average']);
    }

    #[Test]
    #[TestDox('Доплата считается последней и выводится отдельной строкой; после первого квартала — ноль')]
    public function top_up_is_paid_in_first_quarter_only(): void
    {
        app(GuaranteeBaseService::class)->fix($this->effective, $this->head);
        $calculations = app(PayrollCalculationService::class);

        $draft = $calculations->ensureDraft($this->profile->id, $this->effective);
        $components = collect((array) data_get($draft->breakdown, 'components', []))->keyBy('key');
        $guarantee = $components['motivation_guarantee'];

        $earned = (float) $draft->total - (float) $guarantee['amount'];
        $this->assertEqualsWithDelta(max(0.0, 108_000 - $earned), (float) $guarantee['amount'], 0.01, 'Доплата = минимум − остальное');
        $this->assertGreaterThan(0, (float) $guarantee['amount'], 'Без отгрузок работник ниже минимума — доплата есть');
        $this->assertSame(108_000.0, (float) $guarantee['value']);

        $late = $calculations->ensureDraft($this->profile->id, $this->effective->addMonths(3));
        $lateGuarantee = collect((array) data_get($late->breakdown, 'components', []))->keyBy('key')['motivation_guarantee'];
        $this->assertSame(0.0, (float) $lateGuarantee['amount']);
        $this->assertStringContainsString('завершилась', (string) $lateGuarantee['explanation']);
    }

    #[Test]
    #[TestDox('Кнопка на экране параметров и команда фиксируют базу; менеджеру недоступно')]
    public function endpoint_and_command(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/motivation/settings/guarantee', [])
            ->assertOk()
            ->assertJsonPath('report.rows.0.average', 120_000)
            ->assertJsonPath('personal.0.overrides.motivation_guarantee.base', 120_000);

        $this->artisan('motivation:fix-guarantee-base', ['--user' => $this->head->id])
            ->expectsOutputToContain('пропущен')
            ->assertSuccessful();

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->postJson('/crm/motivation/settings/guarantee', [])->assertForbidden();
    }
}
