<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\ClientStatus;
use App\Models\PersonalManager;
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

        // Наименование из 1С — в рабочее поле; личное имя клиента не трогаем.
        $this->assertEquals('Новое Имя', $user->erp_name);
        $this->assertEquals('Old Name', $user->name);
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

        $this->assertEquals('Updated Name', $user->erp_name);
        $this->assertEquals('Original Name', $user->name);
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
        $this->assertEquals('Updated Name', $user->erp_name);
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
        $this->assertEquals('Found By Email', $user->erp_name);
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
    // manager → personal_manager_id (v15.1)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_assigns_existing_personal_manager_by_erp_uuid(): void
    {
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'mgr-uuid-0001',
            'name' => 'Иванов Иван',
        ]);

        $user = User::factory()->create([
            'erp_id' => 'user-uuid-mgr-01',
            'personal_manager_id' => null,
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-mgr-assign',
            'uuid' => 'user-uuid-mgr-01',
            'manager' => ['uuid' => 'mgr-uuid-0001', 'name' => 'Иванов Иван'],
        ]);

        $this->assertEquals($manager->id, $user->refresh()->personal_manager_id);
    }

    #[Test]
    public function it_creates_personal_manager_if_not_exists(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'user-uuid-mgr-02',
            'personal_manager_id' => null,
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-mgr-create',
            'uuid' => 'user-uuid-mgr-02',
            'manager' => ['uuid' => 'mgr-uuid-new', 'name' => 'Петров Пётр'],
        ]);

        $user->refresh();
        $manager = PersonalManager::where('erp_uuid', 'mgr-uuid-new')->first();

        $this->assertNotNull($manager);
        $this->assertEquals('Петров Пётр', $manager->name);
        $this->assertEquals($manager->id, $user->personal_manager_id);
    }

    #[Test]
    public function it_resets_personal_manager_when_manager_is_null(): void
    {
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'mgr-uuid-reset',
        ]);

        $user = User::factory()->create([
            'erp_id' => 'user-uuid-mgr-03',
            'personal_manager_id' => $manager->id,
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-mgr-reset',
            'uuid' => 'user-uuid-mgr-03',
            'manager' => null,
        ]);

        $this->assertNull($user->refresh()->personal_manager_id);
    }

    #[Test]
    public function it_does_not_change_manager_when_key_absent(): void
    {
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'mgr-uuid-keep',
        ]);

        $user = User::factory()->create([
            'erp_id' => 'user-uuid-mgr-04',
            'personal_manager_id' => $manager->id,
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-mgr-absent',
            'uuid' => 'user-uuid-mgr-04',
            'name' => 'Изменилось только имя',
            // manager отсутствует — не менять
        ]);

        $this->assertEquals($manager->id, $user->refresh()->personal_manager_id);
    }

    #[Test]
    public function it_updates_manager_name_if_changed(): void
    {
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'mgr-uuid-rename',
            'name' => 'Старое Имя',
        ]);

        $user = User::factory()->create([
            'erp_id' => 'user-uuid-mgr-05',
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-mgr-rename',
            'uuid' => 'user-uuid-mgr-05',
            'manager' => ['uuid' => 'mgr-uuid-rename', 'name' => 'Новое Имя'],
        ]);

        $this->assertEquals('Новое Имя', $manager->refresh()->name);
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

    // ──────────────────────────────────────────────
    // Граница владения данными: тип аккаунта — поле сайта
    // ──────────────────────────────────────────────

    #[Test]
    public function it_keeps_user_kind_untouched(): void
    {
        // Тип аккаунта размечает администратор сайта, в payload 1С его нет.
        // Если обработчик когда-нибудь начнёт писать в users всё подряд,
        // помеченный закупщик снова станет клиентом и всплывёт в CRM.
        $user = User::factory()->staff()->create([
            'email' => 'buyer@example.com',
            'erp_id' => 'uuid-staff-kind',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-kind',
            'uuid' => 'uuid-staff-kind',
            'name' => 'Закупщик Иванов',
            'city' => 'Тюмень',
        ]);

        $user->refresh();

        $this->assertSame(UserKind::STAFF, $user->user_kind);
        $this->assertEquals('Закупщик Иванов', $user->erp_name);
    }

    // ──────────────────────────────────────────────
    // Рабочее наименование и личное имя (v15.10)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_keeps_name_edited_by_client_in_cabinet(): void
    {
        // Ровно тот случай, ради которого поле заведено: клиент переименовал
        // себя в кабинете, а 1С продолжает слать наименование карточки.
        $user = User::factory()->create([
            'email' => 'renamed@example.com',
            'erp_id' => 'uuid-renamed',
            'name' => 'Как я себя назвал',
            'erp_name' => 'ООО «Ромашка» (Иванов)',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-rename',
            'uuid' => 'uuid-renamed',
            'name' => 'ООО «Ромашка» (Иванов И.И.)',
        ]);

        $user->refresh();

        $this->assertEquals('Как я себя назвал', $user->name);
        $this->assertEquals('ООО «Ромашка» (Иванов И.И.)', $user->erp_name);
    }

    #[Test]
    public function it_keeps_erp_name_when_payload_has_no_name(): void
    {
        $user = User::factory()->create([
            'email' => 'noname@example.com',
            'erp_id' => 'uuid-no-name',
            'name' => 'Личное имя',
            'erp_name' => 'Рабочее наименование',
        ]);

        $handler = new HandlePartnerUpdated;
        $handler->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-upd-no-name',
            'uuid' => 'uuid-no-name',
            'city' => 'Тюмень',
        ]);

        $user->refresh();

        $this->assertEquals('Рабочее наименование', $user->erp_name);
        $this->assertEquals('Личное имя', $user->name);
        $this->assertEquals('Тюмень', $user->city);
    }
}
