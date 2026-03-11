<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_user_by_erp_id(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $handler = new HandlePartnerDeleted();
        $handler->handle([
            'event' => 'partner.deleted',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::BLOCKED, $user->status);
    }

    #[Test]
    public function it_does_nothing_when_user_not_found(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'пользователь не найден');
            });

        $handler = new HandlePartnerDeleted();
        $handler->handle([
            'event' => 'partner.deleted',
            'uuid' => 'nonexistent-uuid',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid');
            });

        $handler = new HandlePartnerDeleted();
        $handler->handle([
            'event' => 'partner.deleted',
        ]);
    }

    #[Test]
    public function it_blocks_user_regardless_of_current_status(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::PROCESSING,
            'erp_id' => 'test-uuid-123',
        ]);

        $handler = new HandlePartnerDeleted();
        $handler->handle([
            'event' => 'partner.deleted',
            'uuid' => 'test-uuid-123',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::BLOCKED, $user->status);
    }
}
