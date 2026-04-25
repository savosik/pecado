<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyApiStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function requires_auth(): void
    {
        $response = $this->postJson('/cabinet/companies/api', []);
        $response->assertStatus(401);
    }

    #[Test]
    public function creates_company_with_required_fields(): void
    {
        $user = User::factory()->create();

        $payload = [
            'country' => 'RU',
            'name' => 'Тест',
            'legal_name' => 'ООО Тест',
            'tax_id' => '7707083893',
        ];

        $response = $this->actingAs($user)->postJson('/cabinet/companies/api', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure(['company' => ['id', 'name', 'legal_name', 'tax_id']]);

        $this->assertDatabaseHas('companies', [
            'user_id' => $user->id,
            'tax_id' => '7707083893',
            'legal_name' => 'ООО Тест',
        ]);
    }

    #[Test]
    public function validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/cabinet/companies/api', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country', 'name', 'legal_name', 'tax_id']);
    }

    #[Test]
    public function rejects_tax_id_that_already_exists_for_any_user(): void
    {
        $otherUser = User::factory()->create();
        Company::factory()->create([
            'user_id' => $otherUser->id,
            'tax_id' => '7710140679',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/cabinet/companies/api', [
            'country' => 'RU',
            'name' => 'Тест',
            'legal_name' => 'ООО Тест',
            'tax_id' => '7710140679',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tax_id']);

        $this->assertStringContainsString(
            'уже зарегистрирована',
            $response->json('errors.tax_id.0')
        );
    }
}
