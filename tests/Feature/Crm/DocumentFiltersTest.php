<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Фильтры журналов «Документы → Заказы» и «Документы → Реализации».
 *
 * Менеджер ищет не «все заказы за месяц», а «что мы отгружали вот этому
 * контрагенту с этого склада» и «в каких документах вообще был вот этот товар» —
 * поэтому каждое поле мультивыборное, а товар отбирает документ по позициям.
 */
class DocumentFiltersTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $firstCard;

    private PersonalManager $secondCard;

    private User $firstClient;

    private User $secondClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config(['erp.organizations.enabled' => true]);

        // РОП: видит весь отдел, значит и фильтр по менеджеру ему доступен.
        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->firstCard = PersonalManager::factory()->create(['name' => 'Сухов']);
        $this->secondCard = PersonalManager::factory()->create(['name' => 'Курочкина']);

        $this->firstClient = User::factory()->create([
            'name' => 'Первый клиент',
            'personal_manager_id' => $this->firstCard->id,
        ]);
        $this->secondClient = User::factory()->create([
            'name' => 'Второй клиент',
            'personal_manager_id' => $this->secondCard->id,
        ]);
    }

    private function orderFor(User $client, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $client->id,
            'erp_created_at' => now(),
        ], $attributes));
    }

    private function shipmentFor(User $client, array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => now()->toDateString(),
            'erp_created_at' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 5000,
        ], $attributes));
    }

    /**
     * @return list<int>
     */
    private function idsFrom(AssertableInertia $page, string $prop): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $page->toArray()['props'][$prop]['data'],
        );
    }

    // ──────────────────────────────────────────────
    // Партнёр, контрагент, склад, организация
    // ──────────────────────────────────────────────

    #[Test]
    public function orders_are_filtered_by_several_partners(): void
    {
        $third = User::factory()->create(['personal_manager_id' => $this->firstCard->id]);

        $first = $this->orderFor($this->firstClient);
        $second = $this->orderFor($this->secondClient);
        $this->orderFor($third);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['partner_ids' => [$this->firstClient->id, $this->secondClient->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 2)
                ->where('filters.partner_ids', [$this->firstClient->id, $this->secondClient->id])
            )
            ->assertInertia(fn (AssertableInertia $page) => $this->assertEqualsCanonicalizing(
                [$first->id, $second->id],
                $this->idsFrom($page, 'orders'),
            ));
    }

    #[Test]
    public function orders_are_filtered_by_contractor(): void
    {
        $company = Company::factory()->create(['user_id' => $this->firstClient->id, 'name' => 'ООО Ромашка']);

        $matching = $this->orderFor($this->firstClient, ['company_id' => $company->id]);
        $this->orderFor($this->firstClient);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['company_ids' => [$company->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
            );
    }

    #[Test]
    public function shipments_are_filtered_by_several_warehouses(): void
    {
        $moscow = Warehouse::factory()->create(['name' => 'Москва основной']);
        $tyumen = Warehouse::factory()->create(['name' => 'Тюмень основной']);
        $spare = Warehouse::factory()->create(['name' => 'Резервный']);

        $first = $this->shipmentFor($this->firstClient, ['warehouse_id' => $moscow->id]);
        $second = $this->shipmentFor($this->firstClient, ['warehouse_id' => $tyumen->id]);
        $this->shipmentFor($this->firstClient, ['warehouse_id' => $spare->id]);

        $this->actingAs($this->head)
            ->get(route('crm.shipments.index', ['warehouse_ids' => [$moscow->id, $tyumen->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $this->assertEqualsCanonicalizing(
                [$first->id, $second->id],
                $this->idsFrom($page, 'shipments'),
            ));
    }

    /**
     * 'none' — «поле пустое». Документов без организации в переходный период
     * большинство, и отобрать нужно именно их.
     */
    #[Test]
    public function orders_are_filtered_by_documents_without_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->orderFor($this->firstClient, ['organization_id' => $organization->id]);
        $without = $this->orderFor($this->firstClient);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['organization_ids' => ['none']]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $without->id)
            );
    }

    /**
     * Пустое значение выбирается вместе с конкретным — иначе «этот склад плюс
     * непроставленные» пришлось бы смотреть в два захода.
     */
    #[Test]
    public function warehouse_filter_combines_none_with_concrete_value(): void
    {
        $moscow = Warehouse::factory()->create(['name' => 'Москва основной']);
        $other = Warehouse::factory()->create(['name' => 'Другой']);

        $first = $this->orderFor($this->firstClient, ['warehouse_id' => $moscow->id]);
        $without = $this->orderFor($this->firstClient);
        $this->orderFor($this->firstClient, ['warehouse_id' => $other->id]);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['warehouse_ids' => [$moscow->id, 'none']]))
            ->assertInertia(fn (AssertableInertia $page) => $this->assertEqualsCanonicalizing(
                [$first->id, $without->id],
                $this->idsFrom($page, 'orders'),
            ));
    }

    // ──────────────────────────────────────────────
    // Менеджер
    // ──────────────────────────────────────────────

    #[Test]
    public function head_filters_documents_by_manager(): void
    {
        $matching = $this->orderFor($this->firstClient);
        $this->orderFor($this->secondClient);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['manager_ids' => [$this->firstCard->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
                ->where('seesAll', true)
                ->has('managers', 2)
            );
    }

    /**
     * Чужой manager_ids не должен ничего открывать: рядовой менеджер видит
     * только своих клиентов, и подстановка чужого id это не меняет.
     */
    #[Test]
    public function manager_filter_cannot_widen_scope_of_regular_manager(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $own = User::factory()->create(['personal_manager_id' => $card->id]);

        $mine = $this->orderFor($own);
        $this->orderFor($this->firstClient);

        $this->actingAs($manager)
            ->get(route('crm.orders.index', ['manager_ids' => [$this->firstCard->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->id)
                ->where('seesAll', false)
                ->where('managers', [])
            );
    }

    // ──────────────────────────────────────────────
    // Товар
    // ──────────────────────────────────────────────

    #[Test]
    public function orders_are_filtered_by_product_in_items(): void
    {
        $product = Product::factory()->create(['name' => 'Вибратор Neutral']);
        $other = Product::factory()->create();

        $matching = $this->orderFor($this->firstClient);
        OrderItem::factory()->create(['order_id' => $matching->id, 'product_id' => $product->id]);

        $foreign = $this->orderFor($this->firstClient);
        OrderItem::factory()->create(['order_id' => $foreign->id, 'product_id' => $other->id]);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['product_ids' => [$product->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
                // Выбранный товар возвращается целиком: в адресе только id,
                // а рисовать фильтр нужно с названием.
                ->where('selectedProducts.0.name', 'Вибратор Neutral')
            );
    }

    /**
     * Документ с двумя выбранными товарами остаётся одной строкой — join вместо
     * whereHas задвоил бы его в списке и в счётчике.
     */
    #[Test]
    public function document_with_two_selected_products_is_not_duplicated(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        $order = $this->orderFor($this->firstClient);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $first->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $second->id]);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['product_ids' => [$first->id, $second->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.total', 1)
            );
    }

    #[Test]
    public function shipments_are_filtered_by_product_in_items(): void
    {
        $product = Product::factory()->create();

        $matching = $this->shipmentFor($this->firstClient);
        ShipmentItem::create([
            'shipment_id' => $matching->id,
            'product_id' => $product->id,
            'product_name_snapshot' => 'Позиция',
            'quantity' => 2,
            'price' => 100,
            'total' => 200,
        ]);

        $this->shipmentFor($this->firstClient);

        $this->actingAs($this->head)
            ->get(route('crm.shipments.index', ['product_ids' => [$product->id]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('shipments.data', 1)
                ->where('shipments.data.0.id', $matching->id)
            );
    }

    // ──────────────────────────────────────────────
    // Справочники и совместимость
    // ──────────────────────────────────────────────

    /**
     * Справочники собираются из самих документов журнала: у РОПа сотни клиентов,
     * и предлагать в фильтре тех, у кого ни одного заказа нет, бессмысленно.
     */
    #[Test]
    public function partner_and_contractor_options_come_from_documents(): void
    {
        $company = Company::factory()->create(['user_id' => $this->firstClient->id, 'name' => 'ООО Ромашка']);
        $this->orderFor($this->firstClient, ['company_id' => $company->id]);

        // У второго клиента заказов нет — в справочник он не попадает.
        $this->shipmentFor($this->secondClient);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('partners', 1)
                ->where('partners.0.id', $this->firstClient->id)
                ->has('companies', 1)
                ->where('companies.0.name', 'ООО Ромашка')
            );
    }

    #[Test]
    public function status_filter_accepts_multiple_values(): void
    {
        $new = $this->orderFor($this->firstClient, ['status' => 'pending_approval']);
        $paid = $this->orderFor($this->firstClient, ['status' => 'shipping']);
        $this->orderFor($this->firstClient, ['status' => 'closed']);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['statuses' => ['pending_approval', 'shipping']]))
            ->assertInertia(fn (AssertableInertia $page) => $this->assertEqualsCanonicalizing(
                [$new->id, $paid->id],
                $this->idsFrom($page, 'orders'),
            ));
    }

    /**
     * Старые ссылки со скалярным параметром продолжают работать: их раздавали
     * менеджерам в переписке, и ломать их из-за перехода на мультивыбор нельзя.
     */
    #[Test]
    public function legacy_scalar_parameters_still_filter(): void
    {
        $warehouse = Warehouse::factory()->create();

        $matching = $this->orderFor($this->firstClient, ['status' => 'shipping', 'warehouse_id' => $warehouse->id]);
        $this->orderFor($this->firstClient, ['status' => 'closed']);

        $this->actingAs($this->head)
            ->get(route('crm.orders.index', ['status' => 'shipping', 'warehouse_id' => $warehouse->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
                ->where('filters.statuses', ['shipping'])
                ->where('filters.warehouse_ids', [(string) $warehouse->id])
            );
    }

    /**
     * Право на отчёты продаж отзывается отдельно от доступа к клиентам —
     * подсказки товаров в журнале не должны от него зависеть.
     */
    #[Test]
    public function product_search_is_available_without_analytics_permission(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('crm-clients.view');

        $this->assertFalse($manager->can('crm-analytics.view'));

        // Короткий запрос отсекается до Meilisearch — проверяем именно доступ.
        $this->actingAs($manager)
            ->getJson(route('crm.documents.products.search', ['query' => 'a']))
            ->assertOk()
            ->assertExactJson([]);
    }
}
