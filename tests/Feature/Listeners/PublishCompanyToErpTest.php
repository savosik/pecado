<?php

namespace Tests\Feature\Listeners;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Events\CompanyDeleted;
use App\Jobs\PublishCompanyToErpJob;
use App\Listeners\PublishCompanyToErp;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublishCompanyToErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_job_when_company_created(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-123']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyCreated($company);

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishCompanyToErpJob::class, function ($job) {
            return $job->payload['event'] === 'CompanyCreated'
                && isset($job->payload['company'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_dispatches_job_when_company_updated(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-456']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyUpdated($company);

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishCompanyToErpJob::class, function ($job) {
            return $job->payload['event'] === 'CompanyUpdated'
                && isset($job->payload['company'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_dispatches_job_when_company_deleted(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-789']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyDeleted($company);

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishCompanyToErpJob::class, function ($job) {
            return $job->payload['event'] === 'CompanyDeleted'
                && isset($job->payload['company'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_includes_bank_accounts_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-101']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        CompanyBankAccount::factory()->create(['company_id' => $company->id]);
        
        $event = new CompanyCreated($company);

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishCompanyToErpJob::class, function ($job) {
            return isset($job->payload['company']['bank_accounts'])
                && count($job->payload['company']['bank_accounts']) === 1;
        });
    }

    #[Test]
    public function it_includes_user_erp_id_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'test-erp-id-999']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        
        $event = new CompanyCreated($company);

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishCompanyToErpJob::class, function ($job) {
            return isset($job->payload['user']['erp_id'])
                && $job->payload['user']['erp_id'] === 'test-erp-id-999';
        });
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_company(): void
    {
        $event = new \stdClass();

        $listener = new PublishCompanyToErp();
        $listener->handle($event);

        Queue::assertNothingPushed();
    }
}
