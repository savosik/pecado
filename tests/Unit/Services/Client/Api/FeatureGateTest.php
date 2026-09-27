<?php

namespace Tests\Unit\Services\Client\Api;

use App\Models\User;
use App\Services\Client\Api\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[TestDox('Гейты повторяют предикаты кабинета: флаги, пилот финансов, режим резерва')]
    public function gates_follow_cabinet_predicates(): void
    {
        $user = User::factory()->create(['reserve_allowed' => true]);

        config(['documents.enabled' => false, 'contracts.cabinet_enabled' => false, 'cabinet.finance_enabled' => false,
            'cabinet.finance_pilot_user_ids' => '', 'order_reserve.enabled' => false, 'order_reserve.canary' => '',
            'debt.enabled' => false]);

        $this->assertTrue(FeatureGate::NONE->allows($user));
        $this->assertFalse(FeatureGate::DOCUMENTS->allows($user));
        $this->assertFalse(FeatureGate::CONTRACTS->allows($user));
        $this->assertFalse(FeatureGate::FINANCE->allows($user));
        $this->assertFalse(FeatureGate::RESERVE->allows($user));
        $this->assertFalse(FeatureGate::ORDER_CANCEL->allows($user));

        config(['documents.enabled' => true, 'contracts.cabinet_enabled' => true, 'order_reserve.enabled' => true]);
        $this->assertTrue(FeatureGate::DOCUMENTS->allows($user));
        $this->assertTrue(FeatureGate::CONTRACTS->allows($user));
        $this->assertTrue(FeatureGate::RESERVE->allows($user));
        $this->assertTrue(FeatureGate::ORDER_CANCEL->allows($user));

        // Пилот финансов открывает и платёжку.
        config(['cabinet.finance_pilot_user_ids' => (string) $user->id]);
        $this->assertTrue(FeatureGate::FINANCE->allows($user));
        $this->assertTrue(FeatureGate::PAYMENT_ORDERS->allows($user));

        $this->assertFalse(FeatureGate::FINANCE->allows(User::factory()->create()));
    }

    #[Test]
    #[TestDox('У каждого гейта есть код и причина на русском')]
    public function every_gate_has_code_and_reason(): void
    {
        foreach (FeatureGate::cases() as $gate) {
            if ($gate === FeatureGate::NONE) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $gate->code());
            $this->assertMatchesRegularExpression('/[А-Яа-я]/u', $gate->reason());
        }
    }
}
