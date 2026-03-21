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

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event'               => 'contractor.created',
            'uuid'                => 'contractor-uuid-456',
            'partner_uuid'        => 'partner-uuid-123',
            'name'                => 'ООО «Тест»',
            'legal_name'          => 'Общество с ограниченной ответственностью «Тест»',
            'tax_id'              => '7701234567',
            'registration_number' => '770101001',
            'legal_address'       => 'г. Москва, ул. Пушкина, д. 10',
            'actual_address'      => 'г. Москва, ул. Лермонтова, д. 5',
            'phone'               => '+74951234567',
            'email'               => 'info@test.ru',
        ]);

        $this->assertDatabaseHas('companies', [
            'erp_id'              => 'contractor-uuid-456',
            'user_id'             => $user->id,
            'name'                => 'ООО «Тест»',
            'legal_name'          => 'Общество с ограниченной ответственностью «Тест»',
            'tax_id'              => '7701234567',
            'registration_number' => '770101001',
            'legal_address'       => 'г. Москва, ул. Пушкина, д. 10',
            'actual_address'      => 'г. Москва, ул. Лермонтова, д. 5',
            'phone'               => '+74951234567',
            'email'               => 'info@test.ru',
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
            'erp_id'  => 'contractor-uuid-existing',
            'user_id' => $user->id,
            'name'    => 'Старое название',
            'tax_id'  => '1234567890',
            'country' => 'BY',
        ]);

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event'        => 'contractor.created',
            'uuid'         => 'contractor-uuid-existing',
            'partner_uuid' => 'partner-uuid-789',
            'name'         => 'Новое название',
            'tax_id'       => '0987654321',
        ]);

        // Должна быть одна компания, а не две
        $this->assertDatabaseCount('companies', 1);

        $this->assertDatabaseHas('companies', [
            'erp_id' => 'contractor-uuid-existing',
            'name'   => 'Новое название',
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

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event'        => 'contractor.created',
            'partner_uuid' => 'some-partner',
        ]);
    }

    #[Test]
    public function it_logs_warning_when_partner_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'отсутствует partner_uuid'));

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event' => 'contractor.created',
            'uuid'  => 'contractor-uuid-no-partner',
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

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event'        => 'contractor.created',
            'uuid'         => 'contractor-uuid-orphan',
            'partner_uuid' => 'nonexistent-partner-uuid',
            'name'         => 'Компания без партнёра',
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

        $handler = new HandleContractorCreated();
        $handler->handle([
            'event'        => 'contractor.created',
            'uuid'         => 'contractor-uuid-event-test',
            'partner_uuid' => 'partner-uuid-event-test',
            'name'         => 'Компания для теста событий',
        ]);

        Event::assertNotDispatched(CompanyCreated::class);
        Event::assertNotDispatched(CompanyUpdated::class);
    }
}
