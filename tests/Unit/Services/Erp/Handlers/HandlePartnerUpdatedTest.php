<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\ClientStatus;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePartnerUpdatedTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Сценарий 1: Поиск по erp_id (идемпотентность)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_updates_user_found_by_erp_id(): void
    {
        $user = User::factory()->create([
            'email' => 'partner@example.com',
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Old Name',
            'phone' => '+70001111111',
            'city' => 'Москва',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-001',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'login' => 'partner@example.com',
            'name' => 'Новое Имя',
            'phone' => '+79999999999',
            'city' => 'Екатеринбург',
        ]);

        $user->refresh();

        $this->assertEquals('Новое Имя', $user->name);
        $this->assertEquals('+79999999999', $user->phone);
        $this->assertEquals('Екатеринбург', $user->city);
    }

    #[Test]
    public function it_partially_updates_only_provided_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'partial@example.com',
            'erp_id' => 'uuid-partial',
            'name' => 'Original Name',
            'phone' => '+70001111111',
            'city' => 'Москва',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-partial',
            'uuid' => 'uuid-partial',
            'name' => 'Updated Name',
            // phone и city не переданы — не должны измениться
        ]);

        $user->refresh();

        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('+70001111111', $user->phone);
        $this->assertEquals('Москва', $user->city);
    }

    // ──────────────────────────────────────────────
    // Сценарий 2: Поиск по email → привязка erp_id
    // ──────────────────────────────────────────────

    #[Test]
    public function it_binds_erp_id_when_found_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'bindme@example.com',
            'erp_id' => null,
            'status' => UserStatus::PROCESSING,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-bind',
            'uuid' => 'new-erp-uuid-1234',
            'login' => 'bindme@example.com',
            'name' => 'Updated Name',
            'is_active' => true,
        ]);

        $user->refresh();

        $this->assertEquals('new-erp-uuid-1234', $user->erp_id);
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
    }

    #[Test]
    public function it_uses_email_field_for_lookup_when_login_absent(): void
    {
        $user = User::factory()->create([
            'email' => 'emailonly@example.com',
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-email',
            'uuid' => 'uuid-email-lookup',
            'email' => 'emailonly@example.com',
            'name' => 'Found By Email',
        ]);

        $user->refresh();

        $this->assertEquals('uuid-email-lookup', $user->erp_id);
        $this->assertEquals('Found By Email', $user->name);
    }

    // ──────────────────────────────────────────────
    // Пользователь не найден — не создаётся
    // ──────────────────────────────────────────────

    #[Test]
    public function it_does_not_create_user_when_not_found(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'пользователь не найден');
            });

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-notfound',
            'uuid' => 'nonexistent-uuid',
            'login' => 'noone@example.com',
            'name' => 'Ghost',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'noone@example.com',
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
                return str_contains($msg, 'отсутствует uuid');
            });

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-nouuid',
            'login' => 'test@example.com',
        ]);
    }

    // ──────────────────────────────────────────────
    // is_active → UserStatus
    // ──────────────────────────────────────────────

    #[Test]
    public function it_blocks_user_when_is_active_false(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked@example.com',
            'erp_id' => 'uuid-block-upd',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-block',
            'uuid' => 'uuid-block-upd',
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
            'erp_id' => 'uuid-reactivate-upd',
            'status' => UserStatus::BLOCKED,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-reactivate',
            'uuid' => 'uuid-reactivate-upd',
            'is_active' => true,
        ]);

        $user->refresh();
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
    }

    // ──────────────────────────────────────────────
    // client_status → ClientStatus
    // ──────────────────────────────────────────────

    #[Test]
    public function it_sets_client_status_when_valid(): void
    {
        $goldStatus = ClientStatus::factory()->create([
            'name' => 'Gold',
            'external_id' => 'gold',
        ]);

        $user = User::factory()->create([
            'email' => 'gold@example.com',
            'erp_id' => 'uuid-gold-upd',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-gold',
            'uuid' => 'uuid-gold-upd',
            'client_status' => 'gold',
        ]);

        $user->refresh();
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
            'erp_id' => 'uuid-reset-upd',
            'client_status_id' => $silverStatus->id,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-reset',
            'uuid' => 'uuid-reset-upd',
            'client_status' => null,
        ]);

        $user->refresh();
        $this->assertNull($user->client_status_id);
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
            'erp_id' => 'uuid-nochange-upd',
            'client_status_id' => $silverStatus->id,
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-nochange',
            'uuid' => 'uuid-nochange-upd',
            'name' => 'Updated Name',
            // client_status отсутствует — не менять
        ]);

        $user->refresh();
        $this->assertEquals($silverStatus->id, $user->client_status_id);
    }

    #[Test]
    public function it_logs_warning_for_unknown_client_status(): void
    {
        $user = User::factory()->create([
            'email' => 'unknown@example.com',
            'erp_id' => 'uuid-unknown-upd',
            'client_status_id' => null,
        ]);

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function ($msg, $context = []) {
                if (str_contains($msg, 'неизвестный client_status')) {
                    return $context['client_status'] === 'platinum';
                }

                return true;
            });

        Log::shouldReceive('info')->andReturnNull();

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-unknown',
            'uuid' => 'uuid-unknown-upd',
            'client_status' => 'platinum',
        ]);

        $user->refresh();
        $this->assertNull($user->client_status_id);
    }

    // ──────────────────────────────────────────────
    // Предотвращение петель (event loop)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_does_not_dispatch_user_events(): void
    {
        Event::fake([UserUpdated::class, UserCreated::class]);

        $user = User::factory()->create([
            'email' => 'noevent@example.com',
            'erp_id' => 'uuid-noevent-upd',
        ]);

        Event::fake([UserUpdated::class, UserCreated::class]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-noevent',
            'uuid' => 'uuid-noevent-upd',
            'name' => 'Silent Update',
        ]);

        Event::assertNotDispatched(UserUpdated::class);
        Event::assertNotDispatched(UserCreated::class);
    }
}
