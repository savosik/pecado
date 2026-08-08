<?php

namespace Tests\Feature\Crm;

use App\Models\CrmAgentToken;
use App\Models\CrmClientProfile;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Api\OperationRegistry;
use App\Support\Crm\ClientPassport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Паспорт клиента: поля, которые отдел собирает интервью с менеджером.
 *
 * Главное свойство, которое здесь защищается, — единственность перечня полей.
 * Форма, агентское API и база берут его из {@see ClientPassport}; стоит одному
 * месту завести свой список, и агент начнёт слать поле, которое сервер молча
 * отбросит, — без ошибки и без следа в логах.
 */
class ClientPassportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $personal = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $personal->id]);

        $this->token = CrmAgentToken::issue('Агент', (int) $this->manager->id)->token;
    }

    /**
     * @return array<string, mixed>
     */
    private function passportPayload(): array
    {
        return [
            'business_type' => 'chain',
            'points_count' => 12,
            'has_offline_points' => true,
            'has_online_store' => true,
            'works_with_marketplaces' => false,
            'specialization' => 'lingerie',
            'primary_channel' => 'offline',
            'secondary_channel' => 'online',
            'point_location' => 'mall',
            'price_segment' => 'premium',
            'staff_level' => 'experts',
            'regions' => 'Москва, Тверь',
            'delivery_method' => 'carrier',
            'carrier' => 'СДЭК, до терминала на Ленина',
            'receiving_hours' => 'Пн-Пт 09:00–12:00',
            'packaging_notes' => 'Маркировка коробов по точкам',
            'payment_type' => 'deferred',
            'deferral_days' => 21,
            'credit_rating' => 'reliable',
            'commercial_terms' => 'Компенсация логистики 10 000 ₽',
            'unique_terms' => 'Скидка 5% с 01.08.2026',
            'taboo_categories' => 'БДСМ',
            'taboo_brands' => 'Lovense — берёт у эксклюзива',
            'competitors' => 'Поставщик Х',
            'decision_maker_birthday' => '1985-04-17',
            'accountant_name' => 'Анна Петрова',
            'accountant_contact' => '+7 900 111-22-33',
            'owner_name' => 'Игорь Соколов',
            'owner_contact' => 'owner@example.test',
            'novelty_attitude' => 'innovator',
            'psychotype' => 'discount_hunter',
            'marketing_needs' => 'Тестеры и обучение персонала',
            'traffic_work' => 'Работают с блогерами',
        ];
    }

    #[Test]
    #[TestDox('Менеджер заполняет паспорт клиента через форму карточки')]
    public function manager_fills_passport_through_form(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->passportPayload())
            ->assertRedirect();

        $profile = CrmClientProfile::where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('chain', $profile->business_type->value);
        $this->assertSame(12, $profile->points_count);
        $this->assertSame('premium', $profile->price_segment->value);
        $this->assertSame(21, $profile->deferral_days);
        $this->assertSame('БДСМ', $profile->taboo_categories);
        $this->assertSame('1985-04-17', $profile->decision_maker_birthday->format('Y-m-d'));
    }

    #[Test]
    #[TestDox('Агент заполняет паспорт через client.profile.update')]
    public function agent_fills_passport_through_api(): void
    {
        $response = $this->json(
            'PATCH',
            "/api/crm/clients/{$this->client->id}/profile",
            $this->passportPayload(),
            ['Authorization' => 'Bearer '.$this->token],
        );

        // Операция отвечает своим результатом без обёртки: в ответе сразу поля профиля.
        $response->assertOk()
            ->assertJsonPath('business_type', 'chain')
            ->assertJsonPath('price_segment', 'premium')
            ->assertJsonPath('taboo_brands', 'Lovense — берёт у эксклюзива')
            ->assertJsonPath('passport_completeness.filled', count($this->passportPayload()));

        $profile = CrmClientProfile::where('user_id', $this->client->id)->firstOrFail();
        $this->assertSame('discount_hunter', $profile->psychotype->value);
        $this->assertSame('Работают с блогерами', $profile->traffic_work);
    }

    #[Test]
    #[TestDox('Непереданные поля паспорта остаются как были')]
    public function untouched_passport_fields_survive_partial_update(): void
    {
        $this->json('PATCH', "/api/crm/clients/{$this->client->id}/profile", $this->passportPayload(), [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertOk();

        // Интервью идёт блоками: каждый следующий блок не должен стирать предыдущий.
        $this->json('PATCH', "/api/crm/clients/{$this->client->id}/profile", [
            'price_segment' => 'medium',
        ], ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $profile = CrmClientProfile::where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('medium', $profile->price_segment->value);
        $this->assertSame('chain', $profile->business_type->value, 'Соседнее поле стёрлось при частичном обновлении');
        $this->assertSame(12, $profile->points_count);
    }

    #[Test]
    #[TestDox('Значение вне перечня отклоняется, а не пишется как есть')]
    public function invalid_enum_value_is_rejected(): void
    {
        $this->json('PATCH', "/api/crm/clients/{$this->client->id}/profile", [
            'price_segment' => 'люксовый',
        ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(422);

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), ['business_type' => 'что-то'])
            ->assertSessionHasErrors('business_type');
    }

    #[Test]
    #[TestDox('Каталог операций описывает каждое поле паспорта')]
    public function every_passport_field_is_described_in_the_catalog(): void
    {
        $operation = app(OperationRegistry::class)->find('client.profile.update');
        $this->assertNotNull($operation);

        $described = array_map(fn ($param) => $param->name, $operation->params);

        foreach (ClientPassport::keys() as $key) {
            $this->assertContains($key, $described, "Поле «{$key}» не описано для агента: он о нём не узнает");
        }
    }

    #[Test]
    #[TestDox('Каждое поле паспорта записывается в базу, а не отбрасывается моделью')]
    public function every_passport_field_is_fillable(): void
    {
        $fillable = (new CrmClientProfile)->getFillable();

        foreach (ClientPassport::keys() as $key) {
            $this->assertContains($key, $fillable, "Поле «{$key}» не в fillable: запись потерялась бы молча");
        }
    }

    #[Test]
    #[TestDox('Заполненность паспорта считается по факту, а не по наличию профиля')]
    public function completeness_reflects_filled_fields(): void
    {
        $profile = new CrmClientProfile;
        $this->assertSame(0, ClientPassport::completeness($profile)['filled']);

        $this->json('PATCH', "/api/crm/clients/{$this->client->id}/profile", [
            'business_type' => 'wholesale',
            'price_segment' => 'economy',
        ], ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $saved = CrmClientProfile::where('user_id', $this->client->id)->firstOrFail();
        $completeness = ClientPassport::completeness($saved);

        $this->assertSame(2, $completeness['filled']);
        $this->assertSame(count(ClientPassport::keys()), $completeness['total']);
    }

    #[Test]
    #[TestDox('Чужой клиент недоступен и для полей паспорта')]
    public function passport_of_foreign_client_is_not_writable(): void
    {
        $otherManager = User::factory()->create();
        $otherManager->assignRole('sales-manager');
        $otherPersonal = PersonalManager::factory()->create(['user_id' => $otherManager->id]);
        $foreign = User::factory()->create(['personal_manager_id' => $otherPersonal->id]);

        $this->json('PATCH', "/api/crm/clients/{$foreign->id}/profile", [
            'price_segment' => 'premium',
        ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(404);

        $this->assertDatabaseMissing('crm_client_profiles', ['user_id' => $foreign->id]);
    }
}
