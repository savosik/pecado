<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\GoodsIssue;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleGoodsIssueCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleGoodsIssueCreatedTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): HandleGoodsIssueCreated
    {
        return app(HandleGoodsIssueCreated::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'goods_issue.created',
            'message_id' => 'msg-gi-'.uniqid(),
            'uuid' => '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f',
            'number' => 'УТ-00009419',
            'date' => '2026-07-08T13:25:55+03:00',
            'shipment_date' => '2026-07-08T13:25:55+03:00',
            'status' => GoodsIssue::STATUS_TO_PICK,
            'operation' => 'Отгрузка клиенту',
            'recipient_name' => 'Интернет Решения ООО, г.Москва',
            'responsible' => 'Отгрузка 3 Москва',
            'priority' => GoodsIssue::PRIORITY_NORMAL,
            'comment' => 'Москва и МО доп',
            'delivery_type' => GoodsIssue::DELIVERY_DELIVERY,
            'items' => [
                [
                    'line_number' => 1,
                    'product_uuid' => 'product-uuid-1',
                    'product_name' => 'Товар из 1С',
                    'order_uuid' => 'order-uuid-1',
                    'order_number' => '30УТ-000213',
                    'quantity' => 15,
                    'unit' => 'шт',
                    'package_number' => 3,
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function it_creates_goods_issue_with_header_fields(): void
    {
        $this->handler()->handle($this->payload());

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertNotNull($goodsIssue);
        $this->assertSame('УТ-00009419', $goodsIssue->number);
        $this->assertSame(GoodsIssue::STATUS_TO_PICK, $goodsIssue->status);
        $this->assertSame('Отгрузка клиенту', $goodsIssue->operation);
        $this->assertSame('Интернет Решения ООО, г.Москва', $goodsIssue->recipient_name);
        $this->assertSame('Отгрузка 3 Москва', $goodsIssue->responsible);
        $this->assertSame('Москва и МО доп', $goodsIssue->comment);
        $this->assertSame(GoodsIssue::DELIVERY_DELIVERY, $goodsIssue->delivery_type);
    }

    #[Test]
    public function it_links_items_to_catalog_products_and_orders(): void
    {
        $product = Product::factory()->create(['external_id' => 'product-uuid-1']);
        $order = Order::factory()->create(['uuid' => 'order-uuid-1']);

        $this->handler()->handle($this->payload());

        $item = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f')->items->first();

        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($order->id, $item->order_id);
        $this->assertSame('order-uuid-1', $item->order_uuid);
        $this->assertSame('30УТ-000213', $item->order_number);
        $this->assertEquals(15, (float) $item->quantity);
        $this->assertSame(3, $item->package_number);
    }

    #[Test]
    public function it_keeps_item_when_product_is_missing_in_catalog(): void
    {
        // Ордер может уехать по номенклатуре, которой нет на сайте: кладовщику
        // он всё равно нужен, а имя показывается из снимка 1С.
        $this->handler()->handle($this->payload());

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');
        $item = $goodsIssue->items->first();

        $this->assertNull($item->product_id);
        $this->assertSame('Товар из 1С', $item->product_name);
        $this->assertSame('Товар из 1С', $item->product_label);
        $this->assertSame(1, $goodsIssue->unresolved_items_count);
    }

    #[Test]
    public function it_collects_items_from_several_orders(): void
    {
        $this->handler()->handle($this->payload([
            'items' => [
                ['product_uuid' => 'p1', 'order_uuid' => 'order-a', 'order_number' => '30УТ-000213', 'quantity' => 15],
                ['product_uuid' => 'p2', 'order_uuid' => 'order-a', 'order_number' => '30УТ-000213', 'quantity' => 10],
                ['product_uuid' => 'p3', 'order_uuid' => 'order-b', 'order_number' => '30УТ-000999', 'quantity' => 18],
            ],
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertSame(3, $goodsIssue->items_count);
        $this->assertEquals(43, (float) $goodsIssue->total_quantity);
        $this->assertSame(
            ['order-a', 'order-b'],
            $goodsIssue->items->pluck('order_uuid')->unique()->sort()->values()->all(),
        );
    }

    #[Test]
    public function it_numbers_lines_when_1c_omits_line_number(): void
    {
        $this->handler()->handle($this->payload([
            'items' => [
                ['product_uuid' => 'p1', 'quantity' => 1],
                ['product_uuid' => 'p2', 'quantity' => 2],
            ],
        ]));

        $lines = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f')
            ->items->pluck('line_number')->all();

        $this->assertSame([1, 2], $lines);
    }

    #[Test]
    public function it_stores_packages_and_counts_places(): void
    {
        $this->handler()->handle($this->payload([
            'packages' => [
                ['number' => 3, 'positions_count' => 2],
                ['number' => 4, 'positions_count' => 1],
                ['number' => 1, 'positions_count' => 2],
                ['number' => 2, 'positions_count' => 1],
            ],
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertSame(4, $goodsIssue->packages_count);
        // Отдаются в порядке номеров, а не в порядке присылки — склад считает места по номерам.
        $this->assertSame([1, 2, 3, 4], $goodsIssue->packages->pluck('number')->all());
    }

    #[Test]
    public function it_ignores_duplicate_package_numbers(): void
    {
        // Повтор номера места — ошибка 1С, но ронять из-за неё весь ордер нельзя.
        $this->handler()->handle($this->payload([
            'packages' => [
                ['number' => 1, 'positions_count' => 2],
                ['number' => 1, 'positions_count' => 5],
            ],
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertSame(1, $goodsIssue->packages_count);
        $this->assertSame(2, $goodsIssue->packages->first()->positions_count);
    }

    #[Test]
    public function it_resolves_contractor_by_uuid(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid']);
        $company = Company::factory()->create([
            'erp_id' => 'contractor-uuid',
            'user_id' => $user->id,
        ]);

        $this->handler()->handle($this->payload([
            'contractor_uuid' => 'contractor-uuid',
            'partner_uuid' => 'partner-uuid',
            'tax_id' => '7704217370',
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertSame($company->id, $goodsIssue->company_id);
        $this->assertSame($user->id, $goodsIssue->user_id);
        $this->assertSame('contractor-uuid', $goodsIssue->contractor_uuid);
    }

    #[Test]
    public function it_keeps_recipient_name_when_contractor_is_unknown(): void
    {
        $this->handler()->handle($this->payload(['contractor_uuid' => 'never-seen-uuid']));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertNull($goodsIssue->company_id);
        $this->assertSame('Интернет Решения ООО, г.Москва', $goodsIssue->recipient_label);
    }

    #[Test]
    public function it_resolves_warehouse_by_external_id(): void
    {
        $warehouse = Warehouse::factory()->create(['external_id' => 'warehouse-uuid']);

        $this->handler()->handle($this->payload(['warehouse_uuid' => 'warehouse-uuid']));

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');

        $this->assertSame($warehouse->id, $goodsIssue->warehouse_id);
        $this->assertSame('warehouse-uuid', $goodsIssue->warehouse_uuid);
    }

    #[Test]
    public function it_writes_first_status_history_entry(): void
    {
        $this->handler()->handle($this->payload());

        $goodsIssue = GoodsIssue::firstWhere('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f');
        $history = $goodsIssue->statusHistories;

        $this->assertCount(1, $history);
        $this->assertNull($history->first()->from_status);
        $this->assertSame(GoodsIssue::STATUS_TO_PICK, $history->first()->to_status);
        $this->assertNotNull($goodsIssue->status_changed_at);
    }

    #[Test]
    public function repeated_created_does_not_duplicate_the_order(): void
    {
        $this->handler()->handle($this->payload());
        $this->handler()->handle($this->payload(['message_id' => 'another-message']));

        $this->assertSame(1, GoodsIssue::where('uuid', '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f')->count());
        $this->assertSame(1, GoodsIssue::first()->items()->count());
    }

    #[Test]
    public function it_ignores_payload_without_uuid(): void
    {
        $payload = $this->payload();
        unset($payload['uuid']);

        $this->handler()->handle($payload);

        $this->assertSame(0, GoodsIssue::count());
    }
}
