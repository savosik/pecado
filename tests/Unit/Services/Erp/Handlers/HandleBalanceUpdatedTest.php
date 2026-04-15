<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\User;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleBalanceUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleBalanceUpdated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new HandleBalanceUpdated();
    }

    /** @test */
    public function it_does_nothing_when_partner_uuid_missing(): void
    {
        $this->handler->handle([
            'contractors' => [],
        ]);

        $this->assertDatabaseCount('contractor_balances', 0);
    }

    /** @test */
    public function it_does_nothing_when_user_not_found(): void
    {
        $this->handler->handle([
            'partner_uuid' => 'non-existent-uuid',
            'contractors'  => [],
        ]);

        $this->assertDatabaseCount('contractor_balances', 0);
    }

    /** @test */
    public function it_creates_contractor_balance_from_event(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-001']);

        $this->handler->handle([
            'partner_uuid' => 'partner-001',
            'contractors'  => [
                [
                    'tax_id'  => '1234567890',
                    'current_balance' => -125000.00,
                    'overdue_debt'    => 50000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => 's-uuid-001', 'amount' => 30000.00, 'due_date' => '2026-01-15'],
                        ['shipment_uuid' => 's-uuid-002', 'amount' => 20000.00, 'due_date' => '2026-02-01'],
                    ],
                ],
            ],
            'updated_at' => '2026-02-16T10:00:00',
        ]);

        $balance = ContractorBalance::where('user_id', $user->id)
            ->where('tax_id', '1234567890')
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(-125000.00, (float)$balance->current_balance);
        $this->assertEquals(50000.00, (float)$balance->overdue_debt);
        $this->assertCount(2, $balance->overdueDetails);
    }

    /** @test */
    public function it_updates_existing_contractor_balance(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-002']);

        ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '9876543210',
            'current_balance' => -10000.00,
            'overdue_debt'    => 0,
        ]);

        $this->handler->handle([
            'partner_uuid' => 'partner-002',
            'contractors'  => [
                [
                    'tax_id'  => '9876543210',
                    'current_balance' => -20000.00,
                    'overdue_debt'    => 5000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => 's-new', 'amount' => 5000.00, 'due_date' => '2026-03-01'],
                    ],
                ],
            ],
            'updated_at' => '2026-02-17T10:00:00',
        ]);

        $balance = ContractorBalance::where('user_id', $user->id)
            ->where('tax_id', '9876543210')
            ->first();

        $this->assertEquals(-20000.00, (float)$balance->current_balance);
        $this->assertEquals(5000.00, (float)$balance->overdue_debt);
        $this->assertCount(1, $balance->overdueDetails);
    }

    /** @test */
    public function it_replaces_overdue_details_on_update(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-003']);

        $balance = ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '1111111111',
            'current_balance' => 0,
            'overdue_debt'    => 0,
        ]);

        ContractorBalanceOverdueDetail::create([
            'contractor_balance_id' => $balance->id,
            'shipment_uuid'         => 'old-shipment',
            'amount'                => 1000.00,
            'due_date'              => '2026-01-01',
        ]);

        $this->handler->handle([
            'partner_uuid' => 'partner-003',
            'contractors'  => [
                [
                    'tax_id'  => '1111111111',
                    'current_balance' => 0,
                    'overdue_debt'    => 2000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => 'new-shipment-1', 'amount' => 1200.00, 'due_date' => '2026-04-01'],
                        ['shipment_uuid' => 'new-shipment-2', 'amount' => 800.00, 'due_date' => '2026-05-01'],
                    ],
                ],
            ],
            'updated_at' => '2026-02-18T10:00:00',
        ]);

        $balance->refresh();
        $details = $balance->overdueDetails()->get();
        $this->assertCount(2, $details);
        $this->assertFalse($details->pluck('shipment_uuid')->contains('old-shipment'));
    }

    /** @test */
    public function it_handles_multiple_contractors(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-004']);

        $this->handler->handle([
            'partner_uuid' => 'partner-004',
            'contractors'  => [
                [
                    'tax_id'  => 'INN-001',
                    'current_balance' => -1000.00,
                    'overdue_debt'    => 0,
                    'overdue_details' => [],
                ],
                [
                    'tax_id'  => 'INN-002',
                    'current_balance' => -5000.00,
                    'overdue_debt'    => 2000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => 'ship-x', 'amount' => 2000.00, 'due_date' => '2026-03-15'],
                    ],
                ],
            ],
            'updated_at' => '2026-02-16T10:00:00',
        ]);

        $this->assertDatabaseCount('contractor_balances', 2);
        $this->assertDatabaseCount('contractor_balance_overdue_details', 1);
    }

    /** @test */
    public function it_links_company_by_inn_when_found(): void
    {
        $user    = User::factory()->create(['erp_id' => 'partner-005']);
        $company = Company::factory()->create(['user_id' => $user->id, 'tax_id' => '5555555555']);

        $this->handler->handle([
            'partner_uuid' => 'partner-005',
            'contractors'  => [
                [
                    'tax_id'  => '5555555555',
                    'current_balance' => -3000.00,
                    'overdue_debt'    => 0,
                    'overdue_details' => [],
                ],
            ],
            'updated_at' => '2026-02-16T10:00:00',
        ]);

        $balance = ContractorBalance::where('tax_id', '5555555555')->first();
        $this->assertEquals($company->id, $balance->company_id);
    }
}
