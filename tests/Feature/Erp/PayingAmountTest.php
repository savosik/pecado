<?php

namespace Tests\Feature\Erp;

use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use App\Services\Erp\Handlers\HandleSettlementCheckpoint;
use App\Services\Erp\Handlers\HandleSettlementPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ресурс «Оплачивается» (`paying_amount`, спека v16.4.0, круг 11).
 *
 * Живой долг пары в 1С = SUM(amount) − SUM(paying_amount); баланс ленты — по-прежнему
 * SUM(amount). Поле опционально: сообщения v16.0 его не несут, и «нет данных»
 * обязано отличаться от «ноль».
 */
class PayingAmountTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTOR_UUID = '00000000-0000-4000-a000-000000005000';

    #[Test]
    public function posted_stores_paying_amount(): void
    {
        app(HandleSettlementPosted::class)->handle([
            'event' => 'settlement.posted',
            'message_id' => 'msg-paying-1',
            'spec_version' => '16.4',
            'document_uuid' => '00000000-0000-4000-a000-000000005100',
            'document_kind' => 'shipment',
            'entries' => [[
                'uuid' => '00000000-0000-4000-a000-000000005101',
                'type' => 'shipment',
                'amount' => -14972.75,
                'paying_amount' => 4972.75,
                'date' => '2026-08-19',
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ]);

        $entry = SettlementEntry::query()->sole();

        $this->assertEqualsWithDelta(4972.75, (float) $entry->paying_amount, 0.01);
        // Баланс ленты считается по amount и «Оплачивается» не вычитает.
        $this->assertEqualsWithDelta(-14972.75, (float) $entry->amount, 0.01);
    }

    #[Test]
    public function missing_paying_amount_stays_null_not_zero(): void
    {
        app(HandleSettlementPosted::class)->handle([
            'event' => 'settlement.posted',
            'message_id' => 'msg-paying-2',
            'spec_version' => '16.0',
            'document_uuid' => '00000000-0000-4000-a000-000000005200',
            'document_kind' => 'shipment',
            'entries' => [[
                'uuid' => '00000000-0000-4000-a000-000000005201',
                'type' => 'shipment',
                'amount' => -100.00,
                'date' => '2026-08-19',
                'contractor_uuid' => self::CONTRACTOR_UUID,
            ]],
        ]);

        $this->assertNull(SettlementEntry::query()->sole()->paying_amount);
    }

    #[Test]
    public function checkpoint_stores_paying_amount(): void
    {
        app(HandleSettlementCheckpoint::class)->handle([
            'event' => 'settlement.checkpoint',
            'message_id' => 'msg-paying-cp',
            'spec_version' => '16.4',
            'as_of_date' => '2026-08-19',
            'amount' => -18602.75,
            'paying_amount' => 3000.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'currency_code' => 'RUB',
        ]);

        $checkpoint = SettlementCheckpoint::query()->sole();

        $this->assertEqualsWithDelta(3000.00, (float) $checkpoint->paying_amount, 0.01);
        $this->assertEqualsWithDelta(-18602.75, (float) $checkpoint->amount, 0.01);
    }

    #[Test]
    public function checkpoint_without_field_keeps_null(): void
    {
        app(HandleSettlementCheckpoint::class)->handle([
            'event' => 'settlement.checkpoint',
            'message_id' => 'msg-paying-cp-old',
            'spec_version' => '16.0',
            'as_of_date' => '2026-08-19',
            'amount' => -500.00,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'currency_code' => 'RUB',
        ]);

        $this->assertNull(SettlementCheckpoint::query()->sole()->paying_amount);
    }
}
