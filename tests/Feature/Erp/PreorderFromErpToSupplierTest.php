<?php

namespace Tests\Feature\Erp;

use App\Enums\OrderType;
use App\Jobs\SendPreorderToSupplierJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierPreorderRequest;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use App\Services\Erp\Handlers\HandleOrderCreated;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v15.9: предзаказ, заведённый менеджером в 1С, сайт отправляет поставщику
 * на резерв — у 1С своего канала к поставщику нет.
 *
 * Единственный признак предзаказа — поле `type: "preorder"` в payload.
 */
class PreorderFromErpToSupplierTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_UUID = '550e8400-e29b-41d4-a716-446655440101';

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sex_opt.order_api_url', 'https://api.sex-opt.ru/customer/api/v1');
        config()->set('services.sex_opt.order_api_key', 'test-key');
        config()->set('services.sex_opt.preorder.enabled', true);
        config()->set('services.sex_opt.preorder.stock', 'tmn');
        config()->set('services.sex_opt.preorder.testmode', false);

        $this->user = User::factory()->create(['erp_id' => '550e8400-e29b-41d4-a716-446655440102']);
        $this->product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-446655440103',
            'code' => 'УТ-00007466',
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'event' => 'order.created',
            'message_id' => 'msg-preorder-from-erp',
            'uuid' => self::ORDER_UUID,
            'number' => '29УТ-000123',
            'status' => 'pending_approval',
            'type' => 'preorder',
            'partner_uuid' => $this->user->erp_id,
            'contractor' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440104',
                'tax_id' => '7710140679',
                'name' => 'ООО Клиент',
            ],
            'items' => [[
                'product_uuid' => $this->product->external_id,
                'quantity' => 4,
                'base_price' => 100,
                'final_price' => 100,
            ]],
        ], $override);
    }

    /**
     * Прогон сообщения через очередь целиком: JSON Schema → handler.
     */
    private function fireIncoming(array $payload): void
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
            'erp_in.orders'
        ))->fire();
    }

    #[Test]
    public function preorder_from_erp_passes_schema_and_is_queued_for_supplier(): void
    {
        Queue::fake();

        $this->fireIncoming($this->payload());

        // Сообщение прошло валидацию по JSON Schema и обработалось
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-preorder-from-erp',
            'event' => 'order.created',
        ]);

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();
        $this->assertSame(OrderType::PREORDER, $order->type);

        Queue::assertPushed(SendPreorderToSupplierJob::class,
            fn (SendPreorderToSupplierJob $job) => $job->orderId === $order->id);
    }

    #[Test]
    public function order_without_type_is_not_sent_to_supplier(): void
    {
        Queue::fake();

        $payload = $this->payload();
        unset($payload['type']);

        app(HandleOrderCreated::class)->handle($payload);

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();
        $this->assertSame(OrderType::ORDER, $order->type);

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function preorder_returning_from_erp_roundtrip_is_not_sent_again(): void
    {
        Queue::fake();

        // Предзаказ, оформленный на сайте: поставщику он уже ушёл при оформлении
        Order::factory()->create([
            'uuid' => self::ORDER_UUID,
            'type' => OrderType::PREORDER,
            'user_id' => $this->user->id,
        ]);

        app(HandleOrderCreated::class)->handle($this->payload());

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function deleted_preorder_from_erp_is_not_sent_to_supplier(): void
    {
        Queue::fake();

        app(HandleOrderCreated::class)->handle($this->payload(['status' => 'Удалён']));

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function preorder_recovered_from_order_updated_is_sent_to_supplier(): void
    {
        Queue::fake();

        // 1С потеряла order.created — заказ восстанавливается из order.updated (v15.4)
        app(HandleOrderUpdated::class)->handle($this->payload(['event' => 'order.updated']));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();
        $this->assertSame(OrderType::PREORDER, $order->type);

        Queue::assertPushed(SendPreorderToSupplierJob::class,
            fn (SendPreorderToSupplierJob $job) => $job->orderId === $order->id);
    }

    #[Test]
    public function order_updated_does_not_turn_existing_order_into_preorder(): void
    {
        Queue::fake();

        app(HandleOrderCreated::class)->handle($this->payload(['type' => 'order']));
        app(HandleOrderUpdated::class)->handle($this->payload(['event' => 'order.updated']));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();
        $this->assertSame(OrderType::ORDER, $order->type, 'order.updated не меняет тип документа');

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function preorder_from_erp_reaches_supplier_with_erp_number_in_comment(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 314159],
                'transaction' => 'commit',
            ]),
        ]);

        app(HandleOrderCreated::class)->handle($this->payload());

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        // Очередь не подделываем: job отрабатывает синхронно (queue sync в тестах)
        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();

        $this->assertSame(SupplierPreorderRequest::STATUS_SUCCESS, $record->status);
        $this->assertSame('предзаказ 29УТ-000123 поставьте в резерв.', $record->comment);
        $this->assertSame('tmn', $record->stock);
        $this->assertSame('314159', $record->supplier_order_id);

        /** @var \Illuminate\Http\Client\Request $request */
        $request = Http::recorded()[0][0];
        $payload = json_decode($request->data()['post'], true);

        $this->assertSame(['УТ-00007466' => 4], $payload['data']['items']);
    }
}
