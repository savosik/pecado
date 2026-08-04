<?php

namespace Tests\Feature\Crm;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Карточки заказа и реализации внутри CRM.
 *
 * Отдельного права у них нет — доступ решает скоуп клиента. Поэтому изоляция
 * проверяется на каждом входе: карточка документа это ещё один способ добраться
 * до чужих данных, зная ID.
 */
class ClientDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    private function orderFor(User $client): Order
    {
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'erp_number' => 'ЗК-100',
            'total_amount' => 15000,
            'currency_code' => 'RUB',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create(['name' => 'Товар для теста'])->id,
            'name' => 'Товар для теста',
            'quantity' => 3,
            'price' => 5000,
            'final_price' => 5000,
            'subtotal' => 15000,
        ]);

        return $order;
    }

    private function shipmentFor(User $client): Shipment
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'erp_number' => 'РЕ-200',
            'date' => now()->toDateString(),
            'erp_created_at' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 8000,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 2,
            'price' => 4000,
            'total' => 8000,
            'subtotal' => 8000,
        ]);

        return $shipment;
    }

    #[Test]
    public function manager_opens_own_client_order_with_items(): void
    {
        $order = $this->orderFor($this->client);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/Show')
                ->where('document.type', 'order')
                ->where('document.title', 'Заказ №ЗК-100')
                ->where('document.total_label', '15 000,00 ₽')
                ->has('document.items', 1)
                ->where('document.items.0.name', 'Товар для теста')
                ->where('document.items.0.quantity', 3)
                ->where('client.id', $this->client->id)
            );
    }

    #[Test]
    public function manager_opens_own_client_shipment(): void
    {
        $shipment = $this->shipmentFor($this->client);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.type', 'shipment')
                ->where('document.title', 'Реализация №РЕ-200')
                ->where('document.total_label', '8 000,00 ₽')
                ->has('document.items', 1)
            );
    }

    #[Test]
    public function manager_cannot_open_foreign_client_order(): void
    {
        $order = $this->orderFor($this->foreignClient());

        // 404, а не 403: существование чужого документа не подтверждаем.
        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertNotFound();
    }

    #[Test]
    public function manager_cannot_open_foreign_client_shipment(): void
    {
        $shipment = $this->shipmentFor($this->foreignClient());

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment))
            ->assertNotFound();
    }

    #[Test]
    public function sales_head_opens_any_department_document(): void
    {
        $order = $this->orderFor($this->client);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->get(route('crm.orders.show', $order))
            ->assertOk();
    }

    #[Test]
    public function admin_link_is_shown_only_to_those_who_can_open_it(): void
    {
        $order = $this->orderFor($this->client);

        // sales-manager ходит в админку — короткий путь к редактированию ему полезен.
        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.admin_url', route('admin.orders.show', $order->id))
            );

        // sales-head в /admin намеренно не пускают — кнопки быть не должно.
        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->get(route('crm.orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('document.admin_url', null));
    }

    #[Test]
    public function order_shows_related_shipments(): void
    {
        $order = $this->orderFor($this->client);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->client->id,
            'erp_number' => 'РЕ-300',
            'date' => now()->toDateString(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 5000,
        ]);

        // Заказ и реализация связаны через order_uuid в позициях, а не прямой
        // колонкой: одна отгрузка может закрывать несколько заказов.
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'order_uuid' => $order->uuid,
            'quantity' => 1,
            'price' => 5000,
            'total' => 5000,
            'subtotal' => 5000,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('document.related', 1)
                ->where('document.related.0.title', 'Реализация №РЕ-300')
            );
    }

    #[Test]
    public function orders_list_shows_only_own_clients(): void
    {
        $mine = $this->orderFor($this->client);
        $foreign = $this->orderFor($this->foreignClient());

        $ids = collect(
            $this->actingAs($this->manager)
                ->get(route('crm.orders.index'))
                ->assertOk()
                ->viewData('page')['props']['orders']['data']
        )->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function shipments_list_shows_only_own_clients(): void
    {
        $mine = $this->shipmentFor($this->client);
        $this->shipmentFor($this->foreignClient());

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/Shipments')
                ->where('shipments.total', 1)
                ->where('shipments.data.0.id', $mine->id)
            );
    }

    #[Test]
    public function sales_head_sees_documents_of_whole_department(): void
    {
        $this->orderFor($this->client);
        $this->orderFor($this->foreignClient());

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->get(route('crm.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 2));
    }

    #[Test]
    public function orders_list_search_does_not_leak_foreign_documents(): void
    {
        // Номер чужого документа известен — по нему всё равно ничего не находится.
        $foreign = $this->orderFor($this->foreignClient());
        $foreign->update(['erp_number' => 'ЗК-СЕКРЕТ']);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.index', ['search' => 'ЗК-СЕКРЕТ']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 0));
    }

    #[Test]
    public function orders_list_filters_by_status(): void
    {
        $order = $this->orderFor($this->client);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.index', ['status' => $order->status->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 1));

        $other = collect(OrderStatus::cases())
            ->first(fn (OrderStatus $case) => $case !== $order->status);

        $this->actingAs($this->manager)
            ->get(route('crm.orders.index', ['status' => $other->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 0));
    }

    #[Test]
    public function documents_tab_lists_client_orders_through_timeline(): void
    {
        $this->orderFor($this->client);
        $this->shipmentFor($this->client);

        // Вкладки «Заказы» и «Реализации» берут данные из ленты с фильтром типа —
        // второго источника со своими правилами видимости у них нет.
        $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['order']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity.url', route('crm.orders.show', Order::first()->id));
    }
}
