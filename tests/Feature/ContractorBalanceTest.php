<?php

namespace Tests\Feature;

use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContractorBalanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_contractor_balance(): void
    {
        $user = User::factory()->create();

        $balance = ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '1234567890',
            'current_balance' => -15000.50,
            'overdue_debt'    => 5000.00,
        ]);

        $this->assertDatabaseHas('contractor_balances', [
            'user_id'        => $user->id,
            'tax_id' => '1234567890',
        ]);

        $this->assertEquals(-15000.50, (float)$balance->current_balance);
        $this->assertEquals(5000.00, (float)$balance->overdue_debt);
    }

    #[Test]
    public function it_can_store_overdue_details(): void
    {
        $user = User::factory()->create();

        $balance = ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '9876543210',
            'current_balance' => -30000.00,
            'overdue_debt'    => 30000.00,
        ]);

        ContractorBalanceOverdueDetail::create([
            'contractor_balance_id' => $balance->id,
            'shipment_uuid'         => 'ship-aaa-bbb',
            'amount'                => 30000.00,
            'due_date'              => '2026-01-10',
        ]);

        $this->assertCount(1, $balance->overdueDetails);
        $this->assertEquals('ship-aaa-bbb', $balance->overdueDetails->first()->shipment_uuid);
    }

    #[Test]
    public function it_cascades_delete_of_overdue_details(): void
    {
        $user = User::factory()->create();

        $balance = ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '1111111111',
            'current_balance' => 0,
            'overdue_debt'    => 1000.00,
        ]);

        ContractorBalanceOverdueDetail::create([
            'contractor_balance_id' => $balance->id,
            'shipment_uuid'         => 'ship-delete-test',
            'amount'                => 1000.00,
            'due_date'              => '2026-02-28',
        ]);

        $balance->delete();

        $this->assertDatabaseMissing('contractor_balance_overdue_details', [
            'shipment_uuid' => 'ship-delete-test',
        ]);
    }

    #[Test]
    public function it_enforces_unique_user_contractor_inn(): void
    {
        $user = User::factory()->create();

        ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '2222222222',
            'current_balance' => 0,
            'overdue_debt'    => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ContractorBalance::create([
            'user_id'         => $user->id,
            'tax_id'  => '2222222222',
            'current_balance' => -5000.00,
            'overdue_debt'    => 0,
        ]);
    }
}
