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
    public function rejects_tax_id_that_belongs_to_another_user(): void
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
            'привязан к другому аккаунту',
            $response->json('errors.tax_id.0')
        );
    }

    #[Test]
    public function claims_orphan_company_with_matching_tax_id(): void
    {
        $orphan = Company::factory()->create([
            'user_id' => null,
            'tax_id' => '7710140679',
            'legal_name' => 'Старое название из 1С',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/cabinet/companies/api', [
            'country' => 'RU',
            'name' => 'Тест',
            'legal_name' => 'ООО Тест',
            'tax_id' => '7710140679',
        ]);

        $response->assertStatus(201);
        $this->assertSame($orphan->id, $response->json('company.id'));

        // Та же запись теперь привязана к юзеру и обновлена
        $this->assertDatabaseHas('companies', [
            'id' => $orphan->id,
            'user_id' => $user->id,
            'legal_name' => 'ООО Тест',
        ]);

        // Не появилось дубля
        $this->assertSame(1, Company::where('tax_id', '7710140679')->count());
    }

    #[Test]
    public function updates_existing_own_company_with_matching_tax_id(): void
    {
        $user = User::factory()->create();
        $own = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '7710140679',
            'legal_name' => 'Старое название',
        ]);

        $response = $this->actingAs($user)->postJson('/cabinet/companies/api', [
            'country' => 'RU',
            'name' => 'Тест',
            'legal_name' => 'ООО Тест Обновлённое',
            'tax_id' => '7710140679',
        ]);

        $response->assertStatus(201);
        $this->assertSame($own->id, $response->json('company.id'));

        $this->assertDatabaseHas('companies', [
            'id' => $own->id,
            'user_id' => $user->id,
            'legal_name' => 'ООО Тест Обновлённое',
        ]);

        $this->assertSame(1, Company::where('tax_id', '7710140679')->count());
    }
}
