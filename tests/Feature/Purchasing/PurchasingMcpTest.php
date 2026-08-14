<?php

namespace Tests\Feature\Purchasing;

use App\Mcp\Servers\PurchasingServer;
use App\Mcp\Tools\Purchasing\DefectBatch;
use App\Mcp\Tools\Purchasing\DefectBatches;
use App\Mcp\Tools\Purchasing\DefectSetPrice;
use App\Mcp\Tools\Purchasing\DefectSetPublish;
use App\Models\ProductDefect;
use App\Models\PurchasingAgentToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * MCP-сервер закупщика: `/mcp/purchasing`.
 *
 * Главное, что здесь проверяется, — что токен превращается в конкретного
 * закупщика: агент делает ровно то же, что человек в /admin/defects, теми же
 * правилами (публикация без цены запрещена, закрытая партия неприкосновенна),
 * и автор цены фиксируется в partии.
 */
class PurchasingMcpTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp/purchasing';

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Закупщик: заводим роль как на prod (вручную) + доназначаем defects.*,
     * что делает миграция grant_defect_permissions.
     */
    private function buyer(): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);

        foreach (['defects.view', 'defects.price', 'defects.publish'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Сотрудник только с просмотром — без прав на цену и публикацию. */
    private function viewerOnly(): User
    {
        $role = Role::firstOrCreate(['name' => 'defects-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'defects.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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

        $revoked = PurchasingAgentToken::issue('Отозванный', (int) $this->buyer()->id);
        $revoked->forceFill(['is_active' => false])->save();

        $this->callMcp(['Authorization' => 'Bearer '.$revoked->token])->assertStatus(401);
    }

    #[Test]
    #[TestDox('Токен сотрудника без права defects.view не пускает')]
    public function a_token_of_a_user_without_defect_access_is_refused(): void
    {
        $stranger = User::factory()->create();
        $token = PurchasingAgentToken::issue('Агент без прав', (int) $stranger->id);

        $this->callMcp(['Authorization' => 'Bearer '.$token->token])->assertStatus(401);
    }

    #[Test]
    #[TestDox('Валидный токен пускает и отмечает использование')]
    public function a_valid_token_authenticates(): void
    {
        $token = PurchasingAgentToken::issue('Агент закупщика', (int) $this->buyer()->id);

        $this->callMcp(['Authorization' => 'Bearer '.$token->token])->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    #[Test]
    #[TestDox('Токен без владельца создать нельзя')]
    public function a_token_always_has_an_owner(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        PurchasingAgentToken::create(['name' => 'Ничей', 'token' => str_repeat('a', 64), 'is_active' => true]);
    }

    #[Test]
    #[TestDox('Список партий отдаёт фото, остатки и статус публикации')]
    public function the_batch_list_returns_photos_and_stock(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 3]);

        $response = PurchasingServer::actingAs($this->buyer())->tool(DefectBatches::class);

        $response->assertOk();
        $response->assertSee((string) $defect->id);
        $response->assertSee($defect->defect_description);
        $response->assertSee('"photos"');
        $response->assertSee('"available_quantity": 3');
        $response->assertSee('"is_published": false');
    }

    #[Test]
    #[TestDox('Фильтр unpriced показывает только партии без цены')]
    public function the_unpriced_filter_narrows_the_list(): void
    {
        $unpriced = ProductDefect::factory()->create(['defect_description' => 'Царапина на корпусе без цены']);
        ProductDefect::factory()->priced(500)->create(['defect_description' => 'Помята коробка, цена уже есть']);

        $response = PurchasingServer::actingAs($this->buyer())
            ->tool(DefectBatches::class, ['filter' => 'unpriced']);

        $response->assertOk();
        $response->assertSee($unpriced->defect_description);
        $response->assertSee('"total": 1');
        $response->assertDontSee('Помята коробка, цена уже есть');
    }

    #[Test]
    #[TestDox('Неизвестный фильтр отклоняется с перечнем допустимых')]
    public function an_unknown_filter_is_refused(): void
    {
        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectBatches::class, ['filter' => 'everything'])
            ->assertHasErrors();
    }

    #[Test]
    #[TestDox('Карточка партии открывается по id, несуществующая — ошибка')]
    public function the_batch_card_is_available_by_id(): void
    {
        $defect = ProductDefect::factory()->priced(700)->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectBatch::class, ['id' => $defect->id])
            ->assertOk()
            ->assertSee('"price": 700');

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectBatch::class, ['id' => 999999])
            ->assertHasErrors();
    }

    #[Test]
    #[TestDox('Назначение цены пишет price и автора в priced_by')]
    public function setting_a_price_records_the_author(): void
    {
        $buyer = $this->buyer();
        $defect = ProductDefect::factory()->create();

        PurchasingServer::actingAs($buyer)
            ->tool(DefectSetPrice::class, ['id' => $defect->id, 'price' => 1234.56])
            ->assertOk();

        $this->assertDatabaseHas('product_defects', [
            'id' => $defect->id,
            'price' => 1234.56,
            'priced_by' => $buyer->id,
        ]);
    }

    #[Test]
    #[TestDox('Закрытой партии цену назначить нельзя')]
    public function a_closed_batch_cannot_be_priced(): void
    {
        $defect = ProductDefect::factory()->closed()->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPrice::class, ['id' => $defect->id, 'price' => 100])
            ->assertHasErrors();

        $this->assertNull($defect->fresh()->price);
    }

    #[Test]
    #[TestDox('Нулевая и отрицательная цена отклоняются')]
    public function a_non_positive_price_is_refused(): void
    {
        $defect = ProductDefect::factory()->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPrice::class, ['id' => $defect->id, 'price' => 0])
            ->assertHasErrors();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPrice::class, ['id' => $defect->id, 'price' => -5])
            ->assertHasErrors();

        $this->assertNull($defect->fresh()->price);
    }

    #[Test]
    #[TestDox('Без права defects.price операция отказывает с названием права')]
    public function pricing_without_permission_is_refused(): void
    {
        $defect = ProductDefect::factory()->create();

        PurchasingServer::actingAs($this->viewerOnly())
            ->tool(DefectSetPrice::class, ['id' => $defect->id, 'price' => 100])
            ->assertHasErrors(['Нет права «defects.price»: назначать цены этому сотруднику нельзя.']);

        $this->assertNull($defect->fresh()->price);
    }

    #[Test]
    #[TestDox('Публикация партии без цены запрещена')]
    public function publishing_without_a_price_is_refused(): void
    {
        $defect = ProductDefect::factory()->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPublish::class, ['id' => $defect->id, 'is_published' => true])
            ->assertHasErrors();

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    #[TestDox('Партия с ценой публикуется и снимается с публикации')]
    public function a_priced_batch_can_be_published_and_hidden(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPublish::class, ['id' => $defect->id, 'is_published' => true])
            ->assertOk();

        $this->assertTrue($defect->fresh()->is_published);

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPublish::class, ['id' => $defect->id, 'is_published' => false])
            ->assertOk();

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    #[TestDox('Без права defects.publish публикацией управлять нельзя')]
    public function publishing_without_permission_is_refused(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        PurchasingServer::actingAs($this->viewerOnly())
            ->tool(DefectSetPublish::class, ['id' => $defect->id, 'is_published' => true])
            ->assertHasErrors(['Нет права «defects.publish»: управлять публикацией этому сотруднику нельзя.']);

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    #[TestDox('Закрытую партию нельзя трогать и публикацией')]
    public function a_closed_batch_cannot_change_publication(): void
    {
        $defect = ProductDefect::factory()->priced(300)->closed()->create();

        PurchasingServer::actingAs($this->buyer())
            ->tool(DefectSetPublish::class, ['id' => $defect->id, 'is_published' => true])
            ->assertHasErrors();

        $this->assertFalse($defect->fresh()->is_published);
    }
}
