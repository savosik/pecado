<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\CrmClientStatusChange;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Ночное возвращение партнёра: за ушедшим снова пошла активность.
 *
 * Команда единственная в CRM, которая меняет стадию сама, поэтому тест держит
 * не только «подняла», но и все случаи, когда поднимать нельзя.
 */
class ClientLifecycleReviveTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = PersonalManager::factory()->create();
    }

    private function client(ClientLifecycleStatus $stage, ?string $changedAt = '2026-06-01 10:00:00'): User
    {
        $client = User::factory()->create(['personal_manager_id' => $this->manager->id]);

        CrmClientProfile::factory()->create([
            'user_id' => $client->id,
            'lifecycle_status' => $stage,
            'lifecycle_changed_at' => $changedAt,
        ]);

        return $client;
    }

    private function stage(User $client): ClientLifecycleStatus
    {
        return CrmClientProfile::query()->where('user_id', $client->id)->firstOrFail()->lifecycle_status;
    }

    #[Test]
    #[TestDox('Заказа достаточно: ушедший с новым заказом возвращается в «Активен»')]
    public function an_order_alone_brings_the_partner_back(): void
    {
        $client = $this->client(ClientLifecycleStatus::CHURNED);
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDays(2)]);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::ACTIVE, $this->stage($client));

        // Смена системная: сотрудника за ней нет, но причина обязана быть.
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $client->id,
            'field' => 'lifecycle',
            'from_value' => 'churned',
            'to_value' => 'active',
            'user_id' => null,
        ]);

        $change = CrmClientStatusChange::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertStringContainsString('заказ от', (string) $change->reason);
    }

    #[Test]
    #[TestDox('Отгрузка тоже возвращает — и «Ушёл к конкуренту», и «Риск ухода»')]
    public function a_shipment_revives_every_configured_stage(): void
    {
        $competitor = $this->client(ClientLifecycleStatus::COMPETITOR);
        $atRisk = $this->client(ClientLifecycleStatus::AT_RISK);

        foreach ([$competitor, $atRisk] as $client) {
            Shipment::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDay()]);
        }

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::ACTIVE, $this->stage($competitor));
        $this->assertSame(ClientLifecycleStatus::ACTIVE, $this->stage($atRisk));
    }

    #[Test]
    #[TestDox('Активность до ухода не возвращает — иначе прошлогодний заказ поднимал бы каждую ночь')]
    public function activity_older_than_the_stage_change_is_ignored(): void
    {
        $client = $this->client(ClientLifecycleStatus::CHURNED, now()->subDays(10)->toDateTimeString());
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDays(200)]);
        Shipment::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDays(180)]);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::CHURNED, $this->stage($client));
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    #[TestDox('Без даты смены стадии работает окно: свежая активность поднимает, старая — нет')]
    public function without_a_change_date_the_window_decides(): void
    {
        config(['crm.lifecycle.revive_window_days' => 60]);

        $fresh = $this->client(ClientLifecycleStatus::CHURNED, null);
        Order::factory()->create(['user_id' => $fresh->id, 'erp_created_at' => now()->subDays(5)]);

        $stale = $this->client(ClientLifecycleStatus::CHURNED, null);
        Order::factory()->create(['user_id' => $stale->id, 'erp_created_at' => now()->subDays(120)]);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::ACTIVE, $this->stage($fresh));
        $this->assertSame(ClientLifecycleStatus::CHURNED, $this->stage($stale));
    }

    #[Test]
    #[TestDox('Банкрота команда не поднимает, а показывает отдельным списком')]
    public function a_bankrupt_partner_is_reported_but_never_changed(): void
    {
        $client = $this->client(ClientLifecycleStatus::BANKRUPT);
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDay()]);

        $this->artisan('crm:lifecycle-revive')
            ->expectsOutputToContain('Требуют решения')
            ->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::BANKRUPT, $this->stage($client));
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    #[TestDox('Активного и спящего без активности команда не трогает')]
    public function partners_without_activity_are_left_alone(): void
    {
        $active = $this->client(ClientLifecycleStatus::ACTIVE);
        $sleeping = $this->client(ClientLifecycleStatus::SLEEPING);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::ACTIVE, $this->stage($active));
        $this->assertSame(ClientLifecycleStatus::SLEEPING, $this->stage($sleeping));
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    #[TestDox('Партнёр без менеджера — не клиент отдела, его команда не трогает')]
    public function a_partner_without_a_manager_is_out_of_scope(): void
    {
        $client = User::factory()->create(['personal_manager_id' => null]);
        CrmClientProfile::factory()->create([
            'user_id' => $client->id,
            'lifecycle_status' => ClientLifecycleStatus::CHURNED,
            'lifecycle_changed_at' => now()->subMonth(),
        ]);
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDay()]);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::CHURNED, $this->stage($client));
    }

    #[Test]
    #[TestDox('Сухой прогон ничего не пишет')]
    public function dry_run_writes_nothing(): void
    {
        $client = $this->client(ClientLifecycleStatus::CHURNED);
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDay()]);

        $this->artisan('crm:lifecycle-revive --dry-run')->assertSuccessful();

        $this->assertSame(ClientLifecycleStatus::CHURNED, $this->stage($client));
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    #[TestDox('Повторный прогон не пишет в журнал второй раз')]
    public function a_second_run_changes_nothing(): void
    {
        $client = $this->client(ClientLifecycleStatus::CHURNED);
        Order::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDay()]);

        $this->artisan('crm:lifecycle-revive')->assertSuccessful();
        $this->artisan('crm:lifecycle-revive')->assertSuccessful();

        $this->assertDatabaseCount('crm_client_status_changes', 1);
    }
}
