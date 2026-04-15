<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\ProductReturn;
use App\Services\Erp\Handlers\HandleReturnUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleReturnUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleReturnUpdated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new HandleReturnUpdated();
    }

    #[Test]
    public function it_updates_status(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid'   => 'ret-uuid-001',
            'status' => 'pending',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid'   => 'ret-uuid-001',
            'status' => 'approved',
        ]);

        $this->assertEquals('approved', $return->fresh()->status->value);
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
            'uuid'       => 'ret-uuid-erp-num',
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid'   => 'ret-uuid-erp-num',
            'number' => 'ВЗВ-000456',
        ]);

        $this->assertEquals('ВЗВ-000456', $return->fresh()->erp_number);
    }

    #[Test]
    public function it_saves_erp_number_and_status_together(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid'       => 'ret-uuid-combo',
            'status'     => 'pending',
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid'   => 'ret-uuid-combo',
            'number' => 'ВЗВ-000789',
            'status' => 'approved',
        ]);

        $fresh = $return->fresh();
        $this->assertEquals('ВЗВ-000789', $fresh->erp_number);
        $this->assertEquals('approved', $fresh->status->value);
    }
}
