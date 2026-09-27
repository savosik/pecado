<?php

namespace Tests\Feature\Crm;

use App\Models\ApiToken;
use App\Models\ClientAgentCall;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Экран «ИИ-агенты клиентов» (`/crm/agent-usage`, capi-17).
 *
 * Проверяется, что сводка считает то, что обещает (охват, вызовы, заказы,
 * отказы, простаивающие токены), что разрез «мои / отдел» работает как в
 * остальной CRM, и что чужой партнёр в карточке даёт 404, а не 403.
 */
class AgentUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $managerCard;

    private User $clientA;

    private User $clientB;

    private User $clientIdle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create(['name' => 'РОП']);
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create(['name' => 'Менеджер']);
        $this->manager->assignRole('sales-manager');
        $this->managerCard = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Менеджер']);

        $other = PersonalManager::factory()->create(['name' => 'Другой менеджер']);

        $this->clientA = User::factory()->create(['name' => 'Партнёр А', 'personal_manager_id' => $this->managerCard->id]);
        $this->clientB = User::factory()->create(['name' => 'Партнёр Б', 'personal_manager_id' => $other->id]);
        $this->clientIdle = User::factory()->create(['name' => 'Партнёр без вызовов', 'personal_manager_id' => $this->managerCard->id]);

        $tokenA = ApiToken::create(['user_id' => $this->clientA->id, 'name' => 'Агент А', 'is_active' => true]);
        ApiToken::create(['user_id' => $this->clientIdle->id, 'name' => 'Агент без дела', 'is_active' => true]);

        $this->logCall($this->clientA, ['kind' => 'mcp_connect', 'agent' => 'claude-code 2.1.0', 'session_id' => 's1', 'token_id' => $tokenA->id]);
        $this->logCall($this->clientA, ['kind' => 'mcp_tool', 'agent' => 'claude-code 2.1.0', 'session_id' => 's1', 'tool' => 'client-prices', 'operation' => 'catalog.prices', 'token_id' => $tokenA->id]);
        $this->logCall($this->clientA, ['kind' => 'mcp_tool', 'agent' => 'claude-code 2.1.0', 'session_id' => 's1', 'tool' => 'client-create-order', 'operation' => 'orders.create', 'mutating' => true, 'token_id' => $tokenA->id]);
        $this->logCall($this->clientA, ['kind' => 'mcp_tool', 'agent' => 'claude-code 2.1.0', 'session_id' => 's1', 'tool' => 'client-call', 'operation' => 'orders.get', 'ok' => false, 'error_code' => 'not_found', 'token_id' => $tokenA->id]);
        $this->logCall($this->clientB, ['kind' => 'rest', 'operation' => 'me']);
        $this->logCall($this->clientB, ['kind' => 'rest', 'operation' => 'questions.create', 'mutating' => true]);
        // Старый вызов — за пределами периода в 30 дней, в сводку не попадает.
        $this->logCall($this->clientB, ['kind' => 'rest', 'operation' => 'orders.create', 'mutating' => true, 'created_at' => now()->subDays(45)]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function logCall(User $client, array $attributes): void
    {
        ClientAgentCall::query()->create($attributes + [
            'user_id' => $client->id,
            'ok' => true,
            'mutating' => false,
            'duration_ms' => 12,
            'created_at' => now()->subHours(2),
        ]);
    }

    #[Test]
    #[TestDox('РОП видит отдел: охват, вызовы, заказы, отказы, агентов и токены без дела')]
    public function the_head_sees_the_department_summary(): void
    {
        $this->actingAs($this->head)
            ->get(route('crm.agent-usage.index', ['scope' => 'department']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/AgentUsage/Index')
                ->where('summary.partners', 2)
                ->where('summary.partners_with_tokens', 2)
                ->where('summary.connects', 1)
                ->where('summary.sessions', 1)
                ->where('summary.tool_calls', 3)
                ->where('summary.rest_calls', 2)
                ->where('summary.requests', 5)
                ->where('summary.errors', 1)
                ->where('summary.error_rate', 20)
                ->where('summary.orders', 1)
                ->where('summary.questions', 1)
                ->has('partners', 2)
                ->where('partners.0.name', 'Партнёр А')
                ->where('partners.0.agents.0', 'claude-code 2.1.0')
                ->where('partners.0.orders', 1)
                ->where('partners.0.errors', 1)
                ->where('agents.0.agent', 'claude-code 2.1.0')
                ->where('agents.0.partners', 1)
                ->where('errors.0.code', 'not_found')
                ->has('idleTokens', 1)
                ->where('idleTokens.0.partner.name', 'Партнёр без вызовов')
                ->where('filters.period', 30)
                ->where('canSeeDepartment', true)
                ->has('daily', 30)
            );
    }

    #[Test]
    #[TestDox('Разрез операций называет задачи по реестру и считает долю')]
    public function operations_are_labelled_from_the_registry(): void
    {
        $this->actingAs($this->head)
            ->get(route('crm.agent-usage.index', ['scope' => 'department']))
            ->assertInertia(fn ($page) => $page
                ->where('operations.0.calls', 1)
                ->where('operations.0.share', 20)
                ->has('operations', 5)
            );

        $labels = collect($this->actingAs($this->head)
            ->get(route('crm.agent-usage.index', ['scope' => 'department']))
            ->inertiaProps()['operations'])->pluck('label', 'key');

        $this->assertArrayHasKey('op:orders.create', $labels->all());
        $this->assertNotSame('orders.create', $labels['op:orders.create'], 'Операция должна называться по summary реестра, а не по идентификатору');
        $this->assertSame('Discovery /me', $labels['op:me']);
    }

    #[Test]
    #[TestDox('Менеджер в разрезе «мои» видит только своих партнёров')]
    public function the_manager_sees_own_partners_by_default(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.agent-usage.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.partners', 1)
                ->where('summary.rest_calls', 0)
                ->has('partners', 1)
                ->where('partners.0.name', 'Партнёр А')
                ->has('idleTokens', 1)
                ->where('filters.scope', 'mine')
            );
    }

    #[Test]
    #[TestDox('Период годом захватывает старые вызовы, а неделя — нет')]
    public function the_period_switch_changes_the_window(): void
    {
        $this->actingAs($this->head)
            ->get(route('crm.agent-usage.index', ['scope' => 'department', 'period' => 365]))
            ->assertInertia(fn ($page) => $page->where('summary.orders', 2)->where('filters.period', 365)->has('daily', 365));

        // Неизвестный период схлопывается в умолчание, а не даёт 500.
        $this->actingAs($this->head)
            ->get(route('crm.agent-usage.index', ['scope' => 'department', 'period' => 999]))
            ->assertInertia(fn ($page) => $page->where('filters.period', 30));
    }

    #[Test]
    #[TestDox('Карточка партнёра: журнал вызовов свежими сверху; чужой партнёр — 404')]
    public function the_partner_page_lists_calls_and_hides_strangers(): void
    {
        $this->actingAs($this->head)
            ->get(route('crm.agent-usage.show', $this->clientA))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/AgentUsage/Show')
                ->where('partner.name', 'Партнёр А')
                ->where('summary.tool_calls', 3)
                ->has('calls.data', 4)
                ->where('calls.data.0.kind', 'mcp_tool')
                ->where('calls.data.0.error_code', 'not_found')
                ->where('calls.data.0.token', 'Агент А')
            );

        // Сотрудник с правом на экран, но без права на отдел: чужой партнёр не существует.
        $lone = User::factory()->create(['name' => 'Новичок']);
        $lone->givePermissionTo('crm-agent-usage.view');
        PersonalManager::factory()->create(['user_id' => $lone->id]);

        $this->actingAs($lone)->get(route('crm.agent-usage.show', $this->clientA))->assertNotFound();
        $this->actingAs($lone)->get(route('crm.agent-usage.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.partners', 0)->where('canSeeDepartment', false));
    }

    #[Test]
    #[TestDox('Без права crm-agent-usage.view экран закрыт')]
    public function the_screen_requires_its_permission(): void
    {
        $stranger = User::factory()->create();
        $stranger->givePermissionTo('crm-dashboard.view');

        $this->actingAs($stranger)->get(route('crm.agent-usage.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('crm.agent-usage.show', $this->clientA))->assertForbidden();
    }
}
