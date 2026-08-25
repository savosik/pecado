<?php

namespace Tests\Feature\Debt;

use App\Models\ContractorBalance;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyBalancesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithOverdue(float $overdue1c, float $scheduleOutstanding): User
    {
        $user = User::factory()->create();

        ContractorBalance::create([
            'user_id' => $user->id,
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'current_balance' => -$overdue1c,
            'overdue_debt' => $overdue1c,
            'balance_erp_updated_at' => now(),
        ]);

        if ($scheduleOutstanding > 0) {
            $shipment = Shipment::factory()->create([
                'user_id' => $user->id,
                'currency_code' => 'RUB',
            ]);

            // Просроченная плановая строка регистра — то, с чем команда
            // сравнивает просрочку 1С после перехода на единственное ядро.
            SettlementEntry::factory()->create([
                'nature' => SettlementEntry::NATURE_PLAN,
                'type' => SettlementEntry::TYPE_PAYMENT_DUE,
                'user_id' => $user->id,
                'document_uuid' => $shipment->uuid,
                'document_kind' => 'shipment',
                'date' => now()->subDays(10)->toDateString(),
                'amount' => $scheduleOutstanding,
                'settled_amount' => 0,
                'currency_code' => 'RUB',
            ]);
        }

        return $user;
    }

    #[Test]
    public function matched_client_is_offered_as_pilot_candidate(): void
    {
        $user = $this->clientWithOverdue(5000.00, 5000.00);

        $this->artisan('debt:verify-balances')
            ->expectsOutputToContain('Сходится в пределах')
            ->expectsOutputToContain('CABINET_FINANCE_PILOT_USERS='.$user->id)
            ->assertSuccessful();
    }

    #[Test]
    public function mismatched_client_is_reported_and_fails_strict_run(): void
    {
        // 1С видит 10 000 ₽ просрочки, график — только 4 000: расхождение 6 000.
        $this->clientWithOverdue(10000.00, 4000.00);

        $this->artisan('debt:verify-balances')->assertSuccessful();

        $this->artisan('debt:verify-balances --strict')
            ->expectsOutputToContain('Расходится: 1')
            ->assertFailed();
    }

    #[Test]
    public function stale_balance_is_counted(): void
    {
        $user = $this->clientWithOverdue(0.00, 0.00);
        $user->contractorBalances()->update([
            'balance_erp_updated_at' => now()->subDays(30),
        ]);

        $staleDate = now()->subDays((int) config('debt.stale_after_days'))->format('d.m.Y');

        $this->artisan('debt:verify-balances')
            ->expectsOutputToContain("Протухших балансов (старше {$staleDate}): 1.")
            ->assertSuccessful();
    }

    #[Test]
    public function paid_schedule_lines_do_not_count_as_site_overdue(): void
    {
        $user = User::factory()->create();

        ContractorBalance::create([
            'user_id' => $user->id,
            'tax_id' => '7700000001',
            'current_balance' => 0,
            'overdue_debt' => 0,
            'balance_erp_updated_at' => now(),
        ]);

        $shipment = Shipment::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'RUB',
        ]);

        // Просроченная, но полностью закрытая авансом строка — сайт долга не видит,
        // 1С тоже: клиент сходится и попадает в кандидаты пилота.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $user->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => now()->subDays(5)->toDateString(),
            'amount' => 3000,
            'settled_amount' => 3000,
            'currency_code' => 'RUB',
        ]);

        $this->artisan('debt:verify-balances')
            ->expectsOutputToContain('CABINET_FINANCE_PILOT_USERS='.$user->id)
            ->assertSuccessful();
    }
}
