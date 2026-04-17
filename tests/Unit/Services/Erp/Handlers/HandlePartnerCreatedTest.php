<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\ClientStatus;
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
            'email' => 'partner@example.com',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'email' => 'partner@example.com',
        ]);

        $user->refresh();

        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $user->erp_id);
    }

    #[Test]
    public function it_overwrites_existing_erp_id(): void
    {
        $user = User::factory()->create([
            'email' => 'partner@example.com',
            'status' => UserStatus::BLOCKED,
            'erp_id' => 'old-uuid',
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'new-uuid-1234',
            'email' => 'partner@example.com',
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
        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'erp-uuid-new-partner',
            'email' => 'newuser@example.com',
            'name' => 'Иванов Иван',
            'phone' => '+77001234567',
            'password' => 'temppass123',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'erp_id' => 'erp-uuid-new-partner',
            'name' => 'Иванов Иван',
            'phone' => '+77001234567',
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
        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'erp-uuid-pass-check',
            'email' => 'passcheck@example.com',
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

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'erp-uuid-no-pass',
            'email' => 'nopass@example.com',
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
                return str_contains($msg, 'отсутствует uuid или email');
            });

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'email' => 'partner@example.com',
        ]);
    }

    #[Test]
    public function it_does_nothing_when_email_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует uuid или email');
            });

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
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
            'email' => 'noevent@example.com',
            'status' => UserStatus::PROCESSING,
        ]);

        // Event::fake сбрасывает счётчик — нужно отслеживать с этого момента
        Event::fake([UserUpdated::class, UserCreated::class]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'no-loop-uuid',
            'email' => 'noevent@example.com',
        ]);

        Event::assertNotDispatched(UserUpdated::class);
        Event::assertNotDispatched(UserCreated::class);
    }

    #[Test]
    public function it_does_not_dispatch_user_events_on_creation(): void
    {
        Event::fake([UserUpdated::class, UserCreated::class]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'no-loop-create-uuid',
            'email' => 'nocreate@example.com',
            'password' => 'temp123',
        ]);

        Event::assertNotDispatched(UserUpdated::class);
        Event::assertNotDispatched(UserCreated::class);
    }

    // ──────────────────────────────────────────────
    // v11: client_status — резолвинг статуса клиента
    // ──────────────────────────────────────────────

    #[Test]
    public function it_sets_client_status_when_valid_client_status_provided(): void
    {
        $goldStatus = ClientStatus::factory()->create([
            'name' => 'Gold',
            'external_id' => 'gold',
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-with-status',
            'email' => 'golduser@example.com',
            'password' => 'temp123',
            'client_status' => 'gold',
        ]);

        $user = User::where('email', 'golduser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($goldStatus->id, $user->client_status_id);
    }

    #[Test]
    public function it_resets_client_status_when_null(): void
    {
        $silverStatus = ClientStatus::factory()->create([
            'name' => 'Silver',
            'external_id' => 'silver',
        ]);

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'erp_id' => 'uuid-reset-status',
            'client_status_id' => $silverStatus->id,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-reset-status',
            'email' => 'reset@example.com',
            'client_status' => null,
        ]);

        $user->refresh();
        $this->assertNull($user->client_status_id);
    }

    #[Test]
    public function it_logs_warning_for_unknown_client_status(): void
    {
        $user = User::factory()->create([
            'email' => 'unknown@example.com',
            'erp_id' => 'uuid-unknown-status',
            'client_status_id' => null,
        ]);

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($msg, $context = []) {
                // Нас интересует конкретный вызов — неизвестный client_status
                if (str_contains($msg, 'неизвестный client_status')) {
                    return $context['client_status'] === 'platinum';
                }

                // Остальные warning (например, от NormalizeUserDataJob) — пропускаем
                return true;
            });

        Log::shouldReceive('info')->andReturnNull();

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-unknown-status',
            'email' => 'unknown@example.com',
            'client_status' => 'platinum',
        ]);

        $user->refresh();
        $this->assertNull($user->client_status_id); // не изменился
    }

    #[Test]
    public function it_updates_client_status_on_redelivery(): void
    {
        $silverStatus = ClientStatus::factory()->create([
            'name' => 'Silver',
            'external_id' => 'silver',
        ]);

        $goldStatus = ClientStatus::factory()->create([
            'name' => 'Gold',
            'external_id' => 'gold',
        ]);

        $user = User::factory()->create([
            'email' => 'upgrade@example.com',
            'erp_id' => 'uuid-upgrade',
            'client_status_id' => $silverStatus->id,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-upgrade',
            'email' => 'upgrade@example.com',
            'client_status' => 'gold',
        ]);

        $user->refresh();
        $this->assertEquals($goldStatus->id, $user->client_status_id);
    }

    #[Test]
    public function it_does_not_change_status_when_client_status_absent(): void
    {
        $silverStatus = ClientStatus::factory()->create([
            'name' => 'Silver',
            'external_id' => 'silver',
        ]);

        $user = User::factory()->create([
            'email' => 'nochange@example.com',
            'erp_id' => 'uuid-nochange',
            'client_status_id' => $silverStatus->id,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-nochange',
            'email' => 'nochange@example.com',
            // client_status отсутствует — не менять
        ]);

        $user->refresh();
        $this->assertEquals($silverStatus->id, $user->client_status_id);
    }

    // ──────────────────────────────────────────────
    // v11: is_active — блокировка/активация
    // ──────────────────────────────────────────────

    #[Test]
    public function it_blocks_user_when_is_active_false(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked@example.com',
            'erp_id' => 'uuid-block',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-block',
            'email' => 'blocked@example.com',
            'is_active' => false,
        ]);

        $user->refresh();
        $this->assertEquals(UserStatus::BLOCKED, $user->status);
    }

    #[Test]
    public function it_activates_user_when_is_active_true(): void
    {
        $user = User::factory()->create([
            'email' => 'reactivate@example.com',
            'erp_id' => 'uuid-reactivate',
            'status' => UserStatus::BLOCKED,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-reactivate',
            'email' => 'reactivate@example.com',
            'is_active' => true,
        ]);

        $user->refresh();
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
    }

    #[Test]
    public function it_creates_blocked_user_when_is_active_false(): void
    {
        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-create-blocked',
            'email' => 'newblocked@example.com',
            'password' => 'temp123',
            'is_active' => false,
        ]);

        $user = User::where('email', 'newblocked@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserStatus::BLOCKED, $user->status);
    }
}
