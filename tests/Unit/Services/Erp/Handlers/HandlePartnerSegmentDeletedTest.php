<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\PartnerSegment;
use App\Services\Erp\Handlers\HandlePartnerSegmentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerSegmentDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deletes_existing_segment(): void
    {
        $segment = PartnerSegment::create([
            'uuid' => 'seg-part-del-001',
            'name' => 'Сегмент для удаления',
        ]);

        $handler = app(HandlePartnerSegmentDeleted::class);
        $handler->handle([
            'event' => 'partner_segment.deleted',
            'uuid'  => 'seg-part-del-001',
        ]);

        $this->assertDatabaseMissing('partner_segments', ['uuid' => 'seg-part-del-001']);
    }

    #[Test]
    public function does_not_fail_if_segment_not_found(): void
    {
        $handler = app(HandlePartnerSegmentDeleted::class);

        // Не должно выбросить исключение
        $handler->handle([
            'event' => 'partner_segment.deleted',
            'uuid'  => 'non-existent-uuid',
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function missing_uuid_does_not_crash(): void
    {
        $handler = app(HandlePartnerSegmentDeleted::class);

        $handler->handle([
            'event' => 'partner_segment.deleted',
        ]);

        $this->assertTrue(true);
    }
}
