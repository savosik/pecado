<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Скрытие контрагентов, исключённых из обмена взаиморасчётами (круг 11).
 *
 * 1С вырезает маркетплейсы из settlement.* и balance.updated, поэтому их
 * снимки балансов на сайте заморожены навсегда. Витрина и CRM их не видят
 * (глобальный scope), но обработчик шины обязан уметь обновить замороженную
 * строку, если 1С всё-таки пришлёт баланс — без дублей.
 */
class ExcludedContractorBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const EXCLUDED_INN = '9714053621';

    private const PARTNER_UUID = '550e8400-e29b-41d4-a716-446655440002';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'erp_id' => '550e8400-e29b-41d4-a716-446655440003',
            'tax_id' => self::EXCLUDED_INN,
        ]);
    }

    private function makeBalance(): ContractorBalance
    {
        return ContractorBalance::query()
            ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
            ->create([
                'user_id' => $this->user->id,
                'company_id' => $this->company->id,
                'tax_id' => self::EXCLUDED_INN,
                'current_balance' => -3060928.09,
                'overdue_debt' => 55114.20,
            ]);
    }

    #[Test]
    public function excluded_balance_is_hidden_from_default_queries(): void
    {
        $this->makeBalance();

        $this->assertSame(0, ContractorBalance::query()->count());
        $this->assertSame(1, ContractorBalance::query()
            ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
            ->count());
    }

    #[Test]
    public function excluded_balance_is_hidden_from_cabinet(): void
    {
        $this->makeBalance();

        $response = $this->actingAs($this->user)->get(route('cabinet.dashboard'));

        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertSame(0.0, (float) ($page['props']['totalBalance'] ?? 0));
    }

    #[Test]
    public function organization_slice_is_hidden_too(): void
    {
        $organization = Organization::factory()->create();

        ContractorOrganizationBalance::query()
            ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
            ->create([
                'user_id' => $this->user->id,
                'company_id' => $this->company->id,
                'organization_id' => $organization->id,
                'current_balance' => -100.00,
                'overdue_debt' => 0,
            ]);

        $this->assertSame(0, ContractorOrganizationBalance::query()->count());
    }

    #[Test]
    public function bus_handler_updates_frozen_row_without_duplicates(): void
    {
        $this->makeBalance();

        app(HandleBalanceUpdated::class)->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-balance-excluded',
            'partner_uuid' => self::PARTNER_UUID,
            'updated_at' => '2026-08-19T10:00:00+03:00',
            'contractors' => [[
                'uuid' => $this->company->erp_id,
                'tax_id' => self::EXCLUDED_INN,
                'current_balance' => 0,
                'overdue_debt' => 0,
            ]],
        ]);

        $rows = ContractorBalance::query()
            ->withoutGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED)
            ->where('tax_id', self::EXCLUDED_INN)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(0.0, (float) $rows->first()->current_balance);
    }

    #[Test]
    public function non_excluded_balance_stays_visible(): void
    {
        ContractorBalance::query()->create([
            'user_id' => $this->user->id,
            'company_id' => null,
            'tax_id' => '7710140679',
            'current_balance' => -500.00,
            'overdue_debt' => 0,
        ]);

        $this->assertSame(1, ContractorBalance::query()->count());
    }
}
