<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationObjection;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Motivation\PayslipService;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Расчётный лист (карточка mot-31): версии, документы из снимка, возражение, PDF.
 */
class MotivationPayslipTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private User $worker;

    private PersonalManager $profile;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();

        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');

        // Работник с выданным правом — возражение подаёт он сам.
        $this->worker = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->worker->assignRole('sales-manager');
        $this->worker->givePermissionTo(Permission::findByName('crm-motivation.view'));
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->worker->id, 'name' => 'Курочкина']);

        app(MotivationSchemeInstaller::class)->install($this->month);
    }

    private function ship(User $partner, float $amount, CarbonImmutable $date): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }

    #[Test]
    #[TestDox('Лист читает документы из снимка и группирует их по партнёрам')]
    public function payslip_groups_snapshot_documents_by_partner(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Альфа']);
        $this->ship($partner, 100_000, $this->month->addDays(1));
        $this->ship($partner, 50_000, $this->month->addDays(3));

        $calculation = app(PayrollCalculationService::class)->ensureDraft($this->profile->id, $this->month);
        $slip = app(PayslipService::class)->build($calculation);

        $this->assertFalse($slip['calculation']['frozen']);
        $this->assertCount(1, $slip['versions']);
        $this->assertSame(2, $slip['shipments']['documents_count']);
        $this->assertSame('Альфа', $slip['shipments']['base'][0]['partner_name']);
        $this->assertSame(150_000.0, $slip['shipments']['base'][0]['amount']);
        $this->assertCount(2, $slip['shipments']['base'][0]['documents']);
        $this->assertSame([], $slip['shipments']['new']);
        $this->assertFalse($slip['objection']['can_object'], 'По черновику возражение не подаётся');
    }

    #[Test]
    #[TestDox('Версии месяца переключаются, утверждённая не меняется от новых отгрузок')]
    public function versions_are_listed_and_frozen_one_keeps_its_numbers(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $this->ship($partner, 100_000, $this->month->addDays(1));

        $service = app(PayrollCalculationService::class);
        $first = $service->approve($service->ensureDraft($this->profile->id, $this->month), $this->head);
        $service->reopen($first, $this->head, 'Ошибка в данных');

        $this->ship($partner, 900_000, $this->month->addDays(2));
        $service->recalculateDraft($this->profile->id, $this->month);

        $payslips = app(PayslipService::class);
        $v1 = $payslips->build($payslips->version($this->profile->id, $this->month, 1));
        $latest = $payslips->build($payslips->version($this->profile->id, $this->month, null));

        $this->assertCount(2, $latest['versions']);
        $this->assertSame(1, $v1['calculation']['version']);
        $this->assertTrue($v1['calculation']['frozen']);
        $this->assertSame(1, $v1['shipments']['documents_count'], 'Утверждённая версия не видит отгрузку, пришедшую после');
        $this->assertSame(2, $latest['shipments']['documents_count']);
        $this->assertSame(2, $latest['calculation']['version']);
    }

    #[Test]
    #[TestDox('Возражение: в срок принимается, второе открытое — нет, после срока — нет')]
    public function objection_respects_the_deadline_and_single_open_rule(): void
    {
        $service = app(PayrollCalculationService::class);
        $calculation = $service->approve($service->ensureDraft($this->profile->id, $this->month), $this->head);
        $payslips = app(PayslipService::class);

        $state = $payslips->objection($calculation, $calculation->approved_at);
        $this->assertTrue($state['can_object']);
        $this->assertNotNull($state['deadline_on']);

        $objection = $payslips->object($calculation, $this->worker, 'Не учтена отгрузка партнёру Альфа от 3-го числа');
        $this->assertSame(MotivationObjection::STATUS_OPEN, $objection->status);

        $this->assertFalse($payslips->objection($calculation)['can_object'], 'Второе открытое возражение не принимается');

        $objection->update(['status' => MotivationObjection::STATUS_REJECTED, 'response' => 'Отгрузка в следующем месяце']);
        $late = CarbonImmutable::instance($calculation->approved_at)->addDays(30);
        $expired = $payslips->objection($calculation, $late);
        $this->assertFalse($expired['can_object']);
        $this->assertStringContainsString('истёк', (string) $expired['reason']);
    }

    #[Test]
    #[TestDox('Возражение подаёт сам работник; чужой расчёт — 403; короткая причина отклоняется')]
    public function objection_endpoint_is_for_the_own_calculation(): void
    {
        $service = app(PayrollCalculationService::class);
        $calculation = $service->approve($service->ensureDraft($this->profile->id, $this->month), $this->head);

        $this->actingAs($this->worker)
            ->postJson('/crm/motivation/objection', ['calculation' => $calculation->id, 'reason' => 'мало'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->worker)
            ->postJson('/crm/motivation/objection', ['calculation' => $calculation->id, 'reason' => 'Не учтена отгрузка партнёру от 12-го числа'])
            ->assertOk();

        $stranger = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $stranger->assignRole('sales-manager');
        $stranger->givePermissionTo(Permission::findByName('crm-motivation.view'));
        PersonalManager::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->postJson('/crm/motivation/objection', ['calculation' => $calculation->id, 'reason' => 'Пытаюсь возразить по чужому расчёту'])
            ->assertForbidden();

        $this->assertSame(1, MotivationObjection::query()->count());
    }

    #[Test]
    #[TestDox('Страница и PDF открываются; чужой manager у работника игнорируется')]
    public function page_and_pdf_are_served(): void
    {
        $this->actingAs($this->worker)
            ->get('/crm/motivation/payslip?manager=999')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Payslip')
                ->where('manager.id', $this->profile->id)
                ->where('is_own', true)
                ->has('slip.lines')
                ->has('slip.versions', 1));

        $response = $this->actingAs($this->worker)->get('/crm/motivation/payslip/pdf');
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
    }
}
