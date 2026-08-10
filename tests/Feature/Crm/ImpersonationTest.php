<?php

namespace Tests\Feature\Crm;

use App\Enums\UserStatus;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Просмотр сайта от имени клиента: кто может войти, что в этом режиме запрещено
 * и как менеджер возвращается в свою сессию.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $profile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager-crm');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $this->profile->id]);
    }

    private function startFor(?User $client = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager)
            ->post(route('crm.impersonation.start', $client?->id ?? $this->client->id));
    }

    #[Test]
    #[TestDox('Менеджер входит под своим клиентом')]
    public function manager_can_impersonate_own_client(): void
    {
        $this->startFor()->assertRedirect(route('cabinet.dashboard'));

        $this->assertAuthenticatedAs($this->client);
        $this->assertSame($this->manager->id, session(Impersonation::SESSION_KEY));
    }

    #[Test]
    #[TestDox('Без права crm-impersonate.use вход запрещён')]
    public function permission_is_required(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('content-manager');

        $this->actingAs($stranger)
            ->post(route('crm.impersonation.start', $this->client->id))
            // Роль без CRM-прав до маршрута не доходит — её разворачивает EnsureUserIsCrm.
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($stranger);
    }

    #[Test]
    #[TestDox('Роль с CRM-доступом, но без права входа, получает 403')]
    public function crm_role_without_the_permission_is_forbidden(): void
    {
        $this->manager->revokePermissionTo('crm-impersonate.use');
        $this->manager->roles()->first()->revokePermissionTo('crm-impersonate.use');

        $this->startFor()->assertForbidden();

        $this->assertAuthenticatedAs($this->manager);
    }

    #[Test]
    #[TestDox('Под чужим клиентом войти нельзя — 404')]
    public function foreign_client_is_not_found(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $otherProfile->id]);

        $this->startFor($foreign)->assertNotFound();

        $this->assertAuthenticatedAs($this->manager);
    }

    #[Test]
    #[TestDox('Под сотрудником войти нельзя — скоуп отдаёт 404')]
    public function staff_account_cannot_be_impersonated(): void
    {
        $staff = User::factory()->staff()->create(['personal_manager_id' => $this->profile->id]);

        $this->startFor($staff)->assertNotFound();
    }

    #[Test]
    #[TestDox('Под заблокированным клиентом войти нельзя')]
    public function blocked_client_cannot_be_impersonated(): void
    {
        $this->client->update(['status' => UserStatus::BLOCKED]);

        $this->startFor()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->manager);
    }

    #[Test]
    #[TestDox('В режиме просмотра заказ не оформить')]
    public function checkout_is_blocked_during_impersonation(): void
    {
        $this->startFor();

        $this->post(route('checkout.store'), [])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    #[TestDox('В режиме просмотра нельзя создать возврат и сменить пароль клиенту')]
    public function client_documents_and_credentials_are_blocked(): void
    {
        $this->startFor();

        $this->post(route('cabinet.returns.store'), [])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->put(route('cabinet.password.update'), [])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    #[TestDox('Корзина в режиме просмотра работает')]
    public function cart_stays_available_during_impersonation(): void
    {
        $this->startFor();

        $this->post(route('cart.store'), ['name' => 'Подбор менеджера'])
            ->assertRedirect();

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->client->id,
            'name' => 'Подбор менеджера',
        ]);
    }

    #[Test]
    #[TestDox('Вне режима просмотра оформление заказа не трогаем')]
    public function checkout_is_untouched_for_a_regular_client(): void
    {
        // Клиент сам дошёл до оформления: middleware не должен вмешиваться,
        // и запрос доходит до валидации контроллера, а не до нашей заглушки.
        $this->actingAs($this->client)
            ->post(route('checkout.store'), [])
            ->assertSessionHasErrors();

        $this->assertFalse(session()->has('error'));
    }

    #[Test]
    #[TestDox('Выход из режима возвращает менеджера в карточку клиента')]
    public function stop_returns_the_manager(): void
    {
        $this->startFor();

        $this->post(route('impersonation.stop'))
            ->assertRedirect(route('crm.clients.show', $this->client->id));

        $this->assertAuthenticatedAs($this->manager);
        $this->assertFalse(session()->has(Impersonation::SESSION_KEY));
    }

    #[Test]
    #[TestDox('Обычный клиент не может дёрнуть выход из режима')]
    public function stop_is_forbidden_without_an_active_session(): void
    {
        $this->actingAs($this->client)
            ->post(route('impersonation.stop'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($this->client);
    }

    #[Test]
    #[TestDox('«Выйти» в шапке сайта завершает просмотр, а не сессию менеджера')]
    public function logout_ends_the_impersonation_instead_of_the_session(): void
    {
        $this->startFor();

        $this->post(route('logout'))
            ->assertRedirect(route('crm.clients.show', $this->client->id));

        $this->assertAuthenticatedAs($this->manager);
    }

    #[Test]
    #[TestDox('Блокировка клиента во время просмотра возвращает менеджера, а не выкидывает на /login')]
    public function blocking_the_client_mid_session_returns_the_manager(): void
    {
        $this->startFor();

        $this->client->update(['status' => UserStatus::BLOCKED]);
        // Гвардия держит модель, загруженную предыдущим запросом; в боевом
        // окружении следующий запрос читает пользователя из БД заново.
        $this->app['auth']->forgetGuards();

        $this->get(route('cabinet.dashboard'))
            ->assertRedirect(route('crm.clients.show', $this->client->id));

        $this->assertAuthenticatedAs($this->manager);
    }

    #[Test]
    #[TestDox('Повторный вход в уже активном режиме отклоняется')]
    public function nested_impersonation_is_rejected(): void
    {
        $this->startFor();

        // Сессия уже клиентская, поэтому в CRM-маршрут менеджер не попадёт —
        // проверяем страховку на уровне контроллера напрямую.
        $this->assertTrue(Impersonation::active());

        $this->post(route('crm.impersonation.start', $this->client->id))
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($this->client);
    }

    #[Test]
    #[TestDox('Плашка режима приезжает в пропах, диалог смены пароля подавлен')]
    public function inertia_props_describe_the_mode(): void
    {
        $this->client->update(['must_change_password' => true]);

        $this->startFor();

        $this->get(route('cabinet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('impersonation.client_name', $this->client->display_name)
                ->where('impersonation.manager_name', $this->manager->name)
                ->where('auth.user.must_change_password', false)
            );
    }

    #[Test]
    #[TestDox('Вне режима проп impersonation пустой')]
    public function inertia_props_are_empty_for_a_regular_client(): void
    {
        $this->actingAs($this->client)
            ->get(route('cabinet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('impersonation', null));
    }
}
