<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CompanyControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_list_own_companies(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $ownCompany = Company::factory()->create(['user_id' => $user->id]);
        
        $otherUser = User::factory()->create();
        $otherCompany = Company::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/companies');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $ownCompany->id]);
        $response->assertJsonMissing(['id' => $otherCompany->id]);
    }

    #[Test]
    public function admin_can_list_all_companies(): void
    {
        Event::fake();
        
        $admin = User::factory()->create(['is_admin' => true]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        Company::factory()->create(['user_id' => $user1->id]);
        Company::factory()->create(['user_id' => $user2->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/companies');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    #[Test]
    public function user_can_create_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/companies', [
            'country' => 'RU',
            'name' => 'Test Company',
            'legal_name' => 'ООО Тест',
            'tax_id' => '1234567890',
            'legal_address' => 'Moscow, Test Street 1',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['name' => 'Test Company']);
        
        $this->assertDatabaseHas('companies', [
            'user_id' => $user->id,
            'name' => 'Test Company',
            'country' => 'RU',
        ]);
    }

    #[Test]
    public function user_can_view_own_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/companies/{$company->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $company->id]);
    }

    #[Test]
    public function user_cannot_view_other_user_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/companies/{$company->id}");

        // CompanyScope hides other users' companies, returning 404 (more secure)
        $response->assertNotFound();
    }

    #[Test]
    public function user_can_update_own_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/companies/{$company->id}", [
            'name' => 'Updated Company Name',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Updated Company Name']);
        
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Company Name',
        ]);
    }

    #[Test]
    public function user_cannot_update_other_user_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/companies/{$company->id}", [
            'name' => 'Hacked Company',
        ]);

        // CompanyScope hides other users' companies, returning 404 (more secure)
        $response->assertNotFound();
    }

    #[Test]
    public function user_can_delete_own_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/companies/{$company->id}");

        $response->assertOk();
        
        // Soft delete - should exist with deleted_at
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    #[Test]
    public function user_cannot_delete_other_user_company(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/companies/{$company->id}");

        // CompanyScope hides other users' companies, returning 404 (more secure)
        $response->assertNotFound();
    }

    #[Test]
    public function company_show_includes_bank_accounts(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $bankAccount = CompanyBankAccount::factory()->create(['company_id' => $company->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/companies/{$company->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'bank_name' => $bankAccount->bank_name,
        ]);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_companies(): void
    {
        $response = $this->getJson('/api/companies');

        $response->assertUnauthorized();
    }

    #[Test]
    public function company_creation_validates_required_fields(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/companies', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['country', 'name', 'legal_name', 'tax_id', 'legal_address']);
    }

    #[Test]
    public function company_creation_validates_country_enum(): void
    {
        Event::fake();
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/companies', [
            'country' => 'INVALID',
            'name' => 'Test',
            'legal_name' => 'Test',
            'tax_id' => '123',
            'legal_address' => 'Address',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['country']);
    }
}
