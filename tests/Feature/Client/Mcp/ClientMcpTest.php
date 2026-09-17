<?php

namespace Tests\Feature\Client\Mcp;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Jobs\PublishOrderToErpJob;
use App\Mcp\Servers\ClientServer;
use App\Mcp\Tools\Client\ClientAskManager;
use App\Mcp\Tools\Client\ClientCall;
use App\Mcp\Tools\Client\ClientCatalog;
use App\Mcp\Tools\Client\ClientCreateOrder;
use App\Mcp\Tools\Client\ClientDescribe;
use App\Mcp\Tools\Client\ClientFaq;
use App\Mcp\Tools\Client\ClientOrderStatus;
use App\Mcp\Tools\Client\ClientPrices;
use App\Mcp\Tools\Client\ClientPromotions;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\CrmAgentToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserQuestion;
use App\Support\Client\ClientApiSource;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MCP-сервер клиента: `/mcp/client`.
 *
 * Проверяется, что токен клиента превращается в клиента и не открывает ничего
 * чужого, что инструменты — витрина над тем же реестром, что REST v1, и что
 * записи идут с идемпотентностью и аудитом.
 */
class ClientMcpTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp/client';

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

    private User $client;

    private Company $company;

    private ApiToken $token;

    /** @var array<string, array{available: int, preorder: int}> */
    private array $stock = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);
        Notification::fake();

        $this->client = User::factory()->create(['name' => 'Клиент']);
        $this->company = Company::factory()->create(['user_id' => $this->client->id, 'is_default' => true, 'tax_id' => '7707083893']);
        $this->token = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Агент клиента', 'is_active' => true]);

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $prices->method('getPriceMapForProducts')->willReturnCallback(function (iterable $products) {
            $map = [];
            foreach ($products as $p) {
                $map[$p->id] = new PriceResult(120.0, 100.0, 16.67, true);
            }

            return $map;
        });
        $this->app->instance(PriceServiceInterface::class, $prices);

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturnCallback(fn (Product $p) => $this->stock[$p->code] ?? ['available' => 0, 'preorder' => 0]);
        $stocks->method('getStockMapsByIds')->willReturnCallback(function (array $ids) {
            $maps = ['available' => [], 'preorder' => []];
            foreach ($ids as $id) {
                $code = Product::find($id)?->code;
                $maps['available'][$id] = $this->stock[$code]['available'] ?? 0;
                $maps['preorder'][$id] = $this->stock[$code]['preorder'] ?? 0;
            }

            return $maps;
        });
        $this->app->instance(StockServiceInterface::class, $stocks);

        $currency = $this->createMock(UserCurrencyResolverInterface::class);
        $currency->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currency);
    }

    protected function tearDown(): void
    {
        ClientApiSource::reset();
        parent::tearDown();
    }

    private function product(string $code, int $available): Product
    {
        $this->stock[$code] = ['available' => $available, 'preorder' => 0];

        return Product::factory()->create(['code' => $code, 'sku' => 'SKU-'.$code, 'name' => 'Товар '.$code]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function callMcp(array $headers = [])
    {
        return $this->postJson(self::ENDPOINT, self::INIT, array_merge(['Accept' => 'application/json, text/event-stream'], $headers));
    }

    #[Test]
    #[TestDox('Без токена, с чужим и с отозванным — 401; валидный токен пускает и помечает источник')]
    public function the_gate_requires_an_active_client_token(): void
    {
        // Без ссылки на OAuth-метаданные: иначе Cursor и часть других клиентов уходят
        // в OAuth и перестают отправлять наш статический Bearer-ключ.
        $unauthorized = $this->callMcp()->assertStatus(401);
        $this->assertStringNotContainsString('resource_metadata', (string) $unauthorized->headers->get('WWW-Authenticate'));
        $this->callMcp(['Authorization' => 'Bearer nope'])->assertStatus(401);

        $revoked = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Отозван', 'is_active' => false]);
        $this->callMcp(['Authorization' => 'Bearer '.$revoked->token])->assertStatus(401);

        $this->callMcp(['Authorization' => 'Bearer '.$this->token->token])->assertOk();
        $this->assertTrue(ClientApiSource::isApi());
        $this->assertSame('Агент клиента', ClientApiSource::tokenName());
        $this->assertNotNull($this->token->fresh()->last_used_at);
    }

    #[Test]
    #[TestDox('Границы: токен клиента не открывает /mcp/crm, токен сотрудника не открывает /mcp/client')]
    public function tokens_do_not_cross_server_boundaries(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        $crmToken = CrmAgentToken::issue('Агент менеджера', (int) $manager->id);

        $this->postJson('/mcp/crm', self::INIT, ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer '.$this->token->token])->assertStatus(401);
        $this->callMcp(['Authorization' => 'Bearer '.$crmToken->token])->assertStatus(401);
    }

    #[Test]
    #[TestDox('Ярлыки контента: client-promotions отдаёт действующие акции, client-faq — ответы и страницы')]
    public function content_shortcuts(): void
    {
        \App\Models\Promotion::factory()->create(['name' => 'Осенний подарок', 'slug' => 'osen', 'description' => 'Подарок к заказу']);
        \App\Models\Faq::factory()->create(['title' => 'Как вернуть брак?', 'content' => 'Через кабинет']);
        \App\Models\Page::factory()->create(['title' => 'Доставка', 'slug' => 'dostavka']);

        $list = ClientServer::actingAs($this->client)->tool(ClientPromotions::class);
        $list->assertOk();
        $list->assertSee('Осенний подарок');

        $one = ClientServer::actingAs($this->client)->tool(ClientPromotions::class, ['slug' => 'osen']);
        $one->assertOk();
        $one->assertSee('Подарок к заказу');

        ClientServer::actingAs($this->client)->tool(ClientPromotions::class, ['slug' => 'net-takoi'])->assertHasErrors();

        $faq = ClientServer::actingAs($this->client)->tool(ClientFaq::class, ['q' => 'брак']);
        $faq->assertOk();
        $faq->assertSee('Через кабинет');
        $faq->assertSee('dostavka');
    }

    #[Test]
    #[TestDox('Каталог отдаёт юрлица, состояние разделов и операции с allowed; describe — схему; неизвестная операция — ошибка')]
    public function catalog_and_describe(): void
    {
        config(['documents.enabled' => false]);

        $catalog = ClientServer::actingAs($this->client)->tool(ClientCatalog::class);
        $catalog->assertOk();
        $catalog->assertSee('orders.create');
        $catalog->assertSee('7707083893');
        $catalog->assertSee('documents_disabled');

        ClientServer::actingAs($this->client)->tool(ClientDescribe::class, ['operation' => 'orders.create'])->assertOk()->assertSee('idempotency_required');
        ClientServer::actingAs($this->client)->tool(ClientDescribe::class, ['operation' => 'orders.explode'])->assertHasErrors();
        ClientServer::actingAs($this->client)->tool(ClientCall::class, ['operation' => 'documents.list', 'arguments' => []])->assertHasErrors();
    }

    #[Test]
    #[TestDox('Сценарий: цены → корзина → заказ → статус; повтор ключа не создаёт второй заказ; аудит пишется')]
    public function client_agent_walks_through_prices_cart_order_status(): void
    {
        $channel = Log::spy();
        Log::shouldReceive('channel')->with('client-agent')->andReturn($channel);

        $this->product('A', 10);
        $this->product('B', 0);

        $prices = ClientServer::actingAs($this->client)->tool(ClientPrices::class, ['identifiers' => ['A', 'B', 'NOPE']]);
        $prices->assertOk()->assertSee('"available": 10')->assertSee('NOPE');

        ClientServer::actingAs($this->client)->tool(ClientCall::class, [
            'operation' => 'carts.set-quantity',
            'arguments' => ['cart' => 'active', 'identifier' => 'A', 'quantity' => 2],
        ])->assertOk();

        ClientServer::actingAs($this->client)->tool(ClientCall::class, ['operation' => 'checkout.preview', 'arguments' => []])->assertOk()->assertSee('instock_items');

        $order = ClientServer::actingAs($this->client)->tool(ClientCreateOrder::class, [
            'products' => [['identifier' => 'A', 'quantity' => 3], ['identifier' => 'B', 'quantity' => 1]],
            'idempotency_key' => 'mcp-order-1',
        ]);
        $order->assertOk()->assertSee('not_accepted')->assertSee('out_of_stock');
        $this->assertSame(1, Order::count());

        ClientServer::actingAs($this->client)->tool(ClientCreateOrder::class, [
            'products' => [['identifier' => 'A', 'quantity' => 3], ['identifier' => 'B', 'quantity' => 1]],
            'idempotency_key' => 'mcp-order-1',
        ])->assertOk()->assertSee('idempotent_replay');
        $this->assertSame(1, Order::count());
        Queue::assertPushed(PublishOrderToErpJob::class, 1);

        $created = Order::first();
        ClientServer::actingAs($this->client)->tool(ClientOrderStatus::class, ['order' => $created->number])->assertOk()->assertSee('pending_approval');

        $channel->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'orders.create' && $context['idempotency_key'] === 'mcp-order-1')
            ->once();
    }

    #[Test]
    #[TestDox('Ключ идемпотентности у ярлыка обязателен, чужой заказ недоступен, вопрос менеджеру создаётся')]
    public function guards_and_ask_manager(): void
    {
        $this->product('A', 10);

        ClientServer::actingAs($this->client)->tool(ClientCreateOrder::class, [
            'products' => [['identifier' => 'A', 'quantity' => 1]],
            'idempotency_key' => '',
        ])->assertHasErrors();
        $this->assertSame(0, Order::count());

        // Через client-call без ключа — подсказка про аргумент, а не про HTTP-заголовок.
        ClientServer::actingAs($this->client)->tool(ClientCall::class, [
            'operation' => 'orders.create',
            'arguments' => ['products' => [['identifier' => 'A', 'quantity' => 1]]],
        ])->assertHasErrors(['[idempotency_key_required] Для этой операции обязателен аргумент idempotency_key (например, UUID): повтор с тем же ключом не создаст дубль.']);

        $foreign = Order::factory()->create(['user_id' => User::factory()->create()->id]);
        ClientServer::actingAs($this->client)->tool(ClientOrderStatus::class, ['order' => (string) $foreign->id])->assertHasErrors(['[not_found] Запись не найдена или недоступна этому клиенту.']);

        ClientServer::actingAs($this->client)->tool(ClientAskManager::class, [
            'subject' => 'Объединить заказы',
            'body' => 'Пожалуйста, отправьте заказы одной машиной.',
        ])->assertOk();
        $this->assertSame(1, UserQuestion::where('user_id', $this->client->id)->count());
    }

    #[Test]
    #[TestDox('Каждый ярлык из инструкций сервера существует среди инструментов')]
    public function instructions_mention_only_existing_tools(): void
    {
        $defaults = (new \ReflectionClass(ClientServer::class))->getDefaultProperties();
        $names = array_map(fn (string $class) => (new \ReflectionClass($class))->getDefaultProperties()['name'], $defaults['tools']);

        preg_match_all('/`(client-[a-z-]+)`/', $defaults['instructions'], $matches);

        foreach (array_unique($matches[1]) as $mentioned) {
            $this->assertContains($mentioned, $names, "Инструкции упоминают несуществующий инструмент {$mentioned}");
        }
    }
}
