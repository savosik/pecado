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
            'pending' => ['pending', ReturnStatus::PENDING],
            'confirmed' => ['confirmed', ReturnStatus::CONFIRMED],
            'ready_to_ship' => ['ready_to_ship', ReturnStatus::READY_TO_SHIP],
            'closed' => ['closed', ReturnStatus::CLOSED],
            'cancelled' => ['cancelled', ReturnStatus::CANCELLED],
        ];
    }

    #[Test]
    #[DataProvider('statusFromErpProvider')]
    public function it_applies_each_status_from_erp(string $rawStatus, ReturnStatus $expected): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => 'ret-uuid-'.$rawStatus,
            'status' => ReturnStatus::PENDING,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'ret-uuid-'.$rawStatus,
            'status' => $rawStatus,
        ]);

        $this->assertSame($expected, $return->fresh()->status);
    }

    #[Test]
    public function it_ignores_unknown_status_and_logs_warning(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => 'ret-uuid-unknown',
            'status' => ReturnStatus::PENDING,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'неизвестный статус') && $ctx['status'] === 'unknown_status');

        $this->handler->handle([
            'uuid' => 'ret-uuid-unknown',
            'status' => 'unknown_status',
        ]);

        $this->assertSame(ReturnStatus::PENDING, $return->fresh()->status);
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
            'status' => ReturnStatus::PENDING,
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'ret-uuid-combo',
            'number' => 'ВЗВ-000789',
            'status' => 'confirmed',
        ]);

        $fresh = $return->fresh();
        $this->assertEquals('ВЗВ-000789', $fresh->erp_number);
        $this->assertSame(ReturnStatus::CONFIRMED, $fresh->status);
    }
}
