<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Models\Company;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleContractorCreatedTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Создание компании
    // ──────────────────────────────────────────────

    #[Test]
    public function it_creates_company_linked_to_partner(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-123',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-456',
            'partner_uuid' => 'partner-uuid-123',
            'name' => 'ООО «Тест»',
            'legal_name' => 'Общество с ограниченной ответственностью «Тест»',
            'tax_id' => '7701234567',
            'registration_number' => '770101001',
            'tax_code' => '770101001',
            'okpo_code' => '12345678',
            'legal_address' => 'г. Москва, ул. Пушкина, д. 10',
            'actual_address' => 'г. Москва, ул. Лермонтова, д. 5',
            'phone' => '+74951234567',
            'email' => 'info@test.ru',
            'bank_accounts' => [
                [
                    'bank_name' => 'АО «Сбербанк»',
                    'bank_bik' => '044525225',
                    'correspondent_account' => '30101810400000000225',
                    'account_number' => '40702810938000012345',
                    'is_primary' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('companies', [
            'erp_id' => 'contractor-uuid-456',
            'user_id' => $user->id,
            'name' => 'ООО «Тест»',
            'legal_name' => 'Общество с ограниченной ответственностью «Тест»',
            'tax_id' => '7701234567',
            'registration_number' => '770101001',
            'tax_code' => '770101001',
            'okpo_code' => '12345678',
            'legal_address' => 'г. Москва, ул. Пушкина, д. 10',
            'actual_address' => 'г. Москва, ул. Лермонтова, д. 5',
            'phone' => '+74951234567',
            'email' => 'info@test.ru',
        ]);

        $company = Company::withoutGlobalScopes()->where('erp_id', 'contractor-uuid-456')->first();
        $this->assertCount(1, $company->bankAccounts);
        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'АО «Сбербанк»',
            'bank_bik' => '044525225',
            'account_number' => '40702810938000012345',
            'is_primary' => true,
        ]);
    }

    // ──────────────────────────────────────────────
    // Идемпотентность (updateOrCreate)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_updates_existing_company_instead_of_duplicating(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-789',
            'status' => UserStatus::ACTIVE,
        ]);

        // Создаём компанию вручную
        Company::withoutGlobalScopes()->create([
            'erp_id' => 'contractor-uuid-existing',
            'user_id' => $user->id,
            'name' => 'Старое название',
            'tax_id' => '1234567890',
            'country' => 'BY',
        ]);

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-existing',
            'partner_uuid' => 'partner-uuid-789',
            'name' => 'Новое название',
            'tax_id' => '0987654321',
        ]);

        // Должна быть одна компания, а не две
        $this->assertDatabaseCount('companies', 1);

        $this->assertDatabaseHas('companies', [
            'erp_id' => 'contractor-uuid-existing',
            'name' => 'Новое название',
            'tax_id' => '0987654321',
        ]);
    }

    // ──────────────────────────────────────────────
    // Обработка ошибок
    // ──────────────────────────────────────────────

    #[Test]
    public function it_logs_warning_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'отсутствует uuid'));

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'partner_uuid' => 'some-partner',
        ]);
    }

    #[Test]
    public function it_logs_warning_when_partner_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'отсутствует partner_uuid'));

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-no-partner',
        ]);

        $this->assertDatabaseMissing('companies', [
            'erp_id' => 'contractor-uuid-no-partner',
        ]);
    }

    #[Test]
    public function it_logs_warning_when_partner_not_found(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'партнёр не найден'));

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-orphan',
            'partner_uuid' => 'nonexistent-partner-uuid',
            'name' => 'Компания без партнёра',
        ]);

        $this->assertDatabaseMissing('companies', [
            'erp_id' => 'contractor-uuid-orphan',
        ]);
    }

    // ──────────────────────────────────────────────
    // Предотвращение петель (event loop)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_does_not_dispatch_company_events(): void
    {
        Event::fake([CompanyCreated::class, CompanyUpdated::class]);

        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-event-test',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-event-test',
            'partner_uuid' => 'partner-uuid-event-test',
            'name' => 'Компания для теста событий',
        ]);

        Event::assertNotDispatched(CompanyCreated::class);
        Event::assertNotDispatched(CompanyUpdated::class);
    }

    // ──────────────────────────────────────────────
    // v5: Банковские счета
    // ──────────────────────────────────────────────

    #[Test]
    public function it_replaces_bank_accounts_on_update(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-bank-replace',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandleContractorCreated;

        // Первый вызов — создаём с одним счётом
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-bank-replace',
            'partner_uuid' => 'partner-uuid-bank-replace',
            'name' => 'Компания',
            'bank_accounts' => [
                ['bank_name' => 'Банк 1', 'account_number' => '111', 'is_primary' => true],
            ],
        ]);

        $this->assertDatabaseCount('company_bank_accounts', 1);

        // Второй вызов — заменяем на два новых счёта
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-bank-replace',
            'partner_uuid' => 'partner-uuid-bank-replace',
            'name' => 'Компания',
            'bank_accounts' => [
                ['bank_name' => 'Банк 2', 'account_number' => '222', 'is_primary' => true],
                ['bank_name' => 'Банк 3', 'account_number' => '333', 'is_primary' => false],
            ],
        ]);

        $this->assertDatabaseCount('company_bank_accounts', 2);
        $this->assertDatabaseMissing('company_bank_accounts', ['bank_name' => 'Банк 1']);
        $this->assertDatabaseHas('company_bank_accounts', ['bank_name' => 'Банк 2']);
        $this->assertDatabaseHas('company_bank_accounts', ['bank_name' => 'Банк 3']);
    }

    #[Test]
    public function it_handles_null_bank_accounts_without_deleting_existing(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-bank-null',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandleContractorCreated;

        // Создаём с банковскими счетами
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-bank-null',
            'partner_uuid' => 'partner-uuid-bank-null',
            'name' => 'Компания',
            'bank_accounts' => [
                ['bank_name' => 'Банк', 'account_number' => '444', 'is_primary' => true],
            ],
        ]);

        $this->assertDatabaseCount('company_bank_accounts', 1);

        // Повторный вызов без bank_accounts — счета не трогаем
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-bank-null',
            'partner_uuid' => 'partner-uuid-bank-null',
            'name' => 'Компания обновлённая',
            'bank_accounts' => null,
        ]);

        $this->assertDatabaseCount('company_bank_accounts', 1);
        $this->assertDatabaseHas('company_bank_accounts', ['bank_name' => 'Банк']);
    }
}
