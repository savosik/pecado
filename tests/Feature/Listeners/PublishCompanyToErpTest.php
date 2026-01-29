<?php

namespace Tests\Feature\Listeners;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Events\CompanyDeleted;
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

    #[Test]
    public function it_publishes_message_when_company_created(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-123']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyCreated($company);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_events' 
                    && isset($data['event']) 
                    && $data['event'] === 'CompanyCreated';
            });

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_publishes_message_when_company_updated(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-456']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyUpdated($company);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_events' 
                    && isset($data['event']) 
                    && $data['event'] === 'CompanyUpdated';
            });

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_publishes_message_when_company_deleted(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-789']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $event = new CompanyDeleted($company);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_events' 
                    && isset($data['event']) 
                    && $data['event'] === 'CompanyDeleted';
            });

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_includes_bank_accounts_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'user-erp-101']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        CompanyBankAccount::factory()->create(['company_id' => $company->id]);
        
        $event = new CompanyCreated($company);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_events' 
                    && isset($data['company']['bank_accounts'])
                    && count($data['company']['bank_accounts']) === 1;
            });

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_includes_user_erp_id_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'test-erp-id-999']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        
        $event = new CompanyCreated($company);

        Queue::shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturnSelf();
        
        Queue::shouldReceive('pushRaw')
            ->once()
            ->withArgs(function ($payload, $queue) {
                $data = json_decode($payload, true);
                return $queue === 'erp_events' 
                    && isset($data['user']['erp_id'])
                    && $data['user']['erp_id'] === 'test-erp-id-999';
            });

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
    }

    #[Test]
    public function it_does_nothing_when_event_has_no_company(): void
    {
        Queue::shouldReceive('connection')->never();
        Queue::shouldReceive('pushRaw')->never();

        $event = new \stdClass();

        $listener = new PublishCompanyToErp();
        $listener->handle($event);
        
        $this->assertTrue(true);
    }
}
