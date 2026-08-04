<?php

namespace Tests\Feature\Crm\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Crm\CrmAddComment;
use App\Mcp\Tools\Crm\CrmCall;
use App\Mcp\Tools\Crm\CrmCatalog;
use App\Mcp\Tools\Crm\CrmClientCard;
use App\Mcp\Tools\Crm\CrmCreateTask;
use App\Mcp\Tools\Crm\CrmDescribe;
use App\Models\CrmAgentToken;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Crm\CrmSource;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MCP-сервер отдела продаж: `/mcp/crm`.
 *
 * Главное, что здесь проверяется, — что токен не открывает «доступ к API»,
 * а превращается в конкретного сотрудника: агент видит и меняет ровно то же,
 * что и его менеджер, и ни строкой больше. Плюс запреты, которые отличают
 * пишущий гейт от читающего: удаление закрыто, каждая запись помечена
 * источником и попала в аудит.
 */
class CrmMcpTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp/crm';

    private const INIT = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ],
    ];

    private User $managerA;

    private User $managerB;

    private User $clientA;

    private User $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->managerA = User::factory()->create(['name' => 'Менеджер А']);
        $this->managerA->assignRole('sales-manager');
        $profileA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->create(['name' => 'Менеджер Б']);
        $this->managerB->assignRole('sales-manager');
        $profileB = PersonalManager::factory()->create(['user_id' => $this->managerB->id]);

        $this->clientA = User::factory()->create([
            'name' => 'Клиент А',
            'personal_manager_id' => $profileA->id,
        ]);
        $this->clientB = User::factory()->create([
            'name' => 'Клиент Б',
            'personal_manager_id' => $profileB->id,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function callMcp(array $headers = [])
    {
        return $this->postJson(self::ENDPOINT, self::INIT, array_merge([
            'Accept' => 'application/json, text/event-stream',
        ], $headers));
    }

    #[Test]
    #[TestDox('Без токена, с чужим и с отозванным — 401 с одинаковым текстом')]
    public function the_gate_requires_an_active_token(): void
    {
        $this->callMcp()->assertStatus(401);
        $this->callMcp(['Authorization' => 'Bearer nope'])->assertStatus(401);

        $revoked = CrmAgentToken::issue('Отозванный', (int) $this->managerA->id);
        $revoked->forceFill(['is_active' => false])->save();

        $this->callMcp(['Authorization' => 'Bearer '.$revoked->token])->assertStatus(401);
    }

    #[Test]
    #[TestDox('Валидный токен пускает и помечает запросы источником agent')]
    public function a_valid_token_authenticates_and_marks_the_source(): void
    {
        $token = CrmAgentToken::issue('Агент менеджера А', (int) $this->managerA->id);

        $this->callMcp(['Authorization' => 'Bearer '.$token->token])->assertOk();

        $this->assertTrue(CrmSource::isAgent(), 'Гейт обязан пометить запрос как агентский.');
        $this->assertSame('Агент менеджера А', CrmSource::label());
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    #[Test]
    #[TestDox('Токен без владельца создать нельзя')]
    public function a_token_always_has_an_owner(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        CrmAgentToken::create(['name' => 'Ничей', 'token' => str_repeat('a', 64), 'is_active' => true]);
    }

    #[Test]
    #[TestDox('Каталог показывает операции и отмечает недоступные')]
    public function the_catalog_marks_what_is_not_available(): void
    {
        $response = CrmServer::actingAs($this->managerA)->tool(CrmCatalog::class);

        $response->assertOk();
        $response->assertSee('client.list');
        // Разрез по менеджерам менеджеру закрыт — это видно до вызова.
        $response->assertSee('plan.by-manager');
        $response->assertSee('Нет права «crm-clients-all.view».');
        // Удаление задач закрыто всем агентам, независимо от прав.
        $response->assertSee('Удаление задач через агента запрещено');
    }

    #[Test]
    #[TestDox('Описание операции отдаёт схему аргументов')]
    public function describe_returns_the_argument_schema(): void
    {
        $response = CrmServer::actingAs($this->managerA)
            ->tool(CrmDescribe::class, ['operation' => 'comment.create']);

        $response->assertOk();
        $response->assertSee('entity_type');
        $response->assertSee('body');
    }

    #[Test]
    #[TestDox('Неизвестная операция объясняет, где взять список')]
    public function unknown_operation_points_to_the_catalog(): void
    {
        CrmServer::actingAs($this->managerA)
            ->tool(CrmDescribe::class, ['operation' => 'client.explode'])
            ->assertHasErrors();
    }

    #[Test]
    #[TestDox('Сценарий каталог → описание → вызов создаёт комментарий и задачу')]
    public function the_full_scenario_writes_a_comment_and_a_task(): void
    {
        CrmServer::actingAs($this->managerA)->tool(CrmCatalog::class, ['section' => 'comments'])->assertOk();
        CrmServer::actingAs($this->managerA)->tool(CrmDescribe::class, ['operation' => 'comment.create'])->assertOk();

        CrmServer::actingAs($this->managerA)->tool(CrmCall::class, [
            'operation' => 'comment.create',
            'arguments' => [
                'entity_type' => 'client',
                'entity_id' => $this->clientA->id,
                'body' => 'Договорились о поставке в четверг',
            ],
        ])->assertOk();

        CrmServer::actingAs($this->managerA)->tool(CrmCall::class, [
            'operation' => 'task.create',
            'arguments' => [
                'title' => 'Отправить счёт',
                'entity_type' => 'client',
                'entity_id' => $this->clientA->id,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('crm_comments', [
            'client_user_id' => $this->clientA->id,
            'user_id' => $this->managerA->id,
        ]);
        $this->assertDatabaseHas('crm_tasks', [
            'client_user_id' => $this->clientA->id,
            'author_id' => $this->managerA->id,
            'title' => 'Отправить счёт',
        ]);
    }

    #[Test]
    #[TestDox('Агент не выходит за скоуп своего сотрудника')]
    public function the_agent_cannot_leave_its_scope(): void
    {
        CrmServer::actingAs($this->managerA)
            ->tool(CrmClientCard::class, ['client_id' => $this->clientB->id])
            ->assertHasErrors();

        CrmServer::actingAs($this->managerA)
            ->tool(CrmAddComment::class, [
                'client_id' => $this->clientB->id,
                'body' => 'Попытка написать в чужую карточку',
            ])
            ->assertHasErrors();

        $this->assertDatabaseCount('crm_comments', 0);

        // Свой клиент при этом доступен обоими инструментами.
        CrmServer::actingAs($this->managerA)
            ->tool(CrmClientCard::class, ['client_id' => $this->clientA->id])
            ->assertOk();
    }

    #[Test]
    #[TestDox('Операция без права отказывает с названием права')]
    public function an_operation_without_permission_is_refused(): void
    {
        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, ['operation' => 'plan.by-manager', 'arguments' => []])
            ->assertHasErrors(['Нет права «crm-clients-all.view»: операция «plan.by-manager» недоступна.']);
    }

    #[Test]
    #[TestDox('Удаление через агента закрыто, задача остаётся на месте')]
    public function deletion_through_the_agent_is_closed(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->managerA->id,
            'assignee_id' => $this->managerA->id,
            'client_user_id' => $this->clientA->id,
        ]);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'task.delete',
                'arguments' => ['task' => $task->id],
            ])
            ->assertHasErrors();

        $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    #[Test]
    #[TestDox('Свой комментарий удаляется мягко — запись восстановима')]
    public function own_comment_is_soft_deleted(): void
    {
        $comment = CrmComment::factory()->create([
            'commentable_type' => User::class,
            'commentable_id' => $this->clientA->id,
            'client_user_id' => $this->clientA->id,
            'user_id' => $this->managerA->id,
        ]);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCall::class, [
                'operation' => 'comment.delete',
                'arguments' => ['comment' => $comment->id],
            ])
            ->assertOk();

        $this->assertSoftDeleted('crm_comments', ['id' => $comment->id]);
    }

    #[Test]
    #[TestDox('Ярлыки делают ту же работу, что и полный вызов')]
    public function shortcuts_do_the_same_work(): void
    {
        CrmServer::actingAs($this->managerA)
            ->tool(CrmAddComment::class, [
                'client_id' => $this->clientA->id,
                'body' => 'Записано ярлыком',
            ])
            ->assertOk();

        CrmServer::actingAs($this->managerA)
            ->tool(CrmCreateTask::class, [
                'title' => 'Перезвонить в пятницу',
                'client_id' => $this->clientA->id,
                'priority' => 'high',
            ])
            ->assertOk();

        $this->assertDatabaseHas('crm_comments', [
            'client_user_id' => $this->clientA->id,
            'body' => 'Записано ярлыком',
        ]);
        $this->assertDatabaseHas('crm_tasks', [
            'client_user_id' => $this->clientA->id,
            'title' => 'Перезвонить в пятницу',
            'priority' => 'high',
        ]);
    }

    #[Test]
    #[TestDox('Запись помечена источником agent и попала в аудит с именем токена')]
    public function writes_are_marked_and_audited(): void
    {
        CrmSource::agent('Агент менеджера А');

        $channel = Log::spy();
        Log::shouldReceive('channel')->with('crm-agent')->andReturn($channel);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmAddComment::class, [
                'client_id' => $this->clientA->id,
                'body' => 'Эта запись обязана попасть в журнал',
            ])
            ->assertOk();

        $channel->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'comment.create'
                && $context['token'] === 'Агент менеджера А'
                && $context['user_id'] === $this->managerA->id)
            ->once();

        // Автор — менеджер, но источник различим: в ленте видно, что писал агент.
        $this->assertDatabaseHas('crm_comments', [
            'client_user_id' => $this->clientA->id,
            'user_id' => $this->managerA->id,
            'source' => CrmSource::AGENT,
        ]);
    }

    #[Test]
    #[TestDox('Чтение в аудит не пишется — иначе журнал утонет в шуме')]
    public function reads_are_not_audited(): void
    {
        $channel = Log::spy();
        Log::shouldReceive('channel')->with('crm-agent')->andReturn($channel);

        CrmServer::actingAs($this->managerA)
            ->tool(CrmClientCard::class, ['client_id' => $this->clientA->id])
            ->assertOk();

        $channel->shouldNotHaveReceived('info');
    }
}
