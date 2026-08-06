<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Models\ClientStatus;
use App\Models\PersonalManager;
use App\Models\Region;
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
    public function it_normalizes_email_to_lowercase_so_temporary_password_matches(): void
    {
        // 1С генерирует временный пароль как crc32 от email в НИЖНЕМ регистре
        $password = sprintf('%u', crc32('mixed@case.ru'));

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'erp-uuid-email-case',
            'email' => 'Mixed@Case.RU',
            'password' => $password,
        ]);

        $user = User::where('erp_id', 'erp-uuid-email-case')->first();
        $this->assertNotNull($user);
        // E-mail сохранён в нижнем регистре
        $this->assertSame('mixed@case.ru', $user->email);
        // Отображаемый в админке временный пароль реально работает как пароль
        $this->assertSame($password, $user->temporary_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($user->temporary_password, $user->password));
    }

    #[Test]
    public function it_finds_existing_user_by_login_case_insensitively(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@partner.ru',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'erp-uuid-login-case',
            'email' => 'Existing@Partner.RU',
        ]);

        $user->refresh();

        // Не создан дубль, а привязан erp_id к существующему
        $this->assertSame(1, User::where('email', 'existing@partner.ru')->count());
        $this->assertSame('erp-uuid-login-case', $user->erp_id);
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
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

    // ──────────────────────────────────────────────
    // v15: manager — резолвинг менеджера
    // ──────────────────────────────────────────────

    #[Test]
    public function it_creates_and_assigns_personal_manager_when_new(): void
    {
        $user = User::factory()->create([
            'email' => 'manager-test@example.com',
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-with-manager-001',
            'email' => 'manager-test@example.com',
            'manager' => [
                'uuid' => 'erp-manager-uuid-001',
                'name' => 'Иванов Иван Иванович',
            ],
        ]);

        $user->refresh();
        $this->assertNotNull($user->personal_manager_id);

        $manager = PersonalManager::find($user->personal_manager_id);
        $this->assertNotNull($manager);
        $this->assertEquals('erp-manager-uuid-001', $manager->erp_uuid);
        $this->assertEquals('Иванов Иван Иванович', $manager->name);
    }

    #[Test]
    public function it_reuses_existing_personal_manager_by_erp_uuid(): void
    {
        $existingManager = PersonalManager::create([
            'erp_uuid' => 'erp-manager-uuid-reuse',
            'name' => 'Петров Пётр Петрович',
        ]);

        $user = User::factory()->create([
            'email' => 'manager-reuse@example.com',
            'erp_id' => null,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-manager-reuse',
            'email' => 'manager-reuse@example.com',
            'manager' => [
                'uuid' => 'erp-manager-uuid-reuse',
                'name' => 'Петров Пётр Петрович',
            ],
        ]);

        $user->refresh();
        $this->assertEquals($existingManager->id, $user->personal_manager_id);
        $this->assertEquals(1, PersonalManager::where('erp_uuid', 'erp-manager-uuid-reuse')->count());
    }

    #[Test]
    public function it_resets_personal_manager_when_null(): void
    {
        $manager = PersonalManager::create([
            'erp_uuid' => 'erp-manager-reset',
            'name' => 'Сидоров Сидор',
        ]);

        $user = User::factory()->create([
            'email' => 'manager-reset@example.com',
            'erp_id' => 'uuid-manager-reset-001',
            'personal_manager_id' => $manager->id,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-manager-reset-001',
            'email' => 'manager-reset@example.com',
            'manager' => null,
        ]);

        $user->refresh();
        $this->assertNull($user->personal_manager_id);
    }

    #[Test]
    public function it_does_not_change_manager_when_key_absent(): void
    {
        $manager = PersonalManager::create([
            'erp_uuid' => 'erp-manager-absent',
            'name' => 'Козлов Козёл',
        ]);

        $user = User::factory()->create([
            'email' => 'manager-absent@example.com',
            'erp_id' => 'uuid-manager-absent-001',
            'personal_manager_id' => $manager->id,
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-manager-absent-001',
            'email' => 'manager-absent@example.com',
            // ключ manager отсутствует — менеджер не должен измениться
        ]);

        $user->refresh();
        $this->assertEquals($manager->id, $user->personal_manager_id);
    }

    #[Test]
    public function it_assigns_manager_to_new_user_created_from_erp(): void
    {
        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-new-user-with-manager',
            'email' => 'new-user-manager@example.com',
            'password' => 'pass12345',
            'manager' => [
                'uuid' => 'erp-manager-new-user',
                'name' => 'Новый Менеджер',
            ],
        ]);

        $user = User::where('email', 'new-user-manager@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->personal_manager_id);

        $manager = PersonalManager::find($user->personal_manager_id);
        $this->assertEquals('erp-manager-new-user', $manager->erp_uuid);
    }

    // Дефолтный регион при создании из 1С
    // ──────────────────────────────────────────────

    #[Test]
    public function it_assigns_min_region_to_new_user_created_from_erp(): void
    {
        $firstRegion = Region::factory()->create();
        Region::factory()->create();
        Region::factory()->create();

        $minId = Region::min('id');
        $this->assertEquals($firstRegion->id, $minId);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-region-default',
            'email' => 'regiontest@example.com',
            'password' => 'temp123',
        ]);

        $user = User::where('email', 'regiontest@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($minId, $user->region_id);
    }

    // ──────────────────────────────────────────────
    // Рабочее наименование и личное имя (v15.10)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_fills_both_names_for_new_user(): void
    {
        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-new-names',
            'email' => 'newnames@example.com',
            'name' => 'ООО «Ромашка» (Иванов)',
            'password' => 'temp123',
        ]);

        $user = User::where('email', 'newnames@example.com')->first();

        // Клиента у нас ещё не было — стартовое имя совпадает с наименованием.
        $this->assertNotNull($user);
        $this->assertEquals('ООО «Ромашка» (Иванов)', $user->name);
        $this->assertEquals('ООО «Ромашка» (Иванов)', $user->erp_name);
    }

    #[Test]
    public function it_keeps_personal_name_of_existing_user_found_by_erp_id(): void
    {
        $user = User::factory()->create([
            'email' => 'existing-erp@example.com',
            'erp_id' => 'uuid-existing-erp',
            'name' => 'Как я себя назвал',
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-existing-erp',
            'email' => 'existing-erp@example.com',
            'name' => 'ООО «Ромашка» (Иванов)',
        ]);

        $user->refresh();

        $this->assertEquals('Как я себя назвал', $user->name);
        $this->assertEquals('ООО «Ромашка» (Иванов)', $user->erp_name);
    }

    #[Test]
    public function it_fills_erp_name_when_binding_by_email(): void
    {
        // Клиент зарегистрировался на сайте сам, 1С завела карточку под своим
        // наименованием — имя из кабинета должно остаться за клиентом.
        $user = User::factory()->create([
            'email' => 'selfsignup@example.com',
            'erp_id' => null,
            'name' => 'Иван',
        ]);

        $handler = new HandlePartnerCreated;
        $handler->handle([
            'event' => 'partner.created',
            'uuid' => 'uuid-bind-name',
            'email' => 'selfsignup@example.com',
            'name' => 'ИП Иванов Иван Иванович',
        ]);

        $user->refresh();

        $this->assertEquals('uuid-bind-name', $user->erp_id);
        $this->assertEquals('Иван', $user->name);
        $this->assertEquals('ИП Иванов Иван Иванович', $user->erp_name);
    }
}
