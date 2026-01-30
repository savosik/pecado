<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Currency;
use App\Models\UserBalance;

class UserBalanceTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_can_create_user_balance(): void
    {
        $user = User::factory()->create();
        $currency = Currency::factory()->create();
        
        $balance = UserBalance::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 100.50,
            'overdue_debt' => 0,
        ]);

        $this->assertDatabaseHas('user_balances', [
            'id' => $balance->id,
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'balance' => 100.50,
        ]);
    }

    public function test_can_retrieve_user_balances(): void
    {
        $user = User::factory()->create();
        $currency1 = Currency::factory()->create(['code' => 'USD']);
        $currency2 = Currency::factory()->create(['code' => 'EUR']);

        UserBalance::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency1->id,
            'balance' => 100,
        ]);

        UserBalance::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $currency2->id,
            'balance' => -50,
            'overdue_debt' => 10,
        ]);

        $this->assertCount(2, $user->balances);
        $this->assertEquals(100, $user->balances->firstWhere('currency_id', $currency1->id)->balance);
        $this->assertEquals(-50, $user->balances->firstWhere('currency_id', $currency2->id)->balance);
    }
}
