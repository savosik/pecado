<?php

namespace Tests\Feature\Crm;

use App\Models\CrmClientProfile;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'decision_maker_name' => 'Пётр Смирнов',
            'decision_maker_role' => 'Закупщик',
            'decision_maker_contact' => '+7 900 000-00-00',
            'decision_process' => 'Согласует с владельцем, ответ через неделю',
            'payment_behavior' => 'deferred',
            'payment_terms' => 'Отсрочка 14 дней',
            'order_cycle_days' => 30,
            'preferred_channel' => 'telegram',
            'sentiment' => 'loyal',
            'notes_md' => '## Договорённости\n\nВозит сам.',
            'interests' => ['смазки', 'бренд X'],
        ], $overrides);
    }

    #[Test]
    public function manager_fills_profile_of_own_client(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload())
            ->assertRedirect();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('Пётр Смирнов', $profile->decision_maker_name);
        $this->assertSame('deferred', $profile->payment_behavior->value);
        $this->assertSame(30, $profile->order_cycle_days);
        $this->assertSame('telegram', $profile->preferred_channel->value);
        $this->assertSame($this->manager->id, $profile->notes_updated_by);
        $this->assertNotNull($profile->notes_updated_at);
    }

    #[Test]
    public function interests_are_saved_as_tags_of_their_own_type(): void
    {
        // Товарный тег с тем же названием не должен «перетечь» в интересы клиента.
        $product = Product::factory()->create();
        $product->attachTag('смазки');

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload())
            ->assertRedirect();

        $interests = $this->client->fresh()->tagsWithType(User::INTEREST_TAG_TYPE)
            ->map(fn ($tag) => (string) $tag->name)->sort()->values()->all();

        $this->assertSame(['бренд X', 'смазки'], $interests);
        $this->assertCount(1, $product->fresh()->tags);
    }

    #[Test]
    public function editing_notes_keeps_the_previous_version(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload(['notes_md' => 'Первая версия']));

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        // Первое заполнение ревизией не считается: сохранять «было пусто» нечего.
        $this->assertSame(0, $profile->revisions()->count());

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload(['notes_md' => 'Вторая версия']));

        $revisions = $profile->fresh()->revisions()->get();

        $this->assertCount(1, $revisions);
        $this->assertSame('Первая версия', $revisions->first()->notes_md);
        $this->assertSame($this->manager->id, $revisions->first()->user_id);
        $this->assertSame('Вторая версия', $profile->fresh()->notes_md);
    }

    #[Test]
    public function saving_without_touching_notes_does_not_create_a_revision(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload(['notes_md' => 'Текст']));

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload([
                'notes_md' => 'Текст',
                'payment_terms' => 'Предоплата 50%',
            ]));

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(0, $profile->revisions()->count());
        $this->assertSame('Предоплата 50%', $profile->payment_terms);
    }

    #[Test]
    public function card_opens_for_a_client_without_profile(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profile.decision_maker_name', null)
                ->where('profile.interests', [])
                ->where('profile.revisions', [])
            );
    }

    #[Test]
    public function foreign_client_profile_returns_404(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $otherProfile->id]);

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $foreign), $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('crm_client_profiles', 0);
    }

    #[Test]
    public function crm_employee_without_profile_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'crm-viewer']);
        $role->givePermissionTo(Permission::findByName('crm-dashboard.view'));

        $viewer = User::factory()->create();
        $viewer->assignRole($role);

        $this->actingAs($viewer)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload())
            ->assertForbidden();
    }

    #[Test]
    public function unknown_enum_value_gives_a_russian_validation_error(): void
    {
        $response = $this->actingAs($this->manager)
            ->from(route('crm.clients.show', $this->client))
            ->put(route('crm.clients.profile.update', $this->client), $this->payload([
                'payment_behavior' => 'как-нибудь',
            ]));

        $response->assertSessionHasErrors('payment_behavior');
        $this->assertSame(
            'Выберите платёжное поведение из списка.',
            session('errors')->first('payment_behavior'),
        );
    }

    #[Test]
    public function cleared_select_is_accepted_as_empty_value(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload());

        $this->actingAs($this->manager)
            ->put(route('crm.clients.profile.update', $this->client), $this->payload([
                'payment_behavior' => '',
                'sentiment' => '',
                'order_cycle_days' => '',
            ]))
            ->assertSessionHasNoErrors();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertNull($profile->payment_behavior);
        $this->assertNull($profile->sentiment);
        $this->assertNull($profile->order_cycle_days);
    }

    #[Test]
    public function interest_suggestions_do_not_leak_product_tags(): void
    {
        $product = Product::factory()->create();
        $product->attachTag('вибраторы');

        $this->client->syncTagsWithType(['вибраторы для витрины'], User::INTEREST_TAG_TYPE);

        $names = collect(
            $this->actingAs($this->manager)
                ->getJson(route('crm.interests.search', ['query' => 'вибра']))
                ->assertOk()
                ->json()
        )->pluck('name')->all();

        $this->assertSame(['вибраторы для витрины'], $names);
    }
}
