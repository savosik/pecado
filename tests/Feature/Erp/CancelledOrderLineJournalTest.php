<?php

namespace Tests\Feature\Erp;

use App\Enums\Order\CancelSource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Журнал недоборов начинается с сообщения шины: `order.updated` с отменённой
 * строкой обязан оставить в ней дату отмены.
 *
 * Проверяем три вещи, на которых журнал держится: дата ставится один раз,
 * повторная доставка её не сдвигает, а возврат строки в работу стирает
 * и дату, и разметку менеджера.
 */
class CancelledOrderLineJournalTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_UUID = '00000000-0000-4000-a000-0000000030a1';

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeJob(array $payload): ErpIncomingJob
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        return new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.orders',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function orderUpdatedMessage(string $uuid, array $items, string $messageId, ?int $revision = null): array
    {
        return [
            'event' => 'order.updated',
            'uuid' => $uuid,
            'message_id' => $messageId,
            'revision' => $revision,
            'number' => '29УТ-011777',
            'erp_updated_at' => '2026-08-14T12:00:00+03:00',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $lineNumber, bool $cancelled, float $finalPrice = 100.0, int $quantity = 5): array
    {
        return [
            'line_number' => $lineNumber,
            'product_uuid' => self::PRODUCT_UUID,
            'quantity' => $quantity,
            'base_price' => $finalPrice,
            'discount_percent' => 0,
            'final_price' => $finalPrice,
            'cancelled' => $cancelled,
        ];
    }

    private function makeOrder(string $uuid): Order
    {
        Product::factory()->create(['external_id' => self::PRODUCT_UUID]);

        $manager = PersonalManager::factory()->create(['is_active' => true]);
        $client = User::factory()->create(['personal_manager_id' => $manager->id]);

        return Order::factory()->create([
            'uuid' => $uuid,
            'user_id' => $client->id,
            'erp_number' => '29УТ-011777',
            'total_amount' => 0,
        ]);
    }

    #[Test]
    public function cancelled_line_gets_the_journal_date_and_stays_unmarked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $order = $this->makeOrder('11111111-0000-4000-a000-000000000001');

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: false),
            $this->line(2, cancelled: true),
        ], 'msg-journal-1'))->fire();

        $cancelled = OrderItem::where('order_id', $order->id)->where('cancelled', true)->sole();

        $this->assertTrue($cancelled->cancelled_at->is('2026-08-14 10:00:00'));
        // Причины 1С не передаёт: метку ставит менеджер, автоматика её не выдумывает.
        $this->assertNull($cancelled->cancel_source);
        $this->assertNull($cancelled->cancel_source_user_id);

        // Отменённая строка не входит в сумму заказа — считаем только живую.
        $this->assertSame('500.00', $order->fresh()->total_amount);
    }

    #[Test]
    public function redelivery_does_not_move_the_cancellation_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $order = $this->makeOrder('11111111-0000-4000-a000-000000000002');

        $payload = $this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-journal-redelivery-1');

        $this->makeJob($payload)->fire();

        Carbon::setTestNow(Carbon::parse('2026-08-15 18:30:00'));

        $payload['message_id'] = 'msg-journal-redelivery-2';
        $this->makeJob($payload)->fire();

        $cancelled = OrderItem::where('order_id', $order->id)->sole();

        $this->assertTrue($cancelled->cancelled_at->is('2026-08-14 10:00:00'));
    }

    #[Test]
    public function returning_the_line_to_work_clears_the_journal_entry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $order = $this->makeOrder('11111111-0000-4000-a000-000000000003');
        $marker = User::factory()->create();

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
        ], 'msg-journal-return-1'))->fire();

        $item = OrderItem::where('order_id', $order->id)->sole();
        $item->forceFill([
            'cancel_source' => CancelSource::WAREHOUSE,
            'cancel_source_user_id' => $marker->id,
            'cancel_source_at' => now(),
            'cancel_note' => 'мятая упаковка',
        ])->save();

        // 1С вернула строку в работу: недобора больше нет, в журнале ему не место.
        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: false),
        ], 'msg-journal-return-2', revision: 2))->fire();

        $item->refresh();

        $this->assertFalse($item->cancelled);
        $this->assertNull($item->cancelled_at);
        $this->assertNull($item->cancel_source);
        $this->assertNull($item->cancel_source_user_id);
        $this->assertNull($item->cancel_source_at);
        $this->assertNull($item->cancel_note);
    }

    #[Test]
    public function second_wave_dates_each_line_by_its_own_cancellation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $order = $this->makeOrder('11111111-0000-4000-a000-000000000004');

        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: false),
            $this->line(2, cancelled: true),
        ], 'msg-journal-wave-1', revision: 1))->fire();

        Carbon::setTestNow(Carbon::parse('2026-08-15 09:15:00'));

        // Вторая волна: 1С проверила сборку ещё раз и сняла вторую строку.
        $this->makeJob($this->orderUpdatedMessage($order->uuid, [
            $this->line(1, cancelled: true),
            $this->line(2, cancelled: true),
        ], 'msg-journal-wave-2', revision: 2))->fire();

        $items = OrderItem::where('order_id', $order->id)->orderBy('line_number')->get();

        $this->assertTrue($items[0]->cancelled_at->is('2026-08-15 09:15:00'));
        $this->assertTrue($items[1]->cancelled_at->is('2026-08-14 10:00:00'));
    }
}
