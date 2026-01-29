<?php

namespace Tests\Feature;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Events\CompanyDeleted;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CompanyEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function company_created_event_is_dispatched_when_company_is_created(): void
    {
        Event::fake([CompanyCreated::class]);

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Event::assertDispatched(CompanyCreated::class, function ($event) use ($company) {
            return $event->company->id === $company->id;
        });
    }

    #[Test]
    public function company_updated_event_is_dispatched_when_company_is_updated(): void
    {
        Event::fake([CompanyUpdated::class]);

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Event::fake([CompanyUpdated::class]);

        $company->update(['name' => 'Updated Name']);

        Event::assertDispatched(CompanyUpdated::class, function ($event) use ($company) {
            return $event->company->id === $company->id;
        });
    }

    #[Test]
    public function company_deleted_event_is_dispatched_when_company_is_deleted(): void
    {
        Event::fake([CompanyDeleted::class]);

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Event::fake([CompanyDeleted::class]);

        $company->delete();

        Event::assertDispatched(CompanyDeleted::class, function ($event) use ($company) {
            return $event->company->id === $company->id;
        });
    }
}
