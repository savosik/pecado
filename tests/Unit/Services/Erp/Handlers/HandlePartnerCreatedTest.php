<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerCreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_activates_user_and_sets_erp_id(): void
    {
        $user = User::factory()->create([
            'email' => 'partner@example.com',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'login' => 'partner@example.com',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $user->erp_id);
    }

    #[Test]
    public function it_does_nothing_when_user_not_found(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'пользователь не найден');
            });

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'login' => 'nonexistent@example.com',
        ]);

        $this->assertDatabaseMissing('users', [
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid или login');
            });

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'login' => 'partner@example.com',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_login_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid или login');
            });

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
    }

    #[Test]
    public function it_overwrites_existing_erp_id(): void
    {
        $user = User::factory()->create([
            'email' => 'partner@example.com',
            'status' => UserStatus::BLOCKED,
            'erp_id' => 'old-uuid',
        ]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'new-uuid-1234',
            'login' => 'partner@example.com',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('new-uuid-1234', $user->erp_id);
    }
}
