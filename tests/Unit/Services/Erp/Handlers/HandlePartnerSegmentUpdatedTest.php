<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\PartnerSegment;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerSegmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerSegmentUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function updates_existing_segment_name_and_partners(): void
    {
        $segment = PartnerSegment::create([
            'uuid' => 'seg-part-upd-001',
            'name' => 'Старое название',
        ]);

        $user = User::factory()->create(['erp_id' => 'partner-upd-001']);

        $handler = app(HandlePartnerSegmentUpdated::class);
        $handler->handle([
            'event'         => 'partner_segment.updated',
            'uuid'          => 'seg-part-upd-001',
            'name'          => 'Новое название',
            'partner_uuids' => ['partner-upd-001'],
        ]);

        $segment->refresh();
        $this->assertEquals('Новое название', $segment->name);
        $this->assertCount(1, $segment->users);
        $this->assertTrue($segment->users->contains($user));
    }

    #[Test]
    public function creates_segment_if_not_exists(): void
    {
        $handler = app(HandlePartnerSegmentUpdated::class);
        $handler->handle([
            'event'         => 'partner_segment.updated',
            'uuid'          => 'seg-part-upd-002',
            'name'          => 'Новый сегмент через updated',
            'partner_uuids' => [],
        ]);

        $this->assertDatabaseHas('partner_segments', [
            'uuid' => 'seg-part-upd-002',
            'name' => 'Новый сегмент через updated',
        ]);
    }
}
