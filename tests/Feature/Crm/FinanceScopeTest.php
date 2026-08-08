<?php

namespace Tests\Feature\Crm;

use App\Models\ContractorBalance;
use App\Models\Currency;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Финансовый раздел CRM: изоляция скоупа, сведение валют, бакеты просрочки.
 */
class FinanceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-clients-all.view', 'crm-tasks.create'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);
    }

    /**
     * @return array{0: User, 1: PersonalManager}
     */
    private function makeManagerActor(bool $head = false): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('crm-finance.view');

        if ($head) {
            $actor->givePermissionTo('crm-clients-all.view');
        }

        $card = PersonalManager::create(['name' => $head ? 'РОП' : 'Менеджер', 'user_id' => $actor->id]);

        return [$actor->fresh(), $card];
    }

    private function makeClient(PersonalManager $card): User
    {
        return User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function makeShipment(User $client, array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
            'paid_amount' => 0,
        ], $attrs));
    }

    #[Test]
    public function it_denies_access_without_permission(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::firstOrCreate(['name' => 'crm-clients.view', 'guard_name' => 'web']));

        $this->actingAs($actor->fresh())->get('/crm/finance')->assertForbidden();
    }

    #[Test]
    public function manager_sees_only_own_clients_money(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $mine = $this->makeClient($card);

        $otherCard = PersonalManager::create(['name' => 'Чужой']);
        $foreign = $this->makeClient($otherCard);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($mine)->id,
            'due_date' => Carbon::today()->addDays(5)->toDateString(),
            'amount' => 1000,
        ]);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($foreign)->id,
            'due_date' => Carbon::today()->addDays(5)->toDateString(),
            'amount' => 7777,
        ]);

        $response = $this->actingAs($actor)->get('/crm/finance');

        $response->assertOk();
        $summary = $response->viewData('page')['props']['summary'];

        $this->assertSame(1000.0, $summary['expected_period']);
    }

    #[Test]
    public function manager_filter_works_only_for_head(): void
    {
        [$head, $headCard] = $this->makeManagerActor(head: true);
        $ownClient = $this->makeClient($headCard);

        $otherCard = PersonalManager::create(['name' => 'Второй']);
        $otherClient = $this->makeClient($otherCard);

        foreach ([[$ownClient, 500], [$otherClient, 300]] as [$client, $amount]) {
            ShipmentPaymentSchedule::factory()->create([
                'shipment_id' => $this->makeShipment($client)->id,
                'due_date' => Carbon::today()->addDays(3)->toDateString(),
                'amount' => $amount,
            ]);
        }

        // РОП видит обоих и может отобрать одного.
        $all = $this->actingAs($head)->get('/crm/finance');
        $this->assertSame(800.0, $all->viewData('page')['props']['summary']['expected_period']);

        $filtered = $this->actingAs($head)->get('/crm/finance?manager_ids[]='.$otherCard->id);
        $this->assertSame(300.0, $filtered->viewData('page')['props']['summary']['expected_period']);

        // Рядовой менеджер тем же параметром чужие деньги не достанет.
        [$manager, $managerCard] = $this->makeManagerActor();
        $managerClient = $this->makeClient($managerCard);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($managerClient)->id,
            'due_date' => Carbon::today()->addDays(3)->toDateString(),
            'amount' => 100,
        ]);

        $attempt = $this->actingAs($manager)->get('/crm/finance?manager_ids[]='.$otherCard->id);

        $this->assertSame(100.0, $attempt->viewData('page')['props']['summary']['expected_period']);
        $this->assertSame([], $attempt->viewData('page')['props']['filters']['manager_ids']);
    }

    #[Test]
    public function foreign_currency_is_converted_to_rubles(): void
    {
        Currency::factory()->create(['code' => 'BYN', 'is_base' => false, 'exchange_rate' => 30]);

        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client, ['currency_code' => 'BYN', 'total_amount' => 100])->id,
            'due_date' => Carbon::today()->addDays(2)->toDateString(),
            'amount' => 100,
        ]);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(3000.0, $props['summary']['expected_period']);
        $this->assertSame(3000.0, $props['upcomingRows'][0]['unpaid_rub']);
        $this->assertSame(100.0, $props['upcomingRows'][0]['unpaid_amount']);
    }

    #[Test]
    public function shipments_without_schedule_are_reported_separately(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makeShipment($client, ['total_amount' => 2500, 'paid_amount' => 500]);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        // Долг без плановой даты не попадает в план по датам, но и не теряется.
        $this->assertSame(0.0, $props['summary']['expected_period']);
        $this->assertSame(2000.0, $props['summary']['no_schedule_amount']);
        $this->assertSame(1, $props['noScheduleCount']);
    }

    #[Test]
    public function overdue_rows_fall_into_aging_buckets(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client)->id,
            'due_date' => Carbon::today()->subDays(45)->toDateString(),
            'amount' => 900,
        ]);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];
        $buckets = collect($props['aging']['buckets'])->keyBy('key');

        $this->assertSame(900.0, $buckets['31_60']['amount']);
        $this->assertSame(0.0, $buckets['1_7']['amount']);
        $this->assertSame(900.0, $props['summary']['overdue_amount']);
        $this->assertSame(1, $props['summary']['overdue_clients']);
    }

    #[Test]
    public function closed_schedule_lines_are_not_expected(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client, ['paid_amount' => 1000])->id,
            'due_date' => Carbon::today()->addDays(4)->toDateString(),
            'amount' => 1000,
            'paid_amount' => 1000,
        ]);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(0.0, $props['summary']['expected_period']);
        $this->assertSame([], $props['upcomingRows']);
    }

    #[Test]
    public function balances_show_erp_and_schedule_overdue_side_by_side(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client)->id,
            'due_date' => Carbon::today()->subDays(3)->toDateString(),
            'amount' => 400,
        ]);

        ContractorBalance::create([
            'user_id' => $client->id,
            'tax_id' => '7701234567',
            'current_balance' => -1500,
            'overdue_debt' => 600,
            'balance_erp_updated_at' => Carbon::now(),
        ]);

        $props = $this->actingAs($actor)->get('/crm/finance/balances')->viewData('page')['props'];

        $this->assertCount(1, $props['balances']);
        $this->assertSame(600.0, $props['balances'][0]['overdue_debt']);
        $this->assertSame(400.0, $props['balances'][0]['overdue_by_schedule']);
        $this->assertSame(200.0, $props['balances'][0]['overdue_diff']);
    }

    #[Test]
    public function overdue_page_ignores_period_filter(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client)->id,
            'due_date' => Carbon::today()->subDays(120)->toDateString(),
            'amount' => 750,
        ]);

        // Период фильтров смотрит вперёд, а просрочка всё равно должна быть видна.
        $props = $this->actingAs($actor)
            ->get('/crm/finance/overdue?date_from='.Carbon::today()->toDateString())
            ->viewData('page')['props'];

        $this->assertSame(1, $props['rows']['total']);
        $this->assertSame(750.0, $props['rows']['data'][0]['unpaid_rub']);
        $this->assertTrue($props['rows']['data'][0]['is_overdue']);
    }

    #[Test]
    public function export_returns_xlsx_within_scope(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        ShipmentPaymentSchedule::factory()->create([
            'shipment_id' => $this->makeShipment($client)->id,
            'due_date' => Carbon::today()->addDays(6)->toDateString(),
            'amount' => 1200,
        ]);

        $response = $this->actingAs($actor)->get('/crm/finance/export');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertNotEmpty($response->streamedContent());
    }
}
