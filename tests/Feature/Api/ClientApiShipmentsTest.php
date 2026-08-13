<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Клиентский API реализаций:
 * GET /api/client-api/{token}/shipments и /shipments/{shipment}.
 */
class ClientApiShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'token' => 'test-token-shipments',
            'is_active' => true,
        ])->token;
    }

    private function makeShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'currency_code' => 'RUB',
            'status' => 'completed',
        ], $attributes));
    }

    #[Test]
    public function it_returns_data_and_meta_envelope(): void
    {
        $this->makeShipment([
            'erp_number' => '29УТ-003413',
            'date' => '2026-08-01',
            'total_amount' => 15000.50,
        ]);

        $response = $this->getJson("/api/client-api/{$this->token}/shipments");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'uuid', 'number', 'erp_number', 'date', 'status', 'status_label',
                    'currency_code', 'total_amount', 'items_count', 'company', 'updated_at',
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.number', '29УТ-003413')
            ->assertJsonPath('data.0.total_amount', 15000.5)
            ->assertJsonPath('data.0.status_label', 'Выполнена')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function it_returns_only_own_shipments(): void
    {
        $this->makeShipment(['erp_number' => 'МОЙ-1']);

        $other = User::factory()->create();
        Shipment::factory()->create(['user_id' => $other->id, 'erp_number' => 'ЧУЖОЙ-1']);

        $response = $this->getJson("/api/client-api/{$this->token}/shipments");

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', 'МОЙ-1');
    }

    #[Test]
    public function it_rejects_unknown_token(): void
    {
        $this->getJson('/api/client-api/no-such-token/shipments')->assertNotFound();
    }

    #[Test]
    public function it_hides_items_until_requested(): void
    {
        $shipment = $this->makeShipment();
        $product = Product::factory()->create([
            'name' => 'Товар 1',
            'external_id' => 'uuid-p1',
            'code' => 'ART-001',
            'sku' => 'SKU-001',
        ]);
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 100,
            'subtotal' => 300,
            'total' => 300,
        ]);

        $this->getJson("/api/client-api/{$this->token}/shipments")
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonMissingPath('data.0.items');

        $this->getJson("/api/client-api/{$this->token}/shipments?with_items=1")
            ->assertOk()
            ->assertJsonPath('data.0.items.0.product.uuid', 'uuid-p1')
            ->assertJsonPath('data.0.items.0.product.code', 'ART-001')
            ->assertJsonPath('data.0.items.0.quantity', 3)
            ->assertJsonPath('data.0.items.0.total', 300);
    }

    #[Test]
    public function it_never_exposes_cost_price(): void
    {
        $shipment = $this->makeShipment();
        $product = Product::factory()->create(['cost_price' => 777.77]);
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
        ]);

        $this->getJson("/api/client-api/{$this->token}/shipments?with_items=1")
            ->assertOk()
            ->assertDontSee('cost_price')
            ->assertDontSee('777.77');
    }

    #[Test]
    public function it_filters_by_status_and_dates(): void
    {
        $this->makeShipment(['status' => 'completed', 'date' => '2026-08-01', 'erp_number' => 'A']);
        $this->makeShipment(['status' => 'cancelled', 'date' => '2026-08-02', 'erp_number' => 'B']);
        $this->makeShipment(['status' => 'completed', 'date' => '2026-07-01', 'erp_number' => 'C']);

        $this->getJson("/api/client-api/{$this->token}/shipments?status=completed")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/api/client-api/{$this->token}/shipments?date_from=2026-08-01&date_to=2026-08-01")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', 'A');
    }

    #[Test]
    public function it_validates_date_format_in_russian(): void
    {
        $this->getJson("/api/client-api/{$this->token}/shipments?date_from=01.08.2026")
            ->assertStatus(422)
            ->assertJsonPath('errors.date_from.0', 'Дата начала должна быть в формате ГГГГ-ММ-ДД');
    }

    #[Test]
    public function it_filters_by_number_ignoring_dashes(): void
    {
        $this->makeShipment(['erp_number' => '29УТ-003413']);
        $this->makeShipment(['erp_number' => '29УТ-009999']);

        $this->getJson("/api/client-api/{$this->token}/shipments?number=29УТ003413")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', '29УТ-003413');
    }

    #[Test]
    public function it_filters_by_order_uuid(): void
    {
        $withOrder = $this->makeShipment(['erp_number' => 'С-ЗАКАЗОМ']);
        ShipmentItem::factory()->create([
            'shipment_id' => $withOrder->id,
            'order_uuid' => 'order-uuid-1',
        ]);
        $this->makeShipment(['erp_number' => 'БЕЗ-ЗАКАЗА']);

        $this->getJson("/api/client-api/{$this->token}/shipments?order_uuid=order-uuid-1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', 'С-ЗАКАЗОМ');
    }

    #[Test]
    public function it_filters_by_updated_since(): void
    {
        $old = $this->makeShipment(['erp_number' => 'СТАРАЯ']);
        $old->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        $this->makeShipment(['erp_number' => 'СВЕЖАЯ']);

        $since = now()->subDay()->toDateString();

        $this->getJson("/api/client-api/{$this->token}/shipments?updated_since={$since}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', 'СВЕЖАЯ');
    }

    #[Test]
    public function it_filters_by_inn(): void
    {
        $company = Company::factory()->create(['tax_id' => '7707083893']);
        $this->makeShipment(['erp_number' => 'ПО-ИНН', 'company_id' => $company->id, 'tax_id' => '7707083893']);
        $this->makeShipment(['erp_number' => 'ДРУГАЯ', 'tax_id' => '0000000000']);

        $this->getJson("/api/client-api/{$this->token}/shipments?inn=7707083893")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', 'ПО-ИНН');
    }

    #[Test]
    public function it_hides_payment_block_when_finance_disabled(): void
    {
        config()->set('cabinet.finance_enabled', false);

        $this->makeShipment(['payment_status' => Shipment::PAYMENT_PARTIAL, 'paid_amount' => 500]);

        $this->getJson("/api/client-api/{$this->token}/shipments")
            ->assertOk()
            ->assertJsonMissingPath('data.0.payment_status')
            ->assertJsonMissingPath('data.0.unpaid_amount');
    }

    #[Test]
    public function it_shows_payment_block_when_finance_enabled(): void
    {
        config()->set('cabinet.finance_enabled', true);

        $this->makeShipment([
            'total_amount' => 1000,
            'paid_amount' => 400,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        $this->getJson("/api/client-api/{$this->token}/shipments")
            ->assertOk()
            ->assertJsonPath('data.0.payment_status', Shipment::PAYMENT_PARTIAL)
            ->assertJsonPath('data.0.payment_status_label', 'Оплачена частично')
            ->assertJsonPath('data.0.paid_amount', 400)
            ->assertJsonPath('data.0.unpaid_amount', 600);
    }

    #[Test]
    public function it_returns_single_shipment_by_id_uuid_and_number(): void
    {
        $shipment = $this->makeShipment(['erp_number' => '29УТ-003413']);
        ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

        foreach ([$shipment->id, $shipment->uuid, '29УТ-003413'] as $identifier) {
            $this->getJson("/api/client-api/{$this->token}/shipments/".rawurlencode((string) $identifier))
                ->assertOk()
                ->assertJsonPath('data.id', $shipment->id)
                ->assertJsonPath('data.number', '29УТ-003413')
                ->assertJsonCount(1, 'data.items');
        }
    }

    #[Test]
    public function it_returns_related_orders_in_card(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => 'ЗАК-1']);
        $shipment = $this->makeShipment();
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'order_uuid' => $order->uuid,
        ]);

        $this->getJson("/api/client-api/{$this->token}/shipments/{$shipment->id}")
            ->assertOk()
            ->assertJsonPath('data.orders.0.number', 'ЗАК-1')
            ->assertJsonPath('data.orders.0.uuid', $order->uuid);
    }

    #[Test]
    public function it_does_not_return_foreign_shipment_card(): void
    {
        $other = User::factory()->create();
        $foreign = Shipment::factory()->create(['user_id' => $other->id]);

        $this->getJson("/api/client-api/{$this->token}/shipments/{$foreign->id}")
            ->assertNotFound();
    }
}
