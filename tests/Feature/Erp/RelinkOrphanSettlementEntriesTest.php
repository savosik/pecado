<?php

namespace Tests\Feature\Erp;

use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorCreated;
use App\Services\Erp\Handlers\HandleSettlementPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Перепривязка осиротевших строк взаиморасчётов при приходе карточки контрагента
 * (круг 11: движения старых контрагентов приезжали раньше их карточек).
 */
class RelinkOrphanSettlementEntriesTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTOR_UUID = '00000000-0000-4000-a000-000000002000';

    private const PARTNER_UUID = '00000000-0000-4000-a000-000000000100';

    #[Test]
    public function contractor_created_relinks_orphan_entries(): void
    {
        // Движение пришло раньше карточки — строка ложится без company_id.
        app(HandleSettlementPosted::class)->handle([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-orphan',
            'document_uuid' => '00000000-0000-4000-a000-000000003000',
            'document_kind' => 'payment',
            'entries' => [[
                'uuid' => '00000000-0000-4000-a000-000000003001',
                'type' => 'payment_in',
                'amount' => 50000,
                'amount_rub' => 50000,
                'date' => '2026-08-01',
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ]);

        $entry = SettlementEntry::query()->firstOrFail();
        $this->assertNull($entry->company_id);

        // Карточка наконец приходит — строка перепривязывается.
        $user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);

        app(HandleContractorCreated::class)->handle([
            'event' => 'contractor.created',
            'uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'name' => 'АВАНГАРД РУС ООО',
            'tax_id' => '9724241487',
        ]);

        $entry->refresh();
        $this->assertNotNull($entry->company_id);
        $this->assertSame('9724241487', $entry->company->tax_id);
        $this->assertSame($user->id, $entry->user_id);
    }

    #[Test]
    public function foreign_orphans_stay_untouched(): void
    {
        app(HandleSettlementPosted::class)->handle([
            'event' => 'settlement.posted',
            'message_id' => 'msg-posted-foreign',
            'document_uuid' => '00000000-0000-4000-a000-000000004000',
            'document_kind' => 'payment',
            'entries' => [[
                'uuid' => '00000000-0000-4000-a000-000000004001',
                'type' => 'payment_in',
                'amount' => 100,
                'date' => '2026-08-01',
                'contractor_uuid' => '00000000-0000-4000-a000-000000009999',
            ]],
        ]);

        User::factory()->create(['erp_id' => self::PARTNER_UUID]);

        app(HandleContractorCreated::class)->handle([
            'event' => 'contractor.created',
            'uuid' => self::CONTRACTOR_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'name' => 'АВАНГАРД РУС ООО',
            'tax_id' => '9724241487',
        ]);

        $this->assertNull(SettlementEntry::query()->firstOrFail()->company_id);
    }
}
