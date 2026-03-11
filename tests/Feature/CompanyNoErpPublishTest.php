<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * US-06: При действиях с контрагентом на сайте в 1С НИЧЕГО не отправляется.
 * Проверяем, что никакие Company-специфичные Job'ы не создаются.
 */
class CompanyNoErpPublishTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_company_does_not_dispatch_company_erp_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        // Сбрасываем очередь после создания пользователя (PublishUserToErpJob)
        Queue::fake();

        Company::factory()->create(['user_id' => $user->id]);

        // Убедимся, что не был диспатчен ни один Job
        // (если бы PublishCompanyToErp существовал, он бы создал PublishCompanyToErpJob)
        Queue::assertNothingPushed();
    }

    #[Test]
    public function updating_company_does_not_dispatch_company_erp_jobs(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Queue::fake();

        $company->update(['name' => 'Updated Company Name']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function deleting_company_does_not_dispatch_company_erp_jobs(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Queue::fake();

        $company->delete();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function company_events_still_fire_model_events(): void
    {
        $user = User::factory()->create();

        // Просто проверяем, что модельные события не сломаны
        $company = Company::factory()->create(['user_id' => $user->id]);
        $this->assertNotNull($company->id);

        $company->update(['name' => 'Test Update']);
        $this->assertEquals('Test Update', $company->fresh()->name);

        $company->delete();
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }
}
