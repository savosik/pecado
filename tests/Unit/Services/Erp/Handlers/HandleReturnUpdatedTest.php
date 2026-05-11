<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\ReturnStatus;
use App\Models\ProductReturn;
use App\Services\Erp\Handlers\HandleReturnUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleReturnUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleReturnUpdated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new HandleReturnUpdated;
    }

    public static function statusFromErpProvider(): array
    {
        return [
            // Канонические ключи v15
            'pending_approval' => ['pending_approval', ReturnStatus::PENDING_APPROVAL],
            'for_return' => ['for_return', ReturnStatus::FOR_RETURN],
            'in_reserve' => ['in_reserve', ReturnStatus::IN_RESERVE],
            'ready_for_shipment' => ['ready_for_shipment', ReturnStatus::READY_FOR_SHIPMENT],
            'completed' => ['completed', ReturnStatus::COMPLETED],
            'rejected' => ['rejected', ReturnStatus::REJECTED],
            // Русские названия из 1С
            'ru: На согласовании' => ['На согласовании', ReturnStatus::PENDING_APPROVAL],
            'ru: К возврату' => ['К возврату', ReturnStatus::FOR_RETURN],
            'ru: В резерве' => ['В резерве', ReturnStatus::IN_RESERVE],
            'ru: К отгрузке' => ['К отгрузке', ReturnStatus::READY_FOR_SHIPMENT],
            'ru: Выполнена' => ['Выполнена', ReturnStatus::COMPLETED],
            'ru: Отклонена' => ['Отклонена', ReturnStatus::REJECTED],
            // Legacy-ключи до v15
            'legacy pending' => ['pending', ReturnStatus::PENDING_APPROVAL],
            'legacy confirmed' => ['confirmed', ReturnStatus::IN_RESERVE],
            'legacy ready_to_ship' => ['ready_to_ship', ReturnStatus::READY_FOR_SHIPMENT],
            'legacy closed' => ['closed', ReturnStatus::COMPLETED],
            'legacy cancelled' => ['cancelled', ReturnStatus::REJECTED],
        ];
    }

    #[Test]
    #[DataProvider('statusFromErpProvider')]
    public function it_applies_each_status_from_erp(string $rawStatus, ReturnStatus $expected): void
    {
        $uuid = 'ret-uuid-'.md5($rawStatus);

        $return = ProductReturn::factory()->create([
            'uuid' => $uuid,
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => $uuid,
            'status' => $rawStatus,
        ]);

        $this->assertSame($expected, $return->fresh()->status);
    }

    #[Test]
    public function it_ignores_unknown_status_and_logs_warning(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => 'ret-uuid-unknown',
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'неизвестный статус') && $ctx['status'] === 'unknown_status');

        $this->handler->handle([
            'uuid' => 'ret-uuid-unknown',
            'status' => 'unknown_status',
        ]);

        $this->assertSame(ReturnStatus::PENDING_APPROVAL, $return->fresh()->status);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')->once();

        $this->handler->handle([
            'event' => 'return.updated',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_return_not_found(): void
    {
        Log::shouldReceive('info')->once()->withArgs(function ($msg) {
            return str_contains($msg, 'возврат не найден');
        });

        $this->handler->handle([
            'uuid' => 'nonexistent-uuid',
        ]);
    }

    #[Test]
    public function it_saves_erp_number_from_payload(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => 'ret-uuid-erp-num',
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'ret-uuid-erp-num',
            'number' => 'ВЗВ-000456',
        ]);

        $this->assertEquals('ВЗВ-000456', $return->fresh()->erp_number);
    }

    #[Test]
    public function it_saves_erp_number_and_status_together(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => 'ret-uuid-combo',
            'status' => ReturnStatus::PENDING_APPROVAL,
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'ret-uuid-combo',
            'number' => 'ВЗВ-000789',
            'status' => 'in_reserve',
        ]);

        $fresh = $return->fresh();
        $this->assertEquals('ВЗВ-000789', $fresh->erp_number);
        $this->assertSame(ReturnStatus::IN_RESERVE, $fresh->status);
    }
}
