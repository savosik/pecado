<?php

namespace Tests\Feature\Api\Crm;

use App\Models\CrmAgentToken;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Api\OperationRegistry;
use App\Support\Crm\CrmSource;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * REST-гейт CRM для ИИ-агентов: `/api/crm/*`.
 *
 * Проверяется главное свойство гейта: агент не может больше своего сотрудника.
 * Скоуп задаёт токен, а не аргументы запроса, и ни одна операция не расширяет
 * видимость — потому что все они ходят через `User::visibleInCrm()`.
 */
class CrmApiTest extends TestCase
{
    use RefreshDatabase;

    private User $managerA;

    private User $managerB;

    private User $clientA;

    private User $clientB;

    private string $tokenA;

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

        $this->tokenA = CrmAgentToken::issue('Агент менеджера А', (int) $this->managerA->id)->token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function agent(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->json($method, $uri, $data, [
            'Authorization' => 'Bearer '.($token ?? $this->tokenA),
        ]);
    }

    #[Test]
    #[TestDox('Без токена и с отозванным токеном — 401')]
    public function it_requires_an_active_token(): void
    {
        $this->getJson('/api/crm/me')->assertStatus(401);

        $this->agent('GET', '/api/crm/me', token: 'definitely-not-a-token')->assertStatus(401);

        $revoked = CrmAgentToken::issue('Отозванный', (int) $this->managerA->id);
        $revoked->forceFill(['is_active' => false])->save();

        $this->agent('GET', '/api/crm/me', token: $revoked->token)->assertStatus(401);
    }

    #[Test]
    #[TestDox('Токен сотрудника без доступа в CRM не работает')]
    public function it_rejects_a_token_of_a_user_without_crm_access(): void
    {
        $outsider = User::factory()->create();
        $token = CrmAgentToken::issue('Посторонний', (int) $outsider->id);

        $this->agent('GET', '/api/crm/me', token: $token->token)->assertStatus(401);
    }

    #[Test]
    #[TestDox('Discovery отдаёт актора, скоуп и каталог операций с флагом allowed')]
    public function discovery_describes_the_actor_and_the_catalog(): void
    {
        $response = $this->agent('GET', '/api/crm/me')->assertOk();

        $response->assertJsonPath('data.actor.id', $this->managerA->id);
        $response->assertJsonPath('data.actor.is_head', false);
        // Менеджер видит только своего клиента — скоуп считается по нему, а не по базе.
        $response->assertJsonPath('data.scope.clients_visible', 1);

        $operations = collect($response->json('data.operations'));

        $this->assertSame(
            count(app(OperationRegistry::class)->all()),
            $operations->count(),
            'Каталог discovery обязан совпадать с реестром — иначе агент строит вызов по устаревшему списку.',
        );

        // Каждая операция несёт схему аргументов: агент собирает вызов по ней.
        $this->assertArrayHasKey('schema', $operations->firstWhere('id', 'comment.create'));

        // Разрез по менеджерам менеджеру недоступен — он это видит до вызова.
        $this->assertFalse($operations->firstWhere('id', 'plan.by-manager')['allowed']);
        $this->assertTrue($operations->firstWhere('id', 'client.list')['allowed']);
    }

    #[Test]
    #[TestDox('Список клиентов ограничен скоупом сотрудника')]
    public function client_list_is_limited_to_the_actor_scope(): void
    {
        $response = $this->agent('GET', '/api/crm/clients')->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertContains('Клиент А', $names->all());
        $this->assertNotContains('Клиент Б', $names->all());
    }

    #[Test]
    #[TestDox('Фильтр по менеджеру не расширяет видимость')]
    public function manager_filter_does_not_widen_the_scope(): void
    {
        $foreignManagerId = $this->managerB->managerProfile->id;

        $response = $this->agent('GET', '/api/crm/clients?manager_id='.$foreignManagerId)->assertOk();

        $this->assertNotContains('Клиент Б', collect($response->json('data'))->pluck('name')->all());
    }

    #[Test]
    #[TestDox('Чужой клиент даёт 404 во всех операциях по клиенту')]
    public function foreign_client_is_not_found_anywhere(): void
    {
        $uris = [
            '/api/crm/clients/'.$this->clientB->id,
            '/api/crm/clients/'.$this->clientB->id.'/profile',
            '/api/crm/clients/'.$this->clientB->id.'/timeline',
            '/api/crm/clients/'.$this->clientB->id.'/lifecycle',
        ];

        foreach ($uris as $uri) {
            $this->assertSame(404, $this->agent('GET', $uri)->status(), "Ожидался 404 на {$uri}");
        }

        // И запись тоже: комментарий не должен становиться способом писать
        // в карточку чужого клиента в обход скоупа.
        $this->agent('POST', '/api/crm/comments', [
            'entity_type' => 'client',
            'entity_id' => $this->clientB->id,
            'body' => 'Попытка записи в чужую карточку',
        ])->assertStatus(404);

        $this->assertDatabaseCount('crm_comments', 0);
    }

