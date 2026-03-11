<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\PartnerSegment;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerSegmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerSegmentCreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_segment_with_partners(): void
    {
        $user1 = User::factory()->create(['erp_id' => 'partner-uuid-001']);
        $user2 = User::factory()->create(['erp_id' => 'partner-uuid-002']);

        $handler = app(HandlePartnerSegmentCreated::class);
        $handler->handle([
            'event'         => 'partner_segment.created',
            'uuid'          => 'seg-part-001',
            'name'          => 'Уровень Голд',
            'partner_uuids' => ['partner-uuid-001', 'partner-uuid-002'],
        ]);

        $this->assertDatabaseHas('partner_segments', [
            'uuid' => 'seg-part-001',
            'name' => 'Уровень Голд',
        ]);

        $segment = PartnerSegment::where('uuid', 'seg-part-001')->first();
        $this->assertCount(2, $segment->users);
        $this->assertTrue($segment->users->contains($user1));
        $this->assertTrue($segment->users->contains($user2));
    }

    #[Test]
    public function idempotent_updates_existing_segment(): void
    {
        PartnerSegment::create([
            'uuid' => 'seg-part-002',
            'name' => 'Старое название',
        ]);

        $handler = app(HandlePartnerSegmentCreated::class);
        $handler->handle([
            'event'         => 'partner_segment.created',
            'uuid'          => 'seg-part-002',
            'name'          => 'Новое название',
            'partner_uuids' => [],
        ]);

        $this->assertDatabaseHas('partner_segments', [
            'uuid' => 'seg-part-002',
            'name' => 'Новое название',
        ]);

        $this->assertEquals(1, PartnerSegment::where('uuid', 'seg-part-002')->count());
    }

    #[Test]
    public function ignores_unknown_partner_uuids(): void
    {
        $user = User::factory()->create(['erp_id' => 'known-partner-uuid']);

        $handler = app(HandlePartnerSegmentCreated::class);
        $handler->handle([
            'event'         => 'partner_segment.created',
            'uuid'          => 'seg-part-003',
            'name'          => 'Тестовый сегмент',
            'partner_uuids' => ['known-partner-uuid', 'unknown-partner-uuid'],
        ]);

        $segment = PartnerSegment::where('uuid', 'seg-part-003')->first();
        $this->assertNotNull($segment);
        $this->assertCount(1, $segment->users);
        $this->assertTrue($segment->users->contains($user));
    }

    #[Test]
    public function missing_uuid_does_not_create_segment(): void
    {
        $handler = app(HandlePartnerSegmentCreated::class);
        $handler->handle([
            'event'         => 'partner_segment.created',
            'name'          => 'Без UUID',
            'partner_uuids' => [],
        ]);

        $this->assertDatabaseCount('partner_segments', 0);
    }

    #[Test]
    public function missing_name_does_not_create_segment(): void
    {
        $handler = app(HandlePartnerSegmentCreated::class);
        $handler->handle([
            'event'         => 'partner_segment.created',
            'uuid'          => 'seg-part-005',
            'partner_uuids' => [],
        ]);

        $this->assertDatabaseCount('partner_segments', 0);
    }

    #[Test]
    public function syncs_partners_on_repeated_call(): void
    {
        $user1 = User::factory()->create(['erp_id' => 'partner-sync-001']);
        $user2 = User::factory()->create(['erp_id' => 'partner-sync-002']);

        $handler = app(HandlePartnerSegmentCreated::class);

        // Первый вызов: только user1
        $handler->handle([
            'uuid'          => 'seg-part-006',
            'name'          => 'Тест синхронизации',
            'partner_uuids' => ['partner-sync-001'],
        ]);

        $segment = PartnerSegment::where('uuid', 'seg-part-006')->first();
        $this->assertCount(1, $segment->users);

        // Второй вызов: только user2
        $handler->handle([
            'uuid'          => 'seg-part-006',
            'name'          => 'Тест синхронизации',
            'partner_uuids' => ['partner-sync-002'],
        ]);

        $segment->refresh();
        $this->assertCount(1, $segment->users);
        $this->assertTrue($segment->users->contains($user2));
        $this->assertFalse($segment->users->contains($user1));
    }
}
