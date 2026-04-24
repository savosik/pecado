<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Events\CompanyDeleted;
use App\Models\Company;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleContractorDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_soft_deletes_company_by_uuid(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-del-001',
        ]);

        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-001',
            'uuid' => 'contractor-del-001',
        ]);

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    #[Test]
    public function it_soft_deletes_company_by_tax_id_with_partner_uuid_fallback(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-del-002']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '5555555555',
        ]);

        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-002',
            'tax_id' => '5555555555',
            'partner_uuid' => 'partner-del-002',
        ]);

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    #[Test]
    public function it_does_not_touch_other_users_company_with_same_tax_id(): void
    {
        $userA = User::factory()->create(['erp_id' => 'partner-del-003a']);
        $userB = User::factory()->create(['erp_id' => 'partner-del-003b']);

        $companyA = Company::factory()->create(['user_id' => $userA->id, 'tax_id' => '7777777777']);
        $companyB = Company::factory()->create(['user_id' => $userB->id, 'tax_id' => '7777777777']);

        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-003',
            'tax_id' => '7777777777',
            'partner_uuid' => 'partner-del-003a',
        ]);

        $this->assertSoftDeleted('companies', ['id' => $companyA->id]);
        $this->assertDatabaseHas('companies', ['id' => $companyB->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_does_nothing_when_uuid_and_tax_id_missing(): void
    {
        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-004',
        ]);

        // Просто не падает
        $this->assertTrue(true);
    }

    #[Test]
    public function it_does_nothing_when_company_not_found(): void
    {
        User::factory()->create(['erp_id' => 'partner-del-005']);

        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-005',
            'uuid' => 'contractor-del-missing',
            'tax_id' => '0000000001',
            'partner_uuid' => 'partner-del-005',
        ]);

        $this->assertTrue(true); // warning, без исключений
    }

    #[Test]
    public function it_does_not_dispatch_company_deleted_event(): void
    {
        Event::fake([CompanyDeleted::class]);

        $user = User::factory()->create();
        Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => 'contractor-del-006',
        ]);

        $handler = new HandleContractorDeleted;
        $handler->handle([
            'event' => 'contractor.deleted',
            'message_id' => 'msg-del-006',
            'uuid' => 'contractor-del-006',
        ]);

        Event::assertNotDispatched(CompanyDeleted::class);
    }
}
