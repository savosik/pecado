<?php

namespace Tests\Feature\Crm;

use App\Models\CrmAgentToken;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Выдача и отзыв агентских токенов: команда и экран.
 *
 * Отзыв — операция срочная (уволился сотрудник, утёк токен), поэтому она обязана
 * работать без разработчика и закрывать доступ немедленно. Именно это здесь
 * и проверяется — не «кнопка нажимается», а «после нажатия запрос не проходит».
 */
class AgentTokensTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create(['name' => 'Руководитель отдела']);
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create(['name' => 'Менеджер Сухов']);
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    #[Test]
    #[TestDox('Экран доступен РОПу и закрыт менеджеру')]
    public function the_screen_is_for_the_head_only(): void
    {
        $this->actingAs($this->head)->get(route('crm.agent-tokens.index'))->assertOk();

        // Менеджер не выдаёт токены даже себе: решение «этому сотруднику агент
        // нужен» принимает тот, кто отвечает за отдел.
        $this->actingAs($this->manager)->get(route('crm.agent-tokens.index'))->assertForbidden();
        $this->actingAs($this->manager)
            ->post(route('crm.agent-tokens.store'), ['name' => 'Себе', 'user_id' => $this->manager->id])
            ->assertForbidden();
    }

    #[Test]
    #[TestDox('РОП выпускает токен, и он сразу работает')]
    public function an_issued_token_works_immediately(): void
    {
        $this->actingAs($this->head)
            ->post(route('crm.agent-tokens.store'), [
                'name' => 'Агент Сухова',
                'user_id' => $this->manager->id,
            ])
            ->assertRedirect();

        $token = CrmAgentToken::query()->firstOrFail();

        $this->assertSame((int) $this->manager->id, (int) $token->user_id);

        $this->getJson('/api/crm/me', ['Authorization' => 'Bearer '.$token->token])
            ->assertOk()
            ->assertJsonPath('data.actor.id', $this->manager->id);
    }

    #[Test]
    #[TestDox('Отзыв закрывает доступ немедленно, запись остаётся')]
    public function revoking_closes_access_at_once(): void
    {
        $token = CrmAgentToken::issue('Агент Сухова', (int) $this->manager->id);

        $this->getJson('/api/crm/me', ['Authorization' => 'Bearer '.$token->token])->assertOk();

        $this->actingAs($this->head)
            ->delete(route('crm.agent-tokens.destroy', $token->id))
            ->assertRedirect();

        $this->getJson('/api/crm/me', ['Authorization' => 'Bearer '.$token->token])->assertStatus(401);

        // Отзыв — флаг, а не удаление: кто имел пишущий доступ, видно и после.
        $this->assertDatabaseHas('crm_agent_tokens', ['id' => $token->id, 'is_active' => false]);
    }

    #[Test]
    #[TestDox('Исполнителем можно выбрать только сотрудника с доступом в CRM')]
    public function only_crm_staff_can_own_a_token(): void
    {
        $outsider = User::factory()->create(['name' => 'Кладовщик']);
        $outsider->assignRole('storekeeper');

        $this->actingAs($this->head)
            ->post(route('crm.agent-tokens.store'), [
                'name' => 'Агент кладовщика',
                'user_id' => $outsider->id,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('crm_agent_tokens', 0);
    }

    #[Test]
    #[TestDox('Токен виден в списке — прятать его, оставляя в базе, было бы имитацией')]
    public function the_token_value_is_visible_in_the_list(): void
    {
        $token = CrmAgentToken::issue('Агент Сухова', (int) $this->manager->id);

        $this->actingAs($this->head)
            ->get(route('crm.agent-tokens.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/AgentTokens/Index')
                ->where('tokens.0.token', $token->token)
                ->where('tokens.0.user', 'Менеджер Сухов'));
    }

    #[Test]
    #[TestDox('Команда crm:token выпускает, показывает и отзывает')]
    public function the_console_command_manages_tokens(): void
    {
        $this->artisan('crm:token', [
            'action' => 'issue',
            'value' => 'Агент Сухова',
            '--user' => $this->manager->id,
        ])->assertSuccessful();

        $token = CrmAgentToken::query()->firstOrFail();

        $this->artisan('crm:token', ['action' => 'list'])->assertSuccessful();

        $this->artisan('crm:token', ['action' => 'revoke', 'value' => $token->id])
            ->assertSuccessful();

        $this->assertFalse($token->fresh()->is_active);
    }

    #[Test]
    #[TestDox('Выдача без --user отклоняется с понятным текстом')]
    public function issuing_without_an_owner_is_rejected(): void
    {
        $this->artisan('crm:token', ['action' => 'issue', 'value' => 'Ничей'])
            ->expectsOutputToContain('Токен без владельца выпустить нельзя.')
            ->assertFailed();

        $this->assertDatabaseCount('crm_agent_tokens', 0);
    }

    #[Test]
    #[TestDox('Команда не выдаёт токен сотруднику без доступа в CRM')]
    public function the_command_checks_crm_access(): void
    {
        $outsider = User::factory()->create(['name' => 'Кладовщик']);
        $outsider->assignRole('storekeeper');

        $this->artisan('crm:token', [
            'action' => 'issue',
            'value' => 'Агент кладовщика',
            '--user' => $outsider->id,
        ])->assertFailed();

        $this->assertDatabaseCount('crm_agent_tokens', 0);
    }
}
