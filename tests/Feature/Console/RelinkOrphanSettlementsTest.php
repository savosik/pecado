<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разовая доливка осиротевших строк взаиморасчётов (круг 11).
 */
class RelinkOrphanSettlementsTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTOR_UUID = '00000000-0000-4000-a000-000000006000';

    private function orphanEntry(string $contractorUuid): SettlementEntry
    {
        return SettlementEntry::query()->create([
            'uuid' => 'e-'.substr($contractorUuid, -6).'-'.uniqid(),
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => 'payment_in',
            'date' => '2026-08-01',
            'amount' => 1000,
            'amount_rub' => 1000,
            'currency_code' => 'RUB',
            'contractor_uuid' => $contractorUuid,
            'company_id' => null,
            'source' => 'erp',
        ]);
    }

    #[Test]
    public function command_links_entries_and_checkpoints(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => self::CONTRACTOR_UUID,
            'tax_id' => '9724241487',
        ]);

        $entry = $this->orphanEntry(self::CONTRACTOR_UUID);
        $foreign = $this->orphanEntry('00000000-0000-4000-a000-000000009999');

        $checkpoint = SettlementCheckpoint::query()->create([
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => '',
            'organization_uuid' => '',
            'currency_code' => 'RUB',
            'as_of_date' => '2026-08-19',
            'amount' => -500,
            'amount_rub' => -500,
            'company_id' => null,
        ]);

        $this->artisan('erp:relink-orphan-settlements')->assertSuccessful();

        $this->assertSame($company->id, $entry->refresh()->company_id);
        $this->assertSame($company->id, $checkpoint->refresh()->company_id);
        $this->assertSame($user->id, $entry->user_id);

        // Чужая сирота не тронута: у неё своей карточки нет.
        $this->assertNull($foreign->refresh()->company_id);
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        Company::factory()->create([
            'user_id' => User::factory()->create()->id,
            'erp_id' => self::CONTRACTOR_UUID,
            'tax_id' => '9724241487',
        ]);

        $entry = $this->orphanEntry(self::CONTRACTOR_UUID);

        $this->artisan('erp:relink-orphan-settlements', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($entry->refresh()->company_id);
    }
}
