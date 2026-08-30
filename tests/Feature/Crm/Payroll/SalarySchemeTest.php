<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PayrollScheme;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SalarySchemeTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private PersonalManager $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
    }

    #[Test]
    #[TestDox('Новая версия схемы действует с месяца; старые месяцы — по старой; умолчания проверяются')]
    public function new_scheme_version(): void
    {
        $next = Carbon::now()->addMonth()->format('Y-m');
        $components = config('payroll.default_scheme.components');
        $components[0]['defaults']['amount'] = 75000;
        foreach ($components as &$entry) {
            if ($entry['key'] === 'new_clients_bonus') {
                $entry['enabled'] = true;
            }
        }

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/scheme', ['effective_from' => $next, 'title' => 'Оклад 75 и новые клиенты', 'components' => $components])
            ->assertOk()
            ->assertJsonPath('scheme.version', 2)
            ->assertJsonPath('versions.0.version', 2)
            ->assertJsonPath('versions.0.title', 'Оклад 75 и новые клиенты')
            ->assertJsonCount(2, 'versions');

        $this->assertSame(2, PayrollScheme::query()->count());

        $this->actingAs($this->head)
            ->getJson('/crm/salary/settings/data?month='.Carbon::now()->format('Y-m'))
            ->assertJsonPath('scheme.version', 1)
            ->assertJsonPath('managers.0.params.salary.amount', 70000);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/settings/data?month='.$next)
            ->assertJsonPath('scheme.version', 2)
            ->assertJsonPath('managers.0.params.salary.amount', 75000)
            ->assertJsonPath('managers.0.params.new_clients_bonus.bonus', 2000);

        // Немонотонная лестница в умолчаниях — отказ с русским сообщением.
        $bad = $components;
        $bad[1]['defaults']['active_clients'] = ['ladder' => [['from_share' => 0.5, 'multiplier' => 1]]];
        $response = $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/scheme', ['effective_from' => $next, 'components' => $bad])
            ->assertStatus(422);
        $this->assertStringContainsString('Первая ступень', implode(' ', $response->json('errors.components')));
        $this->assertSame(2, PayrollScheme::query()->count());
    }

    #[Test]
    #[TestDox('payroll:close-month пересчитывает черновики прошлого месяца и не трогает утверждённые')]
    public function close_month_command(): void
    {
        $previous = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $other = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        PayrollCalculation::factory()->forMonth($previous)->approved()->create(['personal_manager_id' => $other->id, 'total' => 123456]);

        $this->artisan('payroll:close-month')
            ->expectsOutputToContain('2 менедж.')
            ->assertSuccessful();

        $this->assertSame(PayrollCalculation::STATUS_DRAFT, PayrollCalculation::query()->forManager($this->profile->id)->forPeriod($previous)->firstOrFail()->status);
        $this->assertSame(123456.0, (float) PayrollCalculation::query()->forManager($other->id)->forPeriod($previous)->firstOrFail()->total);
    }
}
