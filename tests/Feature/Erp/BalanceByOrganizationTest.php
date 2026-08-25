<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Взаиморасчёты в разрезе наших организаций (v15.8.0, карточка org-06).
 *
 * `contractor_balances` остаётся агрегатом-проекцией: legacy-поведение обязано
 * сохраниться байт в байт, иначе сломается кабинет и админка.
 */
class BalanceByOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_UUID = '550e8400-e29b-41d4-a716-446655440002';

    private const ORG_A = '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce';

    private const ORG_B = '9da1768a-40d4-11e1-a692-001e6711ed1d';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => '550e8400-e29b-41d4-a716-446655440003',
            'tax_id' => '7710140679',
        ]);
    }

    private function handle(array $contractorOverride = [], ?string $updatedAt = '2026-08-01T10:00:00+03:00'): void
    {
        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-'.uniqid(),
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => $updatedAt,
            'contractors' => [array_merge([
                'uuid' => $this->company->erp_id,
                'tax_id' => '7710140679',
                'current_balance' => -15000.50,
                'overdue_debt' => 5000,
            ], $contractorOverride)],
        ]);
    }

    // ──────────────────────────────────────────────
    // Legacy-ветка: поведение не изменилось
    // ──────────────────────────────────────────────

    #[Test]
    public function balance_without_organizations_behaves_as_before(): void
    {
        $this->handle();

        $balance = ContractorBalance::where('user_id', $this->user->id)->firstOrFail();

        $this->assertEquals(-15000.50, $balance->current_balance);
        $this->assertEquals(5000, $balance->overdue_debt);
        $this->assertSame(0, ContractorOrganizationBalance::count());
    }

    /**
     * Пустой массив не должен молча обнулять историю: расчётов «ни с одной
     * организацией» при непустом балансе не бывает.
     */
    #[Test]
    public function empty_organizations_array_is_treated_as_absent(): void
    {
        $this->handle(['organizations' => []]);

        $this->assertSame(0, ContractorOrganizationBalance::count());
        $this->assertEquals(-15000.50, ContractorBalance::first()->current_balance);
    }

    // ──────────────────────────────────────────────
    // Разрез по организациям
    // ──────────────────────────────────────────────

    #[Test]
    public function organizations_breakdown_is_stored_alongside_aggregate(): void
    {
        $orgA = Organization::factory()->create(['external_id' => self::ORG_A]);
        $orgB = Organization::factory()->create(['external_id' => self::ORG_B]);

        $this->handle([
            'organizations' => [
                ['uuid' => self::ORG_A, 'current_balance' => -10000.50, 'overdue_debt' => 5000],
                ['uuid' => self::ORG_B, 'current_balance' => -5000, 'overdue_debt' => 0],
            ],
        ]);

        // Агрегат не тронут
        $this->assertEquals(-15000.50, ContractorBalance::first()->current_balance);

        $this->assertSame(2, ContractorOrganizationBalance::count());

        $a = ContractorOrganizationBalance::where('organization_id', $orgA->id)->firstOrFail();
        $this->assertEquals(-10000.50, $a->current_balance);
        $this->assertEquals(5000, $a->overdue_debt);
        $this->assertSame($this->company->id, $a->company_id);
        $this->assertSame($this->user->id, $a->user_id);

        $b = ContractorOrganizationBalance::where('organization_id', $orgB->id)->firstOrFail();
        $this->assertEquals(-5000, $b->current_balance);
    }

    #[Test]
    public function unknown_organization_uuid_creates_stub(): void
    {
        $this->handle([
            'organizations' => [
                ['uuid' => self::ORG_A, 'current_balance' => -15000.50, 'overdue_debt' => 5000],
            ],
        ]);

        $organization = Organization::where('external_id', self::ORG_A)->firstOrFail();

        $this->assertTrue($organization->is_stub);
        $this->assertSame(1, ContractorOrganizationBalance::where('organization_id', $organization->id)->count());
    }

    /**
     * Долг погашен — строка обнуляется, но остаётся: иначе история пропадёт
     * из отчётов задним числом.
     */
    #[Test]
    public function organization_missing_from_new_message_is_zeroed_not_deleted(): void
    {
        $orgA = Organization::factory()->create(['external_id' => self::ORG_A]);
        $orgB = Organization::factory()->create(['external_id' => self::ORG_B]);

        $this->handle([
            'organizations' => [
                ['uuid' => self::ORG_A, 'current_balance' => -10000, 'overdue_debt' => 5000],
                ['uuid' => self::ORG_B, 'current_balance' => -5000, 'overdue_debt' => 0],
            ],
        ]);

        $this->handle([
            'current_balance' => -10000,
            'organizations' => [
                ['uuid' => self::ORG_A, 'current_balance' => -10000, 'overdue_debt' => 5000],
            ],
        ], '2026-08-02T10:00:00+03:00');

        $b = ContractorOrganizationBalance::where('organization_id', $orgB->id)->first();

        $this->assertNotNull($b, 'Строка исчезнувшей организации не должна удаляться');
        $this->assertEquals(0, $b->current_balance);
        $this->assertEquals(0, $b->overdue_debt);
        $this->assertEquals(-10000, ContractorOrganizationBalance::where('organization_id', $orgA->id)->first()->current_balance);
    }

    /**
     * Расхождение сумм — сигнал, что часть расчётов в 1С не разнесена.
     * Итог берём с contractor-уровня, но громко пишем в лог.
     */
    #[Test]
    public function sum_mismatch_keeps_contractor_total_and_logs_warning(): void
    {
        Organization::factory()->create(['external_id' => self::ORG_A]);

        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'не сходится'));

        $this->handle([
            'current_balance' => -15000.50,
            'organizations' => [
                ['uuid' => self::ORG_A, 'current_balance' => -10000, 'overdue_debt' => 0],
            ],
        ]);

        $this->assertEquals(-15000.50, ContractorBalance::first()->current_balance);
    }

    #[Test]
    public function stale_message_does_not_overwrite_newer_breakdown(): void
    {
        $orgA = Organization::factory()->create(['external_id' => self::ORG_A]);

        $this->handle([
            'organizations' => [['uuid' => self::ORG_A, 'current_balance' => -10000, 'overdue_debt' => 0]],
        ], '2026-08-02T10:00:00+03:00');

        // Сообщение «из прошлого» — например, переставленное в очереди
        $this->handle([
            'organizations' => [['uuid' => self::ORG_A, 'current_balance' => -999, 'overdue_debt' => 0]],
        ], '2026-08-01T10:00:00+03:00');

        $this->assertEquals(
            -10000,
            ContractorOrganizationBalance::where('organization_id', $orgA->id)->first()->current_balance,
        );
    }
}
