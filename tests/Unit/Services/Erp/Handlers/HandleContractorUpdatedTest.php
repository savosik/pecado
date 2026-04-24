<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Events\CompanyUpdated;
use App\Models\Company;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleContractorUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_company_found_by_erp_id_idempotent(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-upd-001']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-upd-001',
            'name' => 'Старое имя',
            'legal_name' => 'Старое полное наименование',
            'phone' => '+70000000000',
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-001',
            'uuid' => 'contractor-upd-001',
            'partner_uuid' => 'partner-upd-001',
            'name' => 'Новое имя',
            'phone' => '+79999999999',
        ]);

        $company->refresh();
        $this->assertEquals('Новое имя', $company->name);
        $this->assertEquals('+79999999999', $company->phone);
        $this->assertEquals('Старое полное наименование', $company->legal_name, 'legal_name не передан — не должен меняться');
    }

    #[Test]
    public function it_binds_erp_id_via_tax_id_fallback_backfill(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-upd-002']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '9876543210',
            'erp_id' => null,
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-002',
            'uuid' => 'contractor-upd-002',
            'partner_uuid' => 'partner-upd-002',
            'tax_id' => '9876543210',
            'name' => 'ООО Backfill',
        ]);

        $company->refresh();
        $this->assertEquals('contractor-upd-002', $company->erp_id, 'erp_id должен быть заполнен ленивым backfill');
        $this->assertEquals('ООО Backfill', $company->name);
    }

    #[Test]
    public function it_does_not_create_new_company_when_not_found(): void
    {
        User::factory()->create(['erp_id' => 'partner-upd-003']);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-003',
            'uuid' => 'contractor-upd-003',
            'partner_uuid' => 'partner-upd-003',
            'tax_id' => '0000000000',
            'name' => 'Не должна создаваться',
        ]);

        $this->assertDatabaseMissing('companies', ['erp_id' => 'contractor-upd-003']);
        $this->assertDatabaseMissing('companies', ['name' => 'Не должна создаваться']);
    }

    #[Test]
    public function it_skips_payload_without_uuid(): void
    {
        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-004',
            'partner_uuid' => 'partner-upd-004',
        ]);

        $this->assertDatabaseCount('companies', 0);
    }

    #[Test]
    public function it_does_not_dispatch_company_events(): void
    {
        Event::fake([CompanyUpdated::class]);

        $user = User::factory()->create(['erp_id' => 'partner-upd-005']);
        Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-upd-005',
            'name' => 'Исходное',
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-005',
            'uuid' => 'contractor-upd-005',
            'partner_uuid' => 'partner-upd-005',
            'name' => 'Обновлено',
        ]);

        Event::assertNotDispatched(CompanyUpdated::class);
    }

    #[Test]
    public function it_replaces_bank_accounts_when_array_provided(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-upd-006']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-upd-006',
        ]);

        $company->bankAccounts()->create([
            'bank_name' => 'Старый банк',
            'account_number' => '11111',
            'is_primary' => true,
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-006',
            'uuid' => 'contractor-upd-006',
            'partner_uuid' => 'partner-upd-006',
            'bank_accounts' => [
                [
                    'bank_name' => 'Новый банк',
                    'account_number' => '22222',
                    'is_primary' => true,
                ],
            ],
        ]);

        $this->assertCount(1, $company->bankAccounts()->get());
        $this->assertEquals('Новый банк', $company->bankAccounts()->first()->bank_name);
    }

    #[Test]
    public function it_does_not_touch_bank_accounts_when_null(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-upd-007']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-upd-007',
        ]);

        $company->bankAccounts()->create([
            'bank_name' => 'Постоянный банк',
            'account_number' => '33333',
            'is_primary' => true,
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-007',
            'uuid' => 'contractor-upd-007',
            'partner_uuid' => 'partner-upd-007',
            'name' => 'Обновлено',
            // bank_accounts не передан
        ]);

        $this->assertCount(1, $company->bankAccounts()->get());
        $this->assertEquals('Постоянный банк', $company->bankAccounts()->first()->bank_name);
    }

    #[Test]
    public function it_obeys_global_scopes_when_looking_up(): void
    {
        // CompanyScope фильтрует по user_id, поэтому withoutGlobalScopes обязательно
        $user = User::factory()->create(['erp_id' => 'partner-upd-008']);
        Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-upd-008',
            'name' => 'Исходное',
        ]);

        $handler = new HandleContractorUpdated;
        $handler->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-upd-008',
            'uuid' => 'contractor-upd-008',
            'partner_uuid' => 'partner-upd-008',
            'name' => 'Обновлено через scopes',
        ]);

        $this->assertDatabaseHas('companies', [
            'erp_id' => 'contractor-upd-008',
            'name' => 'Обновлено через scopes',
        ]);
    }
}
