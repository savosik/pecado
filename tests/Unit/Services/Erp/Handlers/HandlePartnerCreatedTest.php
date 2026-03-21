<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerCreatedTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Сценарий 1: Пользователь существует (активация)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_activates_user_and_sets_erp_id(): void
    {
        $user = User::factory()->create([
            'email'  => 'partner@example.com',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid'  => '550e8400-e29b-41d4-a716-446655440000',
            'login' => 'partner@example.com',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $user->erp_id);
    }

    #[Test]
    public function it_overwrites_existing_erp_id(): void
    {
        $user = User::factory()->create([
            'email'  => 'partner@example.com',
            'status' => UserStatus::BLOCKED,
            'erp_id' => 'old-uuid',
        ]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid'  => 'new-uuid-1234',
            'login' => 'partner@example.com',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('new-uuid-1234', $user->erp_id);
    }

    // ──────────────────────────────────────────────
    // Сценарий 2 (v4): Создание пользователя из 1С
    // ──────────────────────────────────────────────

    #[Test]
    public function it_creates_new_user_when_not_found_and_password_present(): void
    {
        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event'    => 'partner.created',
            'uuid'     => 'erp-uuid-new-partner',
            'login'    => 'newuser@example.com',
            'email'    => 'newuser@example.com',
            'name'     => 'Иванов Иван',
            'phone'    => '+77001234567',
            'password' => 'temppass123',
        ]);

        $this->assertDatabaseHas('users', [
            'email'  => 'newuser@example.com',
            'erp_id' => 'erp-uuid-new-partner',
            'name'   => 'Иванов Иван',
            'phone'  => '+77001234567',
            'must_change_password' => true,
        ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertTrue($user->must_change_password);
        // Пароль должен быть хешированным (не plaintext)
        $this->assertNotEquals('temppass123', $user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('temppass123', $user->password));
    }

    #[Test]
    public function it_sets_must_change_password_true_for_erp_created_user(): void
    {
        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event'    => 'partner.created',
            'uuid'     => 'erp-uuid-pass-check',
            'login'    => 'passcheck@example.com',
            'password' => 'abc123',
        ]);

        $user = User::where('email', 'passcheck@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->must_change_password);
    }

    #[Test]
    public function it_does_not_create_user_when_not_found_and_no_password(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'нет пароля для создания');
            });

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid'  => 'erp-uuid-no-pass',
            'login' => 'nopass@example.com',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'nopass@example.com',
        ]);
    }

    // ──────────────────────────────────────────────
    // Валидация payload
    // ──────────────────────────────────────────────

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
            'uuid'  => '550e8400-e29b-41d4-a716-446655440000',
        ]);
    }

    // ──────────────────────────────────────────────
    // Предотвращение петель (event loop)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_does_not_dispatch_user_events_on_activation(): void
    {
        Event::fake([UserUpdated::class, UserCreated::class]);

        $user = User::factory()->create([
            'email'  => 'noevent@example.com',
            'status' => UserStatus::PROCESSING,
        ]);

        // Event::fake сбрасывает счётчик — нужно отслеживать с этого момента
        Event::fake([UserUpdated::class, UserCreated::class]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event' => 'partner.created',
            'uuid'  => 'no-loop-uuid',
            'login' => 'noevent@example.com',
        ]);

        Event::assertNotDispatched(UserUpdated::class);
        Event::assertNotDispatched(UserCreated::class);
    }

    #[Test]
    public function it_does_not_dispatch_user_events_on_creation(): void
    {
        Event::fake([UserUpdated::class, UserCreated::class]);

        $handler = new HandlePartnerCreated();
        $handler->handle([
            'event'    => 'partner.created',
            'uuid'     => 'no-loop-create-uuid',
            'login'    => 'nocreate@example.com',
            'password' => 'temp123',
        ]);

        Event::assertNotDispatched(UserUpdated::class);
        Event::assertNotDispatched(UserCreated::class);
    }
}
