<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Значок «внимание» + перечень изменений товарного состава заказа в списке
 * заказов кабинета (composition_changes в props Inertia).
 */
class OrderCompositionChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchOrders(): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/orders');
        $response->assertOk();

        $content = $response->getContent();
        if (! preg_match('/data-page="([^"]+)"/', $content, $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['orders']['data'] ?? [];
    }

    private function firstOrder(): array
    {
        $rows = $this->fetchOrders();
        $this->assertNotEmpty($rows);

        return $rows[0];
    }

    #[Test]
    public function it_reports_added_and_removed_positions_with_resolved_slugs(): void
    {
        $added = Product::factory()->create(['name' => 'Товар А', 'slug' => 'tovar-a']);
        $removed = Product::factory()->create(['name' => 'Товар Б', 'slug' => 'tovar-b']);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                // added: slug известен из нового формата лога.
                'added' => [[
                    'product_id' => $added->id,
                    'slug' => $added->slug,
                    'product_name' => $added->name,
                    'quantity' => 1,
                    'price' => 100,
                ]],
                // removed: старый формат без slug — резолвится по product_id.
                'removed' => [[
                    'product_id' => $removed->id,
                    'slug' => null,
                    'product_name' => $removed->name,
                    'quantity' => 2,
                    'price' => 50,
                ]],
                'modified' => [],
            ],
            'source' => 'erp',
        ]);

        $row = $this->firstOrder();

        $this->assertNotNull($row['composition_changes']);
        $this->assertSame(2, $row['composition_changes']['count']);

        $this->assertSame('Товар А', $row['composition_changes']['added'][0]['name']);
        $this->assertSame('tovar-a', $row['composition_changes']['added'][0]['slug']);

        $this->assertSame('Товар Б', $row['composition_changes']['removed'][0]['name']);
        $this->assertSame('tovar-b', $row['composition_changes']['removed'][0]['slug']);
    }

    #[Test]
    public function it_reports_partial_quantity_change_in_changed_group(): void
    {
        $product = Product::factory()->create(['name' => 'Частичный', 'slug' => 'chastichnyy']);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        // Выбыла 1 штука из 7 — это не полное удаление, а изменение количества.
        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                'added' => [],
                'removed' => [],
                'modified' => [[
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'product_name' => $product->name,
                    'changes' => ['quantity' => ['old' => 7, 'new' => 6]],
                ]],
            ],
            'source' => 'erp',
        ]);

        $row = $this->firstOrder();

        $this->assertNotNull($row['composition_changes']);
        $this->assertSame(1, $row['composition_changes']['count']);
        $this->assertEmpty($row['composition_changes']['added']);
        $this->assertEmpty($row['composition_changes']['removed']);

        $changed = $row['composition_changes']['changed'];
        $this->assertCount(1, $changed);
        $this->assertSame('Частичный', $changed[0]['name']);
        $this->assertSame('chastichnyy', $changed[0]['slug']);
        $this->assertSame(7, $changed[0]['from']);
        $this->assertSame(6, $changed[0]['to']);
    }

    #[Test]
    public function price_only_modification_does_not_produce_a_change(): void
    {
        $product = Product::factory()->create(['slug' => 'price-only']);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        // Изменилась только цена — в значок такое не выносим.
        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                'added' => [],
                'removed' => [],
                'modified' => [[
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'product_name' => 'X',
                    'changes' => ['final_price' => ['old' => 100, 'new' => 90]],
                ]],
            ],
            'source' => 'erp',
        ]);

        $row = $this->firstOrder();

        $this->assertNull($row['composition_changes']);
    }

    #[Test]
    public function it_resolves_slug_by_name_for_legacy_logs(): void
    {
        $product = Product::factory()->create(['name' => 'Старый товар', 'slug' => 'stary-tovar']);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                'added' => [[
                    // Легаси-лог: ни product_id, ни slug — только имя.
                    'product_name' => $product->name,
                    'quantity' => 1,
                    'price' => 10,
                ]],
                'removed' => [],
                'modified' => [],
            ],
            'source' => 'erp',
        ]);

        $row = $this->firstOrder();

        $this->assertSame(1, $row['composition_changes']['count']);
        $this->assertSame('stary-tovar', $row['composition_changes']['added'][0]['slug']);
    }

    #[Test]
    public function add_then_remove_cancels_out_and_yields_no_badge(): void
    {
        $product = Product::factory()->create(['slug' => 'toggle']);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        // Сначала товар добавили…
        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                'added' => [['product_id' => $product->id, 'slug' => $product->slug, 'product_name' => 'X', 'quantity' => 1, 'price' => 10]],
                'removed' => [],
                'modified' => [],
            ],
            'source' => 'erp',
            'created_at' => now()->subMinute(),
        ]);
        // …затем удалили — нетто-итог пуст.
        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => [
                'added' => [],
                'removed' => [['product_id' => $product->id, 'slug' => $product->slug, 'product_name' => 'X', 'quantity' => 1, 'price' => 10]],
                'modified' => [],
            ],
            'source' => 'erp',
            'created_at' => now(),
        ]);

        $row = $this->firstOrder();

        $this->assertNull($row['composition_changes']);
    }

    #[Test]
    public function orders_without_item_changes_have_no_composition_badge(): void
    {
        Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $row = $this->firstOrder();

        $this->assertNull($row['composition_changes']);
    }
}
