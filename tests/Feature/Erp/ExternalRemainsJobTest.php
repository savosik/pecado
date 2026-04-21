<?php

namespace Tests\Feature\Erp;

use App\Models\ErpProcessedMessage;
use App\Models\Product;
use App\Models\Warehouse;
use App\Queue\Jobs\ExternalRemainsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalRemainsJobTest extends TestCase
{
    use RefreshDatabase;

    private const TYUMEN_UUID = 'f8083799-0838-11e0-a1ea-505054503030';

    private const MSK_UUID = '40301d16-3847-11e1-8034-001e6711ed1d';

    private const OTHER_UUID = '1e2b130f-7a72-11e2-b0c0-001e6711ed1d';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('erp.external_remains.tyumen_warehouse_uuid', self::TYUMEN_UUID);
    }

    /**
     * Собрать envelope в том же формате, что приходит из external.remains_for_website.
     */
    private function envelope(string $productUuid, array $remains, ?string $productCode = null, ?string $messageUid = null): array
    {
        return [
            'service' => 'service-products',
            'uid' => $messageUid ?? (string) Str::uuid(),
            'created_timestamp' => 1776792661.84,
            'accepted_timestamp' => 1776792661.86,
            'exclude_subscribers' => ['epa-ut'],
            'event' => [
                'name' => 'product.quantity.updated',
                'payload' => [
                    'uid' => $productUuid,
                    'code' => $productCode ?? '0T-000'.random_int(10000, 99999),
                    'remains' => $remains,
                ],
            ],
        ];
    }

    /**
     * Фикстура remains[] по нескольким складам — как в реальном сообщении.
     */
    private function remainsAcrossWarehouses(int $tyumenQty, int $tyumenReserve): array
    {
        return [
            [
                'warehouse_uid' => self::MSK_UUID,
                'quantity' => 100, 'reserve' => 10, 'total' => 100, 'expected' => 0, 'nds' => false,
                'updated_at' => '2026-04-20 10:00:00',
            ],
            [
                'warehouse_uid' => self::OTHER_UUID,
                'quantity' => 5, 'reserve' => 0, 'total' => 5, 'expected' => 0, 'nds' => false,
                'updated_at' => '2026-04-20 10:00:00',
            ],
            [
                'warehouse_uid' => self::TYUMEN_UUID,
                'quantity' => $tyumenQty, 'reserve' => $tyumenReserve,
                'total' => $tyumenQty, 'expected' => 0, 'nds' => false,
                'updated_at' => '2026-04-20 10:00:00',
            ],
        ];
    }

    private function makeJob(array $envelope): ExternalRemainsJob
    {
        $rawBody = json_encode($envelope);

        $container = app();
        $queue = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class);

        $amqp = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqp->method('getBody')->willReturn($rawBody);
        $amqp->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        return new ExternalRemainsJob(
            $container,
            $queue,
            $amqp,
            'rabbitmq-external-remains',
            'external.remains_for_website',
        );
    }

    #[Test]
    public function tyumen_stock_is_updated_with_available_quantity(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-001']);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $this->makeJob($this->envelope('prod-uuid-001', $this->remainsAcrossWarehouses(10, 3)))->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $tyumen->id,
            'quantity' => 7,
        ]);
    }

    #[Test]
    public function other_warehouses_are_not_touched(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-002']);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);
        $msk = Warehouse::factory()->create(['external_id' => self::MSK_UUID]);

        // Заранее существующий остаток по МСК — должен остаться нетронутым.
        $product->warehouses()->attach($msk->id, ['quantity' => 42]);

        $this->makeJob($this->envelope('prod-uuid-002', $this->remainsAcrossWarehouses(5, 0)))->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $tyumen->id, 'quantity' => 5,
        ]);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $msk->id, 'quantity' => 42,
        ]);
    }

    #[Test]
    public function reserve_exceeding_quantity_clamps_to_zero(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-003']);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $this->makeJob($this->envelope('prod-uuid-003', $this->remainsAcrossWarehouses(2, 10)))->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $tyumen->id,
            'quantity' => 0,
        ]);
    }

    #[Test]
    public function product_is_found_by_code_when_uid_does_not_match(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'some-other-uuid',
            'code' => '0T-12345',
        ]);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $envelope = $this->envelope(
            productUuid: 'unknown-uid-here',
            remains: $this->remainsAcrossWarehouses(8, 1),
            productCode: '0T-12345',
        );

        $this->makeJob($envelope)->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $tyumen->id,
            'quantity' => 7,
        ]);
    }

    #[Test]
    public function unknown_product_is_silently_ignored(): void
    {
        Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $this->makeJob($this->envelope('nonexistent-product', $this->remainsAcrossWarehouses(10, 0)))->fire();

        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function missing_tyumen_warehouse_is_silently_ignored(): void
    {
        Product::factory()->create(['external_id' => 'prod-uuid-004']);
        // Склад Тюмень НЕ создаём.

        $this->makeJob($this->envelope('prod-uuid-004', $this->remainsAcrossWarehouses(10, 0)))->fire();

        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function missing_tyumen_in_remains_is_skipped(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-005']);
        Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        // В remains[] нет записи по Тюмени — только другие склады.
        $remains = [
            [
                'warehouse_uid' => self::MSK_UUID,
                'quantity' => 50, 'reserve' => 0, 'total' => 50, 'expected' => 0, 'nds' => false,
                'updated_at' => '2026-04-20 10:00:00',
            ],
        ];

        $this->makeJob($this->envelope('prod-uuid-005', $remains))->fire();

        $this->assertDatabaseMissing('product_warehouse', ['product_id' => $product->id]);
    }

    #[Test]
    public function duplicate_message_is_ignored_by_idempotency(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-006']);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $messageUid = '0b3a6e11-0a77-4de7-b7c8-1fe0490288ce';

        $first = $this->envelope('prod-uuid-006', $this->remainsAcrossWarehouses(10, 2), messageUid: $messageUid);
        $this->makeJob($first)->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $tyumen->id, 'quantity' => 8,
        ]);

        // Дубль с тем же envelope.uid, но другими цифрами — не должен обновить.
        $duplicate = $this->envelope('prod-uuid-006', $this->remainsAcrossWarehouses(999, 0), messageUid: $messageUid);
        $this->makeJob($duplicate)->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $tyumen->id, 'quantity' => 8,
        ]);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'external-remains:'.$messageUid,
            'event' => 'product.quantity.updated',
        ]);
    }

    #[Test]
    public function unknown_event_name_is_deleted_without_error(): void
    {
        Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        $envelope = $this->envelope('prod-uuid-007', $this->remainsAcrossWarehouses(10, 0));
        $envelope['event']['name'] = 'something.else';

        $this->makeJob($envelope)->fire();

        $this->assertDatabaseCount('product_warehouse', 0);
        $this->assertDatabaseCount('erp_processed_messages', 0);
    }

    #[Test]
    public function invalid_json_is_handled_gracefully(): void
    {
        $container = app();
        $queue = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class);

        $amqp = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqp->method('getBody')->willReturn('{not a valid json');
        $amqp->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        $job = new ExternalRemainsJob(
            $container,
            $queue,
            $amqp,
            'rabbitmq-external-remains',
            'external.remains_for_website',
        );

        $job->fire();

        $this->assertDatabaseCount('product_warehouse', 0);
        $this->assertDatabaseCount('erp_processed_messages', 0);
    }

    #[Test]
    public function repeated_processing_with_different_uids_updates_product(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-uuid-008']);
        $tyumen = Warehouse::factory()->create(['external_id' => self::TYUMEN_UUID]);

        // Первое сообщение — остаток 5.
        $this->makeJob($this->envelope('prod-uuid-008', $this->remainsAcrossWarehouses(5, 0)))->fire();

        // Второе сообщение (другой envelope.uid) — остаток обновился до 20.
        $this->makeJob($this->envelope('prod-uuid-008', $this->remainsAcrossWarehouses(20, 0)))->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $tyumen->id,
            'quantity' => 20,
        ]);
        $this->assertDatabaseCount('erp_processed_messages', 2);
        $this->assertEquals(2, ErpProcessedMessage::where('event', 'product.quantity.updated')->count());
    }
}