    #[Test]
    #[TestDox('Операция без права возвращает 403 с понятной причиной')]
    public function operation_without_permission_is_forbidden(): void
    {
        $this->agent('GET', '/api/crm/plans/by-manager')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Нет права «crm-clients-all.view»: операция «plan.by-manager» недоступна.');
    }

    #[Test]
    #[TestDox('Руководителю отдела тот же разрез доступен')]
    public function the_head_sees_the_manager_breakdown(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');
        $token = CrmAgentToken::issue('Агент РОПа', (int) $head->id);

        $this->agent('GET', '/api/crm/plans/by-manager', token: $token->token)->assertOk();
    }

    #[Test]
    #[TestDox('Комментарий создаётся автором-сотрудником и помечается источником agent')]
    public function comment_is_created_on_behalf_of_the_employee(): void
    {
        $this->agent('POST', '/api/crm/comments', [
            'entity_type' => 'client',
            'entity_id' => $this->clientA->id,
            'body' => 'Клиент просит прайс на новую линейку',
        ])->assertOk();

        $this->assertDatabaseHas('crm_comments', [
            'client_user_id' => $this->clientA->id,
            'user_id' => $this->managerA->id,
            'source' => CrmSource::AGENT,
        ]);
    }

    #[Test]
    #[TestDox('Задача без исполнителя остаётся на сотруднике, от имени которого работает агент')]
    public function task_defaults_to_the_token_owner(): void
    {
        $this->agent('POST', '/api/crm/tasks', [
            'title' => 'Позвонить по остаткам',
            'entity_type' => 'client',
            'entity_id' => $this->clientA->id,
        ])->assertOk();

        $task = CrmTask::query()->firstOrFail();

        $this->assertSame((int) $this->managerA->id, (int) $task->assignee_id);
        $this->assertSame((int) $this->managerA->id, (int) $task->author_id);
        $this->assertSame(CrmSource::AGENT, $task->source);
    }

    #[Test]
    #[TestDox('Неверные аргументы отклоняются с русским текстом, запись не создаётся')]
    public function invalid_arguments_are_rejected(): void
    {
        $this->agent('POST', '/api/crm/comments', [
            'entity_type' => 'client',
            'entity_id' => $this->clientA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('crm_comments', 0);
    }

    #[Test]
    #[TestDox('Удаление задачи через гейт недоступно: маршрута нет, а каталог объясняет почему')]
    public function task_deletion_is_closed_for_agents(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->managerA->id,
            'assignee_id' => $this->managerA->id,
            'client_user_id' => $this->clientA->id,
        ]);

        // 405, а не 404: адрес задачи существует (GET и PATCH), метода DELETE
        // у него нет — маршрут не создан, потому что операция закрыта для агента.
        $this->agent('DELETE', '/api/crm/tasks/'.$task->id)->assertStatus(405);

        $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'deleted_at' => null]);

        $entry = collect($this->agent('GET', '/api/crm/me')->json('data.operations'))
            ->firstWhere('id', 'task.delete');

        $this->assertFalse($entry['allowed']);
        $this->assertStringContainsString('Удаление задач через агента запрещено', $entry['denied_reason']);
    }

    #[Test]
    #[TestDox('Свой комментарий агент удалить может — мягко')]
    public function agent_may_soft_delete_its_own_comment(): void
    {
        $created = $this->agent('POST', '/api/crm/comments', [
            'entity_type' => 'client',
            'entity_id' => $this->clientA->id,
            'body' => 'Черновик, который потом убрали',
        ])->assertOk();

        $id = $created->json('id');

        $this->agent('DELETE', '/api/crm/comments/'.$id)->assertOk();

        // Мягко: строка остаётся в базе и восстановима.
        $this->assertDatabaseHas('crm_comments', ['id' => $id]);
        $this->assertSoftDeleted('crm_comments', ['id' => $id]);
    }

    #[Test]
    #[TestDox('Каждая операция реестра получила маршрут, и наоборот')]
    public function every_callable_operation_has_a_route(): void
    {
        $registry = app(OperationRegistry::class);

        foreach ($registry->callable() as $operation) {
            $this->assertNotNull(
                app('router')->getRoutes()->getByName('api.crm.'.$operation->id),
                "Операция «{$operation->id}» есть в реестре, но маршрута не получила.",
            );
        }

        // Обратная сторона: закрытая для агента операция адреса не получает.
        $this->assertNull(app('router')->getRoutes()->getByName('api.crm.task.delete'));
    }

    #[Test]
    #[TestDox('Документация CRM API открывается и построена из реестра')]
    public function the_openapi_document_is_published(): void
    {
        $response = $this->getJson('/docs/crm-api.json')->assertOk();

        $paths = $response->json('paths');

        $this->assertArrayHasKey('/api/crm/me', $paths);
        $this->assertArrayHasKey('/api/crm/comments', $paths);
        $this->assertSame(
            'comment.create',
            $paths['/api/crm/comments']['post']['operationId'],
        );

        $this->get('/docs/crm-api')->assertOk();
    }
}
