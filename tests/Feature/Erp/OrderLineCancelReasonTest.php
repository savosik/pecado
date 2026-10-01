<?php

namespace Tests\Feature\Erp;

use App\Enums\Order\OrderLineCancelReason;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Defect\DefectStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Причина отмены строки заказа — `items[].cancel_reason` (протокол v16.17.0, топик №17 Agent Hub).
 *
 * Сообщения идут через `ErpIncomingJob`, то есть сквозь валидацию по JSON Schema:
 * проверяется и контракт (поле необязательно, не перечисление), и то, что из него
 * получается в строках заказа. Примеры `partial_shortage` и `client_cancel` — те,
 * что агент 1С прислал в проекте контракта (seq 4).
 *
 * Отдельная тема — дробление строки при приёме заказа: 1С отменяет хвост отдельной
 * строкой со своим номером, и этой строки в заказе сайта не было.
 */
class OrderLineCancelReasonTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_A = '00000000-0000-4000-a000-0000000170a1';

    private const PRODUCT_B = '00000000-0000-4000-a000-0000000170b2';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $payload */
    private function fire(array $payload): void
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.orders',
        ))->fire();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function orderUpdated(Order $order, array $items, string $messageId): array
    {
        return [
            'event' => 'order.updated',
            'uuid' => $order->uuid,
            'message_id' => $messageId,
            'number' => '29УТ-014795',
            'items' => $items,
        ];
    }

    /**
     * Строка в том виде, в каком её шлёт 1С в примерах seq 4.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function line(int $lineNumber, int $quantity, bool $cancelled, array $extra = [], string $productUuid = self::PRODUCT_A): array
    {
        return array_merge([
            'line_number' => $lineNumber,
            'product_uuid' => $productUuid,
            'quantity' => $quantity,
            'base_price' => 1000,
            'discount_percent' => 0,
            'final_price' => 1000,
            'cancelled' => $cancelled,
        ], $extra);
    }

    /**
     * Заказ, оформленный на сайте: строки без отмен, как их создал чекаут.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines  товар и количество по порядку строк
     */
    private function siteOrder(string $uuid, array $lines, array $attrs = []): Order
    {
        $manager = PersonalManager::factory()->create(['is_active' => true]);
        $client = User::factory()->create(['personal_manager_id' => $manager->id]);

        $order = Order::factory()->create(array_merge([
            'uuid' => $uuid,
            'user_id' => $client->id,
            'total_amount' => 1000 * array_sum(array_column($lines, 1)),
        ], $attrs));

        foreach ($lines as $index => [$product, $quantity]) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'line_number' => $index + 1,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => 1000,
                'base_price' => 1000,
                'discount_percent' => 0,
                'final_price' => 1000,
                'subtotal' => 1000 * $quantity,
            ]);
        }

        return $order;
    }

    private function productA(): Product
    {
        return Product::factory()->create(['external_id' => self::PRODUCT_A, 'name' => 'Гель-смазка']);
    }

    private function productB(): Product
    {
        return Product::factory()->create(['external_id' => self::PRODUCT_B, 'name' => 'Массажное масло']);
    }

    #[Test]
    public function partial_shortage_splits_the_site_line_into_active_part_and_cancelled_tail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:52:30'));

        // Клиент заказал 3 шт, свободно было 2: пример partial_shortage из seq 4.
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000001', [[$this->productA(), 3]]);
        $original = $order->items()->sole();

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => 'out_of_stock']),
        ], 'msg-17-partial'));

        $this->assertDatabaseHas('erp_processed_messages', ['message_id' => 'msg-17-partial']);
        $this->assertDatabaseMissing('erp_validation_errors', ['message_id' => 'msg-17-partial']);

        $items = $order->items()->orderBy('line_number')->get();
        $this->assertCount(2, $items, 'хвоста в заказе сайта не было — он создаётся, а не теряется');

        [$active, $tail] = [$items[0], $items[1]];

        $this->assertSame($original->id, $active->id, 'активная часть — та же строка сайта, обновлённая на месте');
        $this->assertSame(2, $active->quantity);
        $this->assertFalse($active->cancelled);
        $this->assertNull($active->erp_cancel_reason);
        $this->assertNull($active->cancelled_at);

        $this->assertSame(1, $tail->quantity);
        $this->assertTrue($tail->cancelled);
        $this->assertSame(OrderLineCancelReason::OUT_OF_STOCK, $tail->erp_cancel_reason);
        $this->assertTrue($tail->cancelled_at->is('2026-10-01 10:52:30'));
        // Причину из справочника отдела автоматика не проставляет — её выбирает менеджер.
        $this->assertNull($tail->cancel_reason_id);

        // Счёт в 1С уходит только на отгружаемое — и сумма заказа на сайте та же.
        $this->assertSame('2000.00', $order->fresh()->total_amount);

        // Журнал изменений читается как дробление, а не как «удалена + добавлена».
        $log = OrderChangeLog::query()->where('order_id', $order->id)->where('type', 'items_updated')->sole();
        $this->assertEqualsWithDelta(3000.0, (float) $log->old_total, 0.001);
        $this->assertEqualsWithDelta(2000.0, (float) $log->new_total, 0.001);
        $this->assertSame([], $log->getAttribute('changes')['removed']);
    }

    #[Test]
    public function client_cancel_keeps_the_line_and_stores_the_reason(): void
    {
        // Пример client_cancel из seq 4: единственная строка отменена клиентом сайта.
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000002', [[$this->productA(), 1]]);
        $original = $order->items()->sole();

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 1, cancelled: true, extra: ['cancel_reason' => 'client']),
        ], 'msg-17-client'));

        $item = $order->items()->sole();

        $this->assertSame($original->id, $item->id);
        $this->assertTrue($item->cancelled);
        $this->assertSame(OrderLineCancelReason::CLIENT, $item->erp_cancel_reason);
        $this->assertSame('0.00', $order->fresh()->total_amount);
    }

    #[Test]
    public function every_agreed_value_is_stored_as_is(): void
    {
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000003', [[$this->productA(), 1]]);

        foreach (['out_of_stock', 'shortage', 'client', 'reserve_expired', 'other'] as $value) {
            $this->fire($this->orderUpdated($order, [
                $this->line(1, 1, cancelled: true, extra: ['cancel_reason' => $value]),
            ], 'msg-17-value-'.$value));

            $this->assertSame($value, $order->items()->sole()->erp_cancel_reason->value);
        }
    }

    #[Test]
    public function unknown_value_is_accepted_and_treated_as_other(): void
    {
        Log::spy();

        $order = $this->siteOrder('17000000-0000-4000-a000-000000000004', [[$this->productA(), 1]]);

        // Новая причина в 1С не должна останавливать приём заказа: в схеме поле не enum.
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 1, cancelled: true, extra: ['cancel_reason' => 'marketplace_return']),
        ], 'msg-17-unknown'));

        $this->assertDatabaseHas('erp_processed_messages', ['message_id' => 'msg-17-unknown']);
        $this->assertDatabaseMissing('erp_validation_errors', ['message_id' => 'msg-17-unknown']);

        $item = $order->items()->sole();
        $this->assertTrue($item->cancelled);
        $this->assertSame(OrderLineCancelReason::OTHER, $item->erp_cancel_reason);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'неизвестная причина отмены')
                && ($context['cancel_reason'] ?? null) === 'marketplace_return')
            ->once();
    }

    #[Test]
    public function message_without_the_field_is_valid_and_leaves_the_reason_empty(): void
    {
        // Сообщение прежнего формата: 1С включает причину поставкой, в очереди могут лежать старые.
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000005', [[$this->productA(), 3]]);

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true),
        ], 'msg-17-legacy'));

        $this->assertDatabaseHas('erp_processed_messages', ['message_id' => 'msg-17-legacy']);
        $this->assertDatabaseMissing('erp_validation_errors', ['message_id' => 'msg-17-legacy']);

        $tail = $order->items()->where('cancelled', true)->sole();
        $this->assertNull($tail->erp_cancel_reason, '«1С не сказала» — не то же, что «1С сказала: другое»');
        $this->assertNotNull($tail->cancelled_at);

        // null — тоже «не передана».
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => null]),
        ], 'msg-17-legacy-null'));

        $this->assertDatabaseMissing('erp_validation_errors', ['message_id' => 'msg-17-legacy-null']);
        $this->assertNull($order->items()->where('cancelled', true)->sole()->erp_cancel_reason);
    }

    #[Test]
    public function reason_on_an_active_line_is_ignored(): void
    {
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000006', [[$this->productA(), 2]]);

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false, extra: ['cancel_reason' => 'out_of_stock']),
        ], 'msg-17-active'));

        $this->assertDatabaseHas('erp_processed_messages', ['message_id' => 'msg-17-active']);

        $item = $order->items()->sole();
        $this->assertFalse($item->cancelled);
        $this->assertNull($item->erp_cancel_reason);
        $this->assertSame('2000.00', $order->fresh()->total_amount);
    }

    #[Test]
    public function non_string_reason_fails_schema_validation(): void
    {
        Log::spy();

        $order = $this->siteOrder('17000000-0000-4000-a000-000000000007', [[$this->productA(), 1]]);

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 1, cancelled: true, extra: ['cancel_reason' => 5]),
        ], 'msg-17-not-a-string'));

        $this->assertDatabaseHas('erp_validation_errors', ['message_id' => 'msg-17-not-a-string']);
        $this->assertFalse($order->items()->sole()->cancelled, 'невалидное сообщение не применяется');
    }

    #[Test]
    public function reason_can_be_refined_without_moving_the_date_and_is_wiped_when_line_returns(): void
    {
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000008', [[$this->productA(), 3]]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true),
        ], 'msg-17-refine-1'));

        // Следующее сообщение уточняет причину уже отменённой строки.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => 'shortage']),
        ], 'msg-17-refine-2'));

        $tail = $order->items()->where('cancelled', true)->sole();
        $this->assertSame(OrderLineCancelReason::SHORTAGE, $tail->erp_cancel_reason);
        $this->assertTrue($tail->cancelled_at->is('2026-10-01 10:00:00'), 'дата отмены в журнале не сдвигается');

        // 1С вернула строку в работу: это больше не недобор.
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: false),
        ], 'msg-17-refine-3'));

        $returned = $order->items()->where('line_number', 2)->sole();
        $this->assertSame($tail->id, $returned->id);
        $this->assertFalse($returned->cancelled);
        $this->assertNull($returned->erp_cancel_reason);
        $this->assertNull($returned->cancelled_at);
    }

    #[Test]
    public function tail_that_shifts_numbering_does_not_move_other_products_lines(): void
    {
        // Хвост товара А встал строкой 2, товар Б переехал со строки 2 на строку 3.
        $order = $this->siteOrder('17000000-0000-4000-a000-000000000009', [
            [$this->productA(), 3],
            [$this->productB(), 4],
        ]);

        $lineA = $order->items()->where('line_number', 1)->sole();
        $lineB = $order->items()->where('line_number', 2)->sole();

        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => 'out_of_stock']),
            $this->line(3, 4, cancelled: false, productUuid: self::PRODUCT_B),
        ], 'msg-17-shift'));

        $items = $order->items()->orderBy('line_number')->get();
        $this->assertCount(3, $items);

        $this->assertSame($lineA->id, $items[0]->id);
        $this->assertSame(2, $items[0]->quantity);

        $this->assertNotSame($lineB->id, $items[1]->id, 'хвост — новая строка, а не переписанная строка товара Б');
        $this->assertSame($lineA->product_id, $items[1]->product_id);
        $this->assertTrue($items[1]->cancelled);

        $this->assertSame($lineB->id, $items[2]->id, 'строка товара Б найдена по товару и сохранила id');
        $this->assertSame($lineB->product_id, $items[2]->product_id);
        $this->assertSame(4, $items[2]->quantity);
        $this->assertFalse($items[2]->cancelled);

        $this->assertSame('6000.00', $order->fresh()->total_amount);
    }

    #[Test]
    public function defect_batch_links_stay_with_their_products_when_a_line_is_split(): void
    {
        $productA = $this->productA();
        $productB = $this->productB();

        $batchA = ProductDefect::factory()->for($productA)->sellable(1000)->create(['quantity' => 3, 'defect_description' => 'Мятая коробка']);
        $batchB = ProductDefect::factory()->for($productB)->sellable(1000)->create(['quantity' => 4, 'defect_description' => 'Нет плёнки']);

        $order = $this->siteOrder('17000000-0000-4000-a000-000000000010', [
            [$productA, 3],
            [$productB, 4],
        ], ['type' => OrderType::DEFECT]);

        $order->items()->where('line_number', 1)->update(['product_defect_id' => $batchA->id, 'defect_description' => 'Мятая коробка']);
        $order->items()->where('line_number', 2)->update(['product_defect_id' => $batchB->id, 'defect_description' => 'Нет плёнки']);

        // Хвост занял номер строки товара Б — именно тот сдвиг, на котором привязки уезжали 09.09.2026.
        $this->fire($this->orderUpdated($order, [
            $this->line(1, 2, cancelled: false),
            $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => 'out_of_stock']),
            $this->line(3, 4, cancelled: false, productUuid: self::PRODUCT_B),
        ], 'msg-17-defect'));

        $items = $order->items()->orderBy('line_number')->get();
        $this->assertCount(3, $items);

        $this->assertSame($batchA->id, $items[0]->product_defect_id);
        $this->assertSame($batchA->id, $items[1]->product_defect_id, 'хвост наследует партию своего товара');
        $this->assertSame('Мятая коробка', $items[1]->defect_description);
        $this->assertSame($batchB->id, $items[2]->product_defect_id, 'партия товара Б не уехала на чужой артикул');
        $this->assertSame('Нет плёнки', $items[2]->defect_description);

        // Ни одна строка не ссылается на партию чужого товара — диагностика дрейфа из памяти проекта.
        $drift = OrderItem::query()
            ->join('product_defects', 'product_defects.id', '=', 'order_items.product_defect_id')
            ->where('order_items.order_id', $order->id)
            ->whereColumn('product_defects.product_id', '!=', 'order_items.product_id')
            ->count();
        $this->assertSame(0, $drift);

        // Отменённый хвост партию не резервирует: в резерве только активные 2 шт.
        $stock = app(DefectStockService::class);
        $this->assertSame(2, $stock->reserved($batchA));
        $this->assertSame(4, $stock->reserved($batchB));
    }

    #[Test]
    public function order_created_from_erp_accepts_the_reason_too(): void
    {
        $this->productA();
        $client = User::factory()->create(['erp_id' => '17000000-0000-4000-a000-0000000000c1']);

        $this->fire([
            'event' => 'order.created',
            'uuid' => '17000000-0000-4000-a000-000000000011',
            'message_id' => 'msg-17-created',
            'number' => '29УТ-014800',
            'partner_uuid' => $client->erp_id,
            'contractor' => [
                'uuid' => '17000000-0000-4000-a000-0000000000d1',
                'name' => 'ООО «Отель»',
                'tax_id' => '7799000017',
            ],
            'status' => 'pending_approval',
            'items' => [
                $this->line(1, 2, cancelled: false),
                $this->line(2, 1, cancelled: true, extra: ['cancel_reason' => 'out_of_stock']),
            ],
        ]);

        $this->assertDatabaseMissing('erp_validation_errors', ['message_id' => 'msg-17-created']);

        $order = Order::query()->where('uuid', '17000000-0000-4000-a000-000000000011')->sole();
        $tail = $order->items()->where('cancelled', true)->sole();

        $this->assertSame(OrderLineCancelReason::OUT_OF_STOCK, $tail->erp_cancel_reason);
        $this->assertSame('2000.00', $order->total_amount);
    }
}
