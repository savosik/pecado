<?php

namespace Tests\Unit\Services\Erp\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use App\Services\Erp\Support\OrderItemsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Синхронизация позиций заказа из 1С по номеру строки (протокол v15.16.0).
 *
 * Центральный сценарий — недобор: упаковщик в 1С дробит строку, часть остаётся,
 * часть отменяется. Данные взяты из живого сообщения 4609585 по заказу
 * 29УТ-012045 (гель, базовая 5090, скидка 25%, конечная 3817,50):
 *
 *   строка 1: quantity 2, cancelled false → 7 635,00
 *   строка 2: quantity 3, cancelled true  → 11 452,50
 *
 * До v15.16.0 эта пара давала в интерфейсе три разных числа: 7 635 (активная),
 * 11 452,50 (схлопывание по товару — побеждала последняя строка) и 19 087,50
 * (сумма обеих в total_amount).
 */
class OrderItemsSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private OrderItemsSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synchronizer = app(OrderItemsSynchronizer::class);
    }

    /**
     * Payload недобора из сообщения 4609585.
     *
     * @return list<array<string, mixed>>
     */
    private function shortfallPayload(string $productUuid): array
    {
        return [
            [
                'line_number' => 1,
                'product_uuid' => $productUuid,
                'quantity' => 2,
                'base_price' => 5090,
                'discount_percent' => 25,
                'final_price' => 3817.50,
                'cancelled' => false,
            ],
            [
                'line_number' => 2,
                'product_uuid' => $productUuid,
                'quantity' => 3,
                'base_price' => 5090,
                'discount_percent' => 25,
                'final_price' => 3817.50,
                'cancelled' => true,
            ],
        ];
    }

    #[Test]
    public function it_keeps_two_lines_of_the_same_product_apart(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);

        $this->synchronizer->sync($order, $this->shortfallPayload('gel-uuid'));

        $items = $order->fresh()->items()->orderBy('line_number')->get();

        $this->assertCount(2, $items, 'Две строки на один товар не должны схлопываться');
        $this->assertSame(2, $items[0]->quantity);
        $this->assertFalse($items[0]->cancelled);
        $this->assertSame(3, $items[1]->quantity);
        $this->assertTrue($items[1]->cancelled);
        $this->assertSame($product->id, $items[0]->product_id);
    }

    #[Test]
    public function cancelled_line_is_excluded_from_order_total(): void
    {
        $order = Order::factory()->create();
        Product::factory()->create(['external_id' => 'gel-uuid']);

        $total = $this->synchronizer->sync($order, $this->shortfallPayload('gel-uuid'));

        $this->assertSame(7635.00, $total, 'В сумму заказа входит только активная строка');

        // Отменённая строка сохраняется со своей суммой — клиент должен видеть,
        // чего именно не хватило и на сколько
        $cancelled = $order->fresh()->items()->where('cancelled', true)->first();
        $this->assertSame('11452.50', (string) $cancelled->subtotal);
    }

    #[Test]
    public function it_updates_existing_line_instead_of_recreating_it(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);

        $existing = OrderItem::factory()->create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 3817.50,
            'base_price' => 5090,
            'discount_percent' => 25,
            'final_price' => 3817.50,
            'subtotal' => 19087.50,
        ]);

        $this->synchronizer->sync($order, $this->shortfallPayload('gel-uuid'));

        $updated = $order->fresh()->items()->where('line_number', 1)->first();

        $this->assertSame($existing->id, $updated->id, 'Строка обязана сохранить id, а не пересоздаться');
        $this->assertSame(2, $updated->quantity);
    }

    #[Test]
    public function split_line_keeps_defect_batch_link_on_both_halves(): void
    {
        $order = Order::factory()->create(['type' => 'defect']);
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);
        $defect = ProductDefect::factory()->create([
            'product_id' => $product->id,
            'defect_description' => 'Порвана упаковка',
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 3817.50,
            'base_price' => 5090,
            'discount_percent' => 25,
            'final_price' => 3817.50,
            'subtotal' => 19087.50,
            'product_defect_id' => $defect->id,
            'defect_description' => 'Порвана упаковка',
        ]);

        $this->synchronizer->sync($order, $this->shortfallPayload('gel-uuid'));

        $items = $order->fresh()->items()->orderBy('line_number')->get();

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame(
                $defect->id,
                $item->product_defect_id,
                'Обе половины раздробленной строки ссылаются на ту же партию некондиции',
            );
            $this->assertSame('Порвана упаковка', $item->defect_description);
        }
    }

    #[Test]
    public function it_is_idempotent_on_redelivery(): void
    {
        $order = Order::factory()->create();
        Product::factory()->create(['external_id' => 'gel-uuid']);

        $payload = $this->shortfallPayload('gel-uuid');

        $first = $this->synchronizer->sync($order, $payload);
        $idsAfterFirst = $order->fresh()->items()->orderBy('line_number')->pluck('id')->all();

        $second = $this->synchronizer->sync($order, $payload);
        $idsAfterSecond = $order->fresh()->items()->orderBy('line_number')->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($idsAfterFirst, $idsAfterSecond, 'Повторная доставка не должна пересоздавать позиции');
        $this->assertCount(2, $idsAfterSecond);
    }

    #[Test]
    public function line_missing_from_payload_is_deleted(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);

        $this->synchronizer->sync($order, $this->shortfallPayload('gel-uuid'));
        $this->assertCount(2, $order->fresh()->items);

        // 1С сняла отмену и вернула одну строку
        $total = $this->synchronizer->sync($order, [
            [
                'line_number' => 1,
                'product_uuid' => 'gel-uuid',
                'quantity' => 5,
                'base_price' => 5090,
                'discount_percent' => 25,
                'final_price' => 3817.50,
            ],
        ]);

        $items = $order->fresh()->items;
        $this->assertCount(1, $items);
        $this->assertSame(5, $items->first()->quantity);
        $this->assertSame(19087.50, $total);
        $this->assertSame($product->id, $items->first()->product_id);
    }

    /**
     * Переходный путь: заказы, заведённые до v15.16.0, номеров строк не имеют.
     * Сопоставление по товару не даёт им пересоздаться на первом же обновлении.
     */
    #[Test]
    public function it_matches_legacy_items_without_line_number_by_product(): void
    {
        $order = Order::factory()->create();
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);

        $legacy = OrderItem::factory()->create([
            'order_id' => $order->id,
            'line_number' => null,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 3817.50,
            'base_price' => 5090,
            'discount_percent' => 25,
            'final_price' => 3817.50,
            'subtotal' => 19087.50,
        ]);

        $this->synchronizer->sync($order, [
            [
                'line_number' => 1,
                'product_uuid' => 'gel-uuid',
                'quantity' => 4,
                'base_price' => 5090,
                'discount_percent' => 25,
                'final_price' => 3817.50,
            ],
        ]);

        $items = $order->fresh()->items;

        $this->assertCount(1, $items);
        $this->assertSame($legacy->id, $items->first()->id);
        $this->assertSame(1, $items->first()->line_number, 'Номер строки проставляется при первом обновлении');
        $this->assertSame(4, $items->first()->quantity);
    }

    #[Test]
    public function it_falls_back_to_array_index_when_line_number_absent(): void
    {
        $order = Order::factory()->create();
        Product::factory()->create(['external_id' => 'gel-uuid']);

        $this->synchronizer->sync($order, [
            ['product_uuid' => 'gel-uuid', 'quantity' => 1, 'base_price' => 100, 'final_price' => 100],
            ['product_uuid' => 'gel-uuid', 'quantity' => 2, 'base_price' => 100, 'final_price' => 100],
        ]);

        $items = $order->fresh()->items()->orderBy('line_number')->get();

        $this->assertCount(2, $items);
        $this->assertSame([1, 2], $items->pluck('line_number')->all());
    }

    /**
     * До v15.16.0 order.updated молча выбрасывал позиции с неизвестным товаром,
     * а order.created их сохранял — состав заказа зависел от того, какое событие
     * приехало последним.
     */
    #[Test]
    public function it_keeps_line_with_unknown_product(): void
    {
        $order = Order::factory()->create();

        $total = $this->synchronizer->sync($order, [
            [
                'line_number' => 1,
                'product_uuid' => 'нет-такого-товара',
                'name' => 'Товар вне каталога',
                'quantity' => 2,
                'base_price' => 100,
                'final_price' => 100,
            ],
        ]);

        $item = $order->fresh()->items->first();

        $this->assertNotNull($item);
        $this->assertNull($item->product_id);
        $this->assertSame('Товар вне каталога', $item->name);
        $this->assertSame(200.00, $total);
    }

    /**
     * Сквозной путь через обработчик: сумма заказа и журнал изменений должны
     * говорить клиенту одно и то же число.
     */
    #[Test]
    public function handler_writes_single_total_for_split_line(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'order-29ut-012045',
            'total_amount' => 19087.50,
        ]);
        $product = Product::factory()->create(['external_id' => 'gel-uuid']);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 3817.50,
            'base_price' => 5090,
            'discount_percent' => 25,
            'final_price' => 3817.50,
            'subtotal' => 19087.50,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        app(HandleOrderUpdated::class)->handle([
            'uuid' => 'order-29ut-012045',
            'items' => $this->shortfallPayload('gel-uuid'),
        ]);

        $order->refresh();

        $this->assertSame('7635.00', (string) $order->total_amount);
        $this->assertCount(2, $order->items);

        $log = $order->changeLogs()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Отменено при сборке', $log->summary);
        $this->assertStringContainsString('кол-во: 5 → 2', $log->summary);
        $this->assertStringContainsString('19 087.50 → 7 635.00', $log->summary);
    }
}
