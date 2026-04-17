<?php

namespace Tests\Feature\Erp;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ErpProcessedMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ErpIncomingJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создать мок ErpIncomingJob из raw JSON.
     */
    private function makeJob(array $payload): ErpIncomingJob
    {
        $rawBody = json_encode($payload);

        $container = app();
        $rabbitmqQueue = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class);

        // Полноценный мок AMQPMessage
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn($rawBody);
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        return new ErpIncomingJob(
            $container,
            $rabbitmqQueue,
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.partners'
        );
    }

    #[Test]
    public function partner_created_via_incoming_queue_activates_user_in_v4(): void
    {
        // v4: partner.created — входящее событие (1С → Сайт).
        // HandlePartnerCreated активирует пользователя по email и привязывает erp_id.
        $user = User::factory()->create([
            'email' => 'partner-test@example.com',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        $job = $this->makeJob([
            'event' => 'partner.created',
            'uuid' => '00000000-0000-4000-a000-000000000045',
            'login' => 'partner-test@example.com',
            'name' => 'Test Partner',
            'email' => 'partner-test@example.com',
            'message_id' => 'msg-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $user->refresh();

        // v4: Пользователь активирован и привязан к ERP
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertEquals('00000000-0000-4000-a000-000000000045', $user->erp_id);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-001',
            'event' => 'partner.created',
        ]);
    }

    #[Test]
    public function partner_deleted_blocks_user_through_job(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'erp_id' => '00000000-0000-4000-a000-000000000046',
        ]);

        $job = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000046',
            'email' => $user->email,
            'message_id' => 'msg-002',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $user->refresh();

        $this->assertEquals(UserStatus::BLOCKED, $user->status);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-002',
            'event' => 'partner.deleted',
        ]);
    }

    #[Test]
    public function duplicate_message_is_ignored_by_idempotency(): void
    {
        $user = User::factory()->create([
            'email' => 'partner-idem@example.com',
            'status' => UserStatus::BLOCKED,
            'erp_id' => '00000000-0000-4000-a000-000000000004',
        ]);

        // Имитируем уже обработанное сообщение
        ErpProcessedMessage::create([
            'message_id' => 'msg-duplicate',
            'event' => 'partner.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'partner.created',
            'uuid' => '00000000-0000-4000-a000-00000000001b',
            'login' => 'partner-idem@example.com',
            'message_id' => 'msg-duplicate',
        ]);

        $job->fire();

        $user->refresh();

        // Статус НЕ должен измениться — дубликат проигнорирован
        $this->assertEquals(UserStatus::BLOCKED, $user->status);
        $this->assertEquals('00000000-0000-4000-a000-000000000004', $user->erp_id);
    }

    #[Test]
    public function unknown_event_type_is_logged_and_deleted(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'неизвестный тип события');
            });

        $job = $this->makeJob([
            'event' => 'unknown.event',
            'message_id' => 'msg-unknown',
        ]);

        $job->fire();

        $this->assertDatabaseMissing('erp_processed_messages', [
            'message_id' => 'msg-unknown',
        ]);
    }

    #[Test]
    public function invalid_json_is_handled_gracefully(): void
    {
        $container = app();
        $rabbitmqQueue = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class);

        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn('{invalid json');
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'невалидный JSON');
            });

        $job = new ErpIncomingJob(
            $container,
            $rabbitmqQueue,
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.partners'
        );

        $job->fire();
    }

    #[Test]
    public function message_without_event_field_is_deleted(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует поле event');
            });

        $job = $this->makeJob([
            'uuid' => '00000000-0000-4000-a000-000000000035',
            'login' => 'test@example.com',
        ]);

        $job->fire();
    }

    #[Test]
    public function message_without_message_id_still_processes(): void
    {
        // Используем partner.deleted (остаётся как входящее событие) вместо partner.created (v2: стало исходящим)
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'erp_id' => '00000000-0000-4000-a000-000000000047',
        ]);

        $job = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000047',
            'email' => $user->email,
            // no message_id
        ]);

        $job->fire();

        $user->refresh();

        $this->assertEquals(UserStatus::BLOCKED, $user->status);

        // Без message_id нет записи в erp_processed_messages
        $this->assertEquals(0, ErpProcessedMessage::count());
    }

    #[Test]
    public function full_cycle_partner_deleted(): void
    {
        // v2: partner.created теперь исходящее — тестируем только partner.deleted как входящее.
        $user = User::factory()->create([
            'email' => 'full-cycle@example.com',
            'status' => UserStatus::ACTIVE,
            'erp_id' => '00000000-0000-4000-a000-00000000000c',
        ]);

        // partner.deleted (1С → Сайт)
        $deletedJob = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000000c',
            'email' => 'full-cycle@example.com',
            'message_id' => 'msg-cycle-deleted',
        ]);
        $deletedJob->fire();

        $user->refresh();
        $this->assertEquals(UserStatus::BLOCKED, $user->status);

        // Дубль partner.deleted (тот же message_id) — не должен повторно обработаться
        $duplicateJob = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000000c',
            'email' => 'full-cycle@example.com',
            'message_id' => 'msg-cycle-deleted',
        ]);
        $duplicateJob->fire();

        $user->refresh();
        $this->assertEquals(UserStatus::BLOCKED, $user->status);
        $this->assertDatabaseCount('erp_processed_messages', 1);
    }

    // ========================================================
    // US-02: price.updated — синхронизация базовых цен
    // ========================================================

    #[Test]
    public function price_updated_changes_product_base_price_through_job(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000002e',
            'base_price' => 10000.00,
        ]);

        $job = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => '00000000-0000-4000-a000-00000000002e',
            'price' => 15000.00,
            'message_id' => 'msg-price-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $product->refresh();

        $this->assertEquals(15000.00, (float) $product->base_price);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-price-001',
            'event' => 'price.updated',
        ]);
    }

    #[Test]
    public function price_updated_idempotency_prevents_reprocessing(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000002d',
            'base_price' => 10000.00,
        ]);

        // Имитируем уже обработанное сообщение
        ErpProcessedMessage::create([
            'message_id' => 'msg-price-duplicate',
            'event' => 'price.updated',
            'processed_at' => now(),
        ]);

        // Первое обновление прошло ранее, теперь пытаемся повторить с другой ценой
        $job = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => '00000000-0000-4000-a000-00000000002d',
            'price' => 99999.00,
            'message_id' => 'msg-price-duplicate',
        ]);

        $job->fire();

        $product->refresh();

        // Цена НЕ должна измениться — дубликат проигнорирован
        $this->assertEquals(10000.00, (float) $product->base_price);
    }

    #[Test]
    public function price_updated_for_unknown_product_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => '00000000-0000-4000-a000-00000000001f',
            'price' => 15000.00,
            'message_id' => 'msg-price-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        // Сообщение должно быть помечено как обработанное
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-price-unknown',
            'event' => 'price.updated',
        ]);
    }

    #[Test]
    public function price_updated_multiple_products_independently(): void
    {
        $product1 = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000016',
            'base_price' => 5000.00,
        ]);

        $product2 = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000017',
            'base_price' => 8000.00,
        ]);

        // Обновляем цену первого товара
        $job1 = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000016',
            'price' => 6000.00,
            'message_id' => 'msg-multi-price-1',
        ]);
        $job1->fire();

        // Обновляем цену второго товара
        $job2 = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000017',
            'price' => 12000.00,
            'message_id' => 'msg-multi-price-2',
        ]);
        $job2->fire();

        $product1->refresh();
        $product2->refresh();

        $this->assertEquals(6000.00, (float) $product1->base_price);
        $this->assertEquals(12000.00, (float) $product2->base_price);
    }

    // ========================================================
    // US-04: exchange_rate.updated — синхронизация курсов валют
    // ========================================================

    #[Test]
    public function exchange_rate_updated_changes_currency_rate_through_job(): void
    {
        $currency = \App\Models\Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => null,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 5.00,
            'exchange_rate_date' => null,
        ]);

        $job = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.45,
            'base_currency_code' => 'RUB',
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $currency->refresh();

        $this->assertEquals(5.40, (float) $currency->official_rate);
        $this->assertEquals(1.01, (float) $currency->rate_coefficient);
        $this->assertEquals(5.45, (float) $currency->exchange_rate);
        $this->assertEquals('2026-02-16', $currency->exchange_rate_date->format('Y-m-d'));
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-exrate-001',
            'event' => 'exchange_rate.updated',
        ]);
    }

    #[Test]
    public function exchange_rate_updated_idempotency_prevents_reprocessing(): void
    {
        $currency = \App\Models\Currency::factory()->create([
            'code' => 'KZT',
            'exchange_rate' => 5.00,
        ]);

        // Имитируем уже обработанное сообщение
        ErpProcessedMessage::create([
            'message_id' => 'msg-exrate-duplicate',
            'event' => 'exchange_rate.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'rate' => 99.99,
            'message_id' => 'msg-exrate-duplicate',
        ]);

        $job->fire();

        $currency->refresh();

        // Курс НЕ должен измениться — дубликат проигнорирован
        $this->assertEquals(5.00, (float) $currency->exchange_rate);
    }

    #[Test]
    public function exchange_rate_updated_for_unknown_currency_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'USD',
            'rate' => 90.00,
            'base_currency_code' => 'RUB',
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        // Сообщение должно быть помечено как обработанное
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-exrate-unknown',
            'event' => 'exchange_rate.updated',
        ]);
    }

    #[Test]
    public function exchange_rate_updated_multiple_currencies_independently(): void
    {
        $kzt = \App\Models\Currency::factory()->create([
            'code' => 'KZT',
            'exchange_rate' => 5.00,
        ]);

        $byn = \App\Models\Currency::factory()->create([
            'code' => 'BYN',
            'exchange_rate' => 30.00,
        ]);

        // Обновляем курс KZT
        $job1 = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'rate' => 5.45,
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-kzt',
        ]);
        $job1->fire();

        // Обновляем курс BYN
        $job2 = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'BYN',
            'rate' => 28.50,
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-byn',
        ]);
        $job2->fire();

        $kzt->refresh();
        $byn->refresh();

        $this->assertEquals(5.45, (float) $kzt->exchange_rate);
        $this->assertEquals(28.50, (float) $byn->exchange_rate);
    }

    #[Test]
    public function full_exchange_rate_lifecycle_multiple_updates(): void
    {
        $currency = \App\Models\Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => 5.00,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 5.00,
            'exchange_rate_date' => '2026-01-01',
        ]);

        // 1. Первое обновление — с полными полями v2
        $job1 = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.45,
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-life-1',
        ]);
        $job1->fire();

        $currency->refresh();
        $this->assertEquals(5.40, (float) $currency->official_rate);
        $this->assertEquals(1.01, (float) $currency->rate_coefficient);
        $this->assertEquals(5.45, (float) $currency->exchange_rate);
        $this->assertEquals('2026-02-16', $currency->exchange_rate_date->format('Y-m-d'));

        // 2. Второе обновление — перезапись без истории
        $job2 = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.54,
            'rate_coefficient' => 1.011,
            'rate' => 5.60,
            'date' => '2026-03-01',
            'message_id' => 'msg-exrate-life-2',
        ]);
        $job2->fire();

        $currency->refresh();
        $this->assertEquals(5.54, (float) $currency->official_rate);
        $this->assertEquals(1.011, (float) $currency->rate_coefficient);
        $this->assertEquals(5.60, (float) $currency->exchange_rate);
        $this->assertEquals('2026-03-01', $currency->exchange_rate_date->format('Y-m-d'));

        // 3. Дубль первого обновления — не должен откатить курс (идемпотентность)
        $duplicateJob = $this->makeJob([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.45,
            'date' => '2026-02-16',
            'message_id' => 'msg-exrate-life-1',
        ]);
        $duplicateJob->fire();

        $currency->refresh();
        // Курс остался от второго обновления — дубликат проигнорирован
        $this->assertEquals(5.60, (float) $currency->exchange_rate);
        $this->assertEquals('2026-03-01', $currency->exchange_rate_date->format('Y-m-d'));
    }

    // ========================================================
    // US-05: stock.updated — синхронизация остатков
    // ========================================================

    #[Test]
    public function stock_updated_changes_product_quantity_through_job(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000038',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000003c',
        ]);

        $product->warehouses()->attach($warehouse->id, ['quantity' => 10]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000038',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000003c',
            'quantity' => 42,
            'message_id' => 'msg-stock-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 42,
        ]);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-stock-001',
            'event' => 'stock.updated',
        ]);
    }

    #[Test]
    public function stock_updated_creates_pivot_when_not_exists_through_job(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000039',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000003d',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000039',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000003d',
            'quantity' => 25,
            'message_id' => 'msg-stock-002',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
        ]);
    }

    #[Test]
    public function stock_updated_idempotency_prevents_reprocessing(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000036',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000003a',
        ]);

        $product->warehouses()->attach($warehouse->id, ['quantity' => 10]);

        // Имитируем уже обработанное сообщение
        ErpProcessedMessage::create([
            'message_id' => 'msg-stock-duplicate',
            'event' => 'stock.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000036',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000003a',
            'quantity' => 999,
            'message_id' => 'msg-stock-duplicate',
        ]);

        $job->fire();

        // Количество НЕ должно измениться — дубликат проигнорирован
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);
    }

    #[Test]
    public function stock_updated_for_unknown_product_completes_without_error(): void
    {
        $warehouse = Warehouse::factory()->create([
            'external_id' => '00000000-0000-4000-a000-00000000003b',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000022',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000003b',
            'quantity' => 10,
            'message_id' => 'msg-stock-unknown-prod',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-stock-unknown-prod',
            'event' => 'stock.updated',
        ]);
        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function stock_updated_for_unknown_warehouse_completes_without_error(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000037',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000037',
            'warehouse_uuid' => '00000000-0000-4000-a000-000000000023',
            'quantity' => 10,
            'message_id' => 'msg-stock-unknown-wh',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-stock-unknown-wh',
            'event' => 'stock.updated',
        ]);
        $this->assertDatabaseCount('product_warehouse', 0);
    }

    #[Test]
    public function stock_updated_multiple_products_independently(): void
    {
        $product1 = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000018']);
        $product2 = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000019']);
        $warehouse = Warehouse::factory()->create(['external_id' => '00000000-0000-4000-a000-00000000001a']);

        // Обновляем остаток первого товара
        $job1 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000018',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000001a',
            'quantity' => 50,
            'message_id' => 'msg-multi-stock-1',
        ]);
        $job1->fire();

        // Обновляем остаток второго товара
        $job2 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000019',
            'warehouse_uuid' => '00000000-0000-4000-a000-00000000001a',
            'quantity' => 75,
            'message_id' => 'msg-multi-stock-2',
        ]);
        $job2->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product1->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 50,
        ]);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product2->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 75,
        ]);
    }

    #[Test]
    public function full_stock_lifecycle_multiple_updates(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000011',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000012',
        ]);

        // 1. Первое обновление — создание записи
        $job1 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000011',
            'warehouse_uuid' => '00000000-0000-4000-a000-000000000012',
            'quantity' => 100,
            'message_id' => 'msg-stock-life-1',
        ]);
        $job1->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
        ]);

        // 2. Второе обновление — уменьшение остатка
        $job2 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000011',
            'warehouse_uuid' => '00000000-0000-4000-a000-000000000012',
            'quantity' => 60,
            'message_id' => 'msg-stock-life-2',
        ]);
        $job2->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 60,
        ]);

        // 3. Третье обновление — обнуление
        $job3 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000011',
            'warehouse_uuid' => '00000000-0000-4000-a000-000000000012',
            'quantity' => 0,
            'message_id' => 'msg-stock-life-3',
        ]);
        $job3->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 0,
        ]);

        // 4. Дубль первого обновления — не должен откатить остаток
        $duplicateJob = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => '00000000-0000-4000-a000-000000000011',
            'warehouse_uuid' => '00000000-0000-4000-a000-000000000012',
            'quantity' => 100,
            'message_id' => 'msg-stock-life-1',
        ]);
        $duplicateJob->fire();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 0,
        ]);
    }

    // ========================================================
    // US-07: order.created — upsert при повторной доставке
    // ========================================================

    #[Test]
    public function order_created_same_uuid_twice_upserts_and_no_error(): void
    {
        // Регрессионный тест: 1975 failed-сообщений из-за duplicate entry на orders.number.
        // Повторная доставка order.created с тем же uuid должна обновить заказ, не упасть.
        $payload = [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000000b01',
            'number' => 'ORD-UPSERT-001',
            'status' => 'pending',
            'type' => 'order',
            'message_id' => 'msg-order-upsert-first',
        ];

        $this->makeJob($payload)->fire();

        $this->assertDatabaseHas('orders', [
            'uuid' => '00000000-0000-4000-a000-000000000b01',
            'status' => 'pending',
        ]);

        // Вторая доставка того же сообщения — но с другим message_id (RabbitMQ retry)
        $retryPayload = array_merge($payload, [
            'status' => 'confirmed',
            'message_id' => 'msg-order-upsert-retry',
        ]);

        $this->makeJob($retryPayload)->fire();

        // Заказ один — дубля нет
        $this->assertEquals(1, Order::where('uuid', '00000000-0000-4000-a000-000000000b01')->count());

        $order = Order::where('uuid', '00000000-0000-4000-a000-000000000b01')->first();
        // Статус обновлён из второго payload
        $this->assertEquals('confirmed', $order->status->value);
    }

    #[Test]
    public function order_created_different_uuid_same_number_no_duplicate_error(): void
    {
        // 1С при retry генерирует новый uuid для того же заказа — number совпадает.
        // До фикса: SQLSTATE[23000] Duplicate entry на orders_number_unique.
        $firstPayload = [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000000b02',
            'number' => 'ORD-DUP-NUMBER-001',
            'status' => 'pending',
            'type' => 'order',
            'message_id' => 'msg-order-dupnumber-first',
        ];

        $this->makeJob($firstPayload)->fire();

        $secondPayload = [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000000b03',
            'number' => 'ORD-DUP-NUMBER-001',
            'status' => 'pending',
            'type' => 'order',
            'message_id' => 'msg-order-dupnumber-second',
        ];

        // Не должно бросить исключение
        $this->makeJob($secondPayload)->fire();

        // Оба заказа сохранены с одинаковым number
        $this->assertEquals(2, Order::where('number', 'ORD-DUP-NUMBER-001')->count());
    }

    #[Test]
    public function order_created_without_country_creates_company_with_default_ru(): void
    {
        // Регрессионный тест: 200 failed-сообщений из-за SQLSTATE[23000]: Column 'country' cannot be null.
        // order.created без поля country у контрагента — заказ создаётся, company.country = 'RU'.
        $payload = [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000000c01',
            'number' => 'ORD-NO-COUNTRY-001',
            'status' => 'pending',
            'type' => 'order',
            'message_id' => 'msg-order-no-country',
            'contractor' => [
                'tax_id' => '7701234567',
                'name' => 'ООО Без страны',
                // поле country намеренно отсутствует
            ],
        ];

        $this->makeJob($payload)->fire();

        $this->assertDatabaseHas('orders', [
            'uuid' => '00000000-0000-4000-a000-000000000c01',
            'number' => 'ORD-NO-COUNTRY-001',
        ]);

        $this->assertDatabaseHas('companies', [
            'tax_id' => '7701234567',
            'country' => 'RU',
        ]);
    }

    // ========================================================
    // US-06/07: order.updated / order.deleted — синхронизация заказов
    // ========================================================

    #[Test]
    public function order_updated_changes_order_status_through_job(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-00000000003f',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000003f',
            'status' => 'confirmed',
            'items' => [],
            'message_id' => 'msg-order-upd-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();

        $this->assertEquals('confirmed', $order->status->value);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-order-upd-001',
            'event' => 'order.updated',
        ]);
    }

    #[Test]
    public function order_updated_syncs_items_through_job(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product1 = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000024']);
        $product2 = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000025']);

        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        // Добавим старую позицию
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'name' => $product1->name,
            'quantity' => 10,
            'price' => 100,
            'subtotal' => 1000,
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'status' => 'confirmed',
            'items' => [
                [
                    'product_uuid' => '00000000-0000-4000-a000-000000000025',
                    'quantity' => 4,
                    'price' => 3000.00,
                ],
            ],
            'message_id' => 'msg-order-upd-items',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();

        // Старая позиция должна быть заменена на новую
        $this->assertCount(1, $order->items);
        $this->assertEquals($product2->id, $order->items->first()->product_id);
        $this->assertEquals(4, $order->items->first()->quantity);
        $this->assertEquals(3000.00, (float) $order->items->first()->price);
        $this->assertEquals(12000.00, (float) $order->total_amount);
    }

    #[Test]
    public function order_updated_idempotency_prevents_reprocessing(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000040',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-order-dup',
            'event' => 'order.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-000000000040',
            'status' => 'confirmed',
            'message_id' => 'msg-order-dup',
        ]);

        $job->fire();

        $order->refresh();
        $this->assertEquals('pending', $order->status->value);
    }

    #[Test]
    public function order_updated_for_unknown_order_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000001d',
            'status' => 'confirmed',
            'message_id' => 'msg-order-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-order-unknown',
            'event' => 'order.updated',
        ]);
    }

    #[Test]
    public function order_updated_with_ready_to_ship_status(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-00000000a25a',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'confirmed',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000a25a',
            'status' => 'ready_to_ship',
            'message_id' => 'msg-order-r2s-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();
        $this->assertEquals('ready_to_ship', $order->status->value);
        $this->assertDatabaseHas('orders', ['uuid' => '00000000-0000-4000-a000-00000000a25a', 'status' => 'ready_to_ship']);
    }

    #[Test]
    public function order_updated_with_closed_status(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000c15',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'ready_to_ship',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-000000000c15',
            'status' => 'closed',
            'message_id' => 'msg-order-cls-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();
        $this->assertEquals('closed', $order->status->value);
        $this->assertDatabaseHas('orders', ['uuid' => '00000000-0000-4000-a000-000000000c15', 'status' => 'closed']);
    }

    #[Test]
    public function order_deleted_soft_deletes_order_through_job(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-00000000003e',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'confirmed',
        ]);

        $job = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000003e',
            'message_id' => 'msg-order-del-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();

        $this->assertEquals('deleted', $order->status->value);
        $this->assertNull($order->deleted_at, 'Заказ не должен быть soft-deleted — остаётся как лог');
        $this->assertDatabaseHas('orders', ['uuid' => '00000000-0000-4000-a000-00000000003e', 'status' => 'deleted']);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-order-del-001',
            'event' => 'order.deleted',
        ]);
    }

    #[Test]
    public function order_deleted_for_unknown_order_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000001c',
            'message_id' => 'msg-order-del-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-order-del-unknown',
            'event' => 'order.deleted',
        ]);
    }

    // ========================================================
    // US-08: return.updated / return.deleted — синхронизация возвратов
    // ========================================================

    #[Test]
    public function return_updated_changes_return_status_through_job(): void
    {
        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000043',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000043',
            'status' => 'approved',
            'message_id' => 'msg-return-upd-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $return->refresh();

        $this->assertEquals('approved', $return->status->value);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-return-upd-001',
            'event' => 'return.updated',
        ]);
    }

    #[Test]
    public function return_updated_idempotency_prevents_reprocessing(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000044',
            'status' => 'pending',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-return-dup',
            'event' => 'return.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000044',
            'status' => 'approved',
            'message_id' => 'msg-return-dup',
        ]);

        $job->fire();

        $return->refresh();
        $this->assertEquals('pending', $return->status->value);
    }

    #[Test]
    public function return_updated_for_unknown_return_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000021',
            'status' => 'approved',
            'message_id' => 'msg-return-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-return-unknown',
            'event' => 'return.updated',
        ]);
    }

    #[Test]
    public function return_deleted_soft_deletes_return_through_job(): void
    {
        $return = ProductReturn::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000042',
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'return.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000042',
            'message_id' => 'msg-return-del-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertSoftDeleted('returns', ['uuid' => '00000000-0000-4000-a000-000000000042']);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-return-del-001',
            'event' => 'return.deleted',
        ]);
    }

    #[Test]
    public function return_deleted_for_unknown_return_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'return.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000020',
            'message_id' => 'msg-return-del-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-return-del-unknown',
            'event' => 'return.deleted',
        ]);
    }

    // ========================================================
    // US-10: balance.updated — синхронизация баланса
    // ========================================================

    #[Test]
    public function balance_updated_changes_user_balance_through_job(): void
    {
        $user = User::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-000000000001',
        ]);

        $job = $this->makeJob([
            'event' => 'balance.updated',
            'partner_uuid' => '00000000-0000-4000-a000-000000000001',
            'contractors' => [
                [
                    'tax_id' => '1234567890',
                    'current_balance' => -125000.00,
                    'overdue_debt' => 50000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => '00000000-0000-4000-a000-000000000030', 'amount' => 50000.00, 'due_date' => '2026-01-15'],
                    ],
                ],
            ],
            'updated_at' => '2026-02-16T10:00:00',
            'message_id' => 'msg-balance-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('contractor_balances', [
            'user_id' => $user->id,
            'tax_id' => '1234567890',
            'current_balance' => -125000.00,
            'overdue_debt' => 50000.00,
        ]);

        $balance = ContractorBalance::where('user_id', $user->id)->first();
        $this->assertEquals('2026-02-16', $balance->balance_erp_updated_at->format('Y-m-d'));
        $this->assertCount(1, $balance->overdueDetails);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-balance-001',
            'event' => 'balance.updated',
        ]);
    }

    #[Test]
    public function balance_updated_overwrites_existing_balance_through_job(): void
    {
        $user = User::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-000000000002',
        ]);
        ContractorBalance::create([
            'user_id' => $user->id,
            'tax_id' => '9876543210',
            'current_balance' => -50000.00,
            'overdue_debt' => 10000.00,
        ]);

        $job = $this->makeJob([
            'event' => 'balance.updated',
            'partner_uuid' => '00000000-0000-4000-a000-000000000002',
            'contractors' => [
                [
                    'tax_id' => '9876543210',
                    'current_balance' => -200000.00,
                    'overdue_debt' => 75000.00,
                    'overdue_details' => [],
                ],
            ],
            'message_id' => 'msg-balance-002',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $balance = ContractorBalance::where('user_id', $user->id)->first();
        $this->assertEquals(-200000.00, (float) $balance->current_balance);
        $this->assertEquals(75000.00, (float) $balance->overdue_debt);

        // Должен быть только один баланс по этому ИНН
        $this->assertCount(1, ContractorBalance::where('user_id', $user->id)->get());
    }

    #[Test]
    public function balance_updated_idempotency_prevents_reprocessing(): void
    {
        $user = User::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-000000000003',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-balance-dup',
            'event' => 'balance.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'balance.updated',
            'partner_uuid' => '00000000-0000-4000-a000-000000000003',
            'contractors' => [
                [
                    'tax_id' => '9999999999',
                    'current_balance' => -999999.00,
                    'overdue_debt' => 0,
                    'overdue_details' => [],
                ],
            ],
            'message_id' => 'msg-balance-dup',
        ]);

        $job->fire();

        // Баланс не должен быть создан — дубликат проигнорирован
        $this->assertDatabaseMissing('contractor_balances', [
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function balance_updated_for_unknown_partner_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'balance.updated',
            'partner_uuid' => '00000000-0000-4000-a000-00000000001e',
            'current_balance' => -50000.00,
            'overdue_debt' => 10000.00,
            'message_id' => 'msg-balance-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-balance-unknown',
            'event' => 'balance.updated',
        ]);
    }

    // ========================================================
    // US-06/07: полный жизненный цикл заказа
    // ========================================================

    #[Test]
    public function full_order_lifecycle_updated_then_deleted(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-00000000000e']);

        $order = Order::factory()->create([
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        // 1. order.updated — подтверждение + добавление позиций
        $updateJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'status' => 'confirmed',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-00000000000e', 'quantity' => 5, 'price' => 3000.00],
            ],
            'message_id' => 'msg-life-ord-upd',
        ]);
        $updateJob->fire();

        $order->refresh();
        $this->assertEquals('confirmed', $order->status->value);
        $this->assertCount(1, $order->items);

        // 2. order.deleted — отмена
        $deleteJob = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'message_id' => 'msg-life-ord-del',
        ]);
        $deleteJob->fire();

        $order->refresh();
        $this->assertEquals('deleted', $order->status->value);
        $this->assertNull($order->deleted_at, 'Заказ не должен быть soft-deleted — остаётся как лог');
        $this->assertDatabaseHas('orders', ['uuid' => '00000000-0000-4000-a000-00000000000f', 'status' => 'deleted']);

        // 3. Дубль order.updated — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'status' => 'confirmed',
            'message_id' => 'msg-life-ord-upd',
        ]);
        $dupJob->fire();

        // Статус не должен измениться — дубликат
        $order->refresh();
        $this->assertEquals('deleted', $order->status->value);
    }

    // ========================================================
    // US-08: full_return_lifecycle — полный цикл возвратов
    // ========================================================

    #[Test]
    public function full_return_lifecycle_updated_then_deleted(): void
    {
        $user = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000013']);
        $return = ProductReturn::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000014',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // 1. return.updated — смена статуса на approved
        $updJob = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000014',
            'status' => 'approved',
            'message_id' => 'msg-ret-life-upd',
            'timestamp' => now()->toIso8601String(),
        ]);
        $updJob->fire();

        $return->refresh();
        $this->assertEquals('approved', $return->status->value);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ret-life-upd',
            'event' => 'return.updated',
        ]);

        // 2. return.deleted — soft-delete
        $delJob = $this->makeJob([
            'event' => 'return.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000014',
            'message_id' => 'msg-ret-life-del',
            'timestamp' => now()->toIso8601String(),
        ]);
        $delJob->fire();

        $this->assertSoftDeleted('returns', ['uuid' => '00000000-0000-4000-a000-000000000014']);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ret-life-del',
            'event' => 'return.deleted',
        ]);

        // 3. Дубль return.updated (тот же message_id) — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000014',
            'status' => 'completed',
            'message_id' => 'msg-ret-life-upd',
        ]);
        $dupJob->fire();

        // Статус остался approved (soft-deleted, но данные в БД)
        $return = ProductReturn::withTrashed()->where('uuid', '00000000-0000-4000-a000-000000000014')->first();
        $this->assertEquals('approved', $return->status->value);
        $this->assertNotNull($return->deleted_at);
    }

    // ========================================================
    // US-09: shipment.created / shipment.updated / shipment.deleted
    // ========================================================

    #[Test]
    public function shipment_created_creates_shipment_through_job(): void
    {
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-00000000000d']);
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1234567890',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000031',
            'tax_id' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'KZT',
            'items' => [
                [
                    'product_uuid' => '00000000-0000-4000-a000-00000000000d',
                    'quantity' => 10,
                    'price' => 3000.00,
                ],
            ],
            'message_id' => 'msg-ship-created-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000031',
            'tax_id' => '1234567890',
            'status' => 'completed',
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $shipment = \App\Models\Shipment::where('uuid', '00000000-0000-4000-a000-000000000031')->first();
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(30000.00, (float) $shipment->total_amount);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ship-created-001',
            'event' => 'shipment.created',
        ]);
    }

    #[Test]
    public function shipment_updated_changes_status_through_job(): void
    {
        \App\Models\Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000032',
            'status' => 'new',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.updated',
            'uuid' => '00000000-0000-4000-a000-000000000032',
            'status' => 'completed',
            'message_id' => 'msg-ship-updated-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000032',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ship-updated-001',
            'event' => 'shipment.updated',
        ]);
    }

    #[Test]
    public function shipment_deleted_soft_deletes_through_job(): void
    {
        \App\Models\Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000033',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.deleted',
            'uuid' => '00000000-0000-4000-a000-000000000033',
            'message_id' => 'msg-ship-deleted-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertSoftDeleted('shipments', ['uuid' => '00000000-0000-4000-a000-000000000033']);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ship-deleted-001',
            'event' => 'shipment.deleted',
        ]);
    }

    #[Test]
    public function shipment_created_idempotency_prevents_reprocessing(): void
    {
        ErpProcessedMessage::create([
            'message_id' => 'msg-ship-dup-001',
            'event' => 'shipment.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000034',
            'tax_id' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
            'message_id' => 'msg-ship-dup-001',
        ]);

        $job->fire();

        // Реализация не должна быть создана — дубликат проигнорирован
        $this->assertDatabaseMissing('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000034',
        ]);
    }

    #[Test]
    public function full_shipment_lifecycle_created_updated_deleted(): void
    {
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000010']);

        // 1. shipment.created
        $createJob = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-00000000002f',
            'tax_id' => '5555555555',
            'date' => '2026-02-16',
            'status' => 'new',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000000010', 'quantity' => 5, 'price' => 1000.00],
            ],
            'message_id' => 'msg-ship-life-create',
            'timestamp' => now()->toIso8601String(),
        ]);
        $createJob->fire();

        $shipment = \App\Models\Shipment::where('uuid', '00000000-0000-4000-a000-00000000002f')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals('new', $shipment->status);
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(5000.00, (float) $shipment->total_amount);

        // 2. shipment.updated — изменение статуса и позиций
        $updateJob = $this->makeJob([
            'event' => 'shipment.updated',
            'uuid' => '00000000-0000-4000-a000-00000000002f',
            'status' => 'completed',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000000010', 'quantity' => 10, 'price' => 1500.00],
            ],
            'message_id' => 'msg-ship-life-update',
            'timestamp' => now()->toIso8601String(),
        ]);
        $updateJob->fire();

        $shipment->refresh();
        $this->assertEquals('completed', $shipment->status);
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(15000.00, (float) $shipment->total_amount);

        // 3. shipment.deleted
        $deleteJob = $this->makeJob([
            'event' => 'shipment.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000002f',
            'message_id' => 'msg-ship-life-delete',
            'timestamp' => now()->toIso8601String(),
        ]);
        $deleteJob->fire();

        $this->assertSoftDeleted('shipments', ['uuid' => '00000000-0000-4000-a000-00000000002f']);

        // 4. Дубль shipment.created (тот же message_id) — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-00000000002f',
            'tax_id' => '5555555555',
            'items' => [],
            'message_id' => 'msg-ship-life-create',
        ]);
        $dupJob->fire();

        // Проверяем что реализация осталась soft-deleted
        $shipment = \App\Models\Shipment::withTrashed()->where('uuid', '00000000-0000-4000-a000-00000000002f')->first();
        $this->assertNotNull($shipment->deleted_at);
    }

    #[Test]
    public function shipment_created_with_negative_discount_passes_schema_validation(): void
    {
        // Регрессионный тест: до v12.7.4 схема требовала minimum: 0 для discount_percent
        // и auto_discount_percent, что блокировало 15 сообщений от 1С.
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-000000000a01']);

        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000a02',
            'message_id' => 'msg-ship-neg-disc-001',
            'tax_id' => '1234567890',
            'date' => '2026-04-16',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                [
                    'product_uuid' => '00000000-0000-4000-a000-000000000a01',
                    'quantity' => 3,
                    'price' => 2000.00,
                    'auto_discount_percent' => -15,
                    'discount_percent' => -10,
                    'total' => 6900.00,
                ],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000a02',
        ]);

        $shipment = \App\Models\Shipment::where('uuid', '00000000-0000-4000-a000-000000000a02')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals(-15.00, (float) $shipment->items->first()->auto_discount_percent);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ship-neg-disc-001',
            'event' => 'shipment.created',
        ]);
    }

    // ========================================================
    // US-13: category.* — синхронизация категорий
    // ========================================================

    #[Test]
    public function category_created_creates_category_in_database(): void
    {
        $job = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-00000000000b',
            'parent_uuid' => null,
            'name' => 'Бельё и одежда',
            'is_active' => true,
            'message_id' => 'msg-cat-created-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-00000000000b',
            'name' => 'Бельё и одежда',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-cat-created-001',
            'event' => 'category.created',
        ]);
    }

    #[Test]
    public function category_created_with_parent_links_to_parent(): void
    {
        // Сначала создаём родительскую категорию
        $parentJob = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000009',
            'name' => 'Корневая категория',
            'is_active' => true,
            'message_id' => 'msg-cat-parent-001',
        ]);
        $parentJob->fire();

        $parent = \App\Models\Category::where('uuid', '00000000-0000-4000-a000-000000000009')->first();
        $this->assertNotNull($parent);

        // Теперь создаём дочернюю категорию
        $childJob = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000006',
            'parent_uuid' => '00000000-0000-4000-a000-000000000009',
            'name' => 'Дочерняя категория',
            'is_active' => true,
            'message_id' => 'msg-cat-child-001',
        ]);
        $childJob->fire();

        $child = \App\Models\Category::where('uuid', '00000000-0000-4000-a000-000000000006')->first();
        $this->assertNotNull($child);
        $this->assertEquals($parent->id, $child->parent_id);
    }

    #[Test]
    public function category_updated_updates_existing_category(): void
    {
        // Создаём исходную категорию
        $createJob = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-00000000000a',
            'name' => 'Старое название',
            'is_active' => true,
            'message_id' => 'msg-cat-upd-create',
        ]);
        $createJob->fire();

        // Обновляем через category.updated
        $updateJob = $this->makeJob([
            'event' => 'category.updated',
            'uuid' => '00000000-0000-4000-a000-00000000000a',
            'name' => 'Новое название',
            'is_active' => true,
            'message_id' => 'msg-cat-upd-update',
        ]);
        $updateJob->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-00000000000a',
            'name' => 'Новое название',
            'is_active' => true,
        ]);
        // Убедимся, что нет дублей
        $this->assertEquals(1, \App\Models\Category::where('uuid', '00000000-0000-4000-a000-00000000000a')->count());
    }

    #[Test]
    public function category_created_idempotency_prevents_duplicate(): void
    {
        ErpProcessedMessage::create([
            'message_id' => 'msg-cat-dup-001',
            'event' => 'category.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000007',
            'name' => 'Дубликат категории',
            'message_id' => 'msg-cat-dup-001',
        ]);
        $job->fire();

        $this->assertDatabaseMissing('categories', ['uuid' => '00000000-0000-4000-a000-000000000007']);
    }

    #[Test]
    public function category_created_without_required_fields_is_skipped(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'payload не соответствует JSON Schema');
            });

        $job = $this->makeJob([
            'event' => 'category.created',
            'uuid' => null,
            'name' => null,
            'message_id' => 'msg-cat-no-fields',
        ]);
        $job->fire();

        $this->assertEquals(0, \App\Models\Category::count());
    }

    #[Test]
    public function category_created_with_is_active_false_creates_inactive_category(): void
    {
        $job = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000040',
            'name' => 'Неактивная категория',
            'is_active' => false,
            'message_id' => 'msg-cat-inactive-001',
        ]);
        $job->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-000000000040',
            'name' => 'Неактивная категория',
            'is_active' => false,
        ]);
    }

    #[Test]
    public function category_updated_deactivates_category(): void
    {
        // Создаём активную категорию
        $createJob = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'name' => 'Активная категория',
            'is_active' => true,
            'message_id' => 'msg-cat-deact-create',
        ]);
        $createJob->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'is_active' => true,
        ]);

        // Деактивируем через category.updated
        $updateJob = $this->makeJob([
            'event' => 'category.updated',
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'name' => 'Активная категория',
            'is_active' => false,
            'message_id' => 'msg-cat-deact-update',
        ]);
        $updateJob->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-000000000041',
            'is_active' => false,
        ]);
    }

    #[Test]
    public function category_created_without_is_active_defaults_to_true(): void
    {
        $job = $this->makeJob([
            'event' => 'category.created',
            'uuid' => '00000000-0000-4000-a000-000000000042',
            'name' => 'Категория без is_active',
            'message_id' => 'msg-cat-default-active',
        ]);
        $job->fire();

        $this->assertDatabaseHas('categories', [
            'uuid' => '00000000-0000-4000-a000-000000000042',
            'is_active' => true,
        ]);
    }

    // ========================================================
    // US-13: product.* — синхронизация товаров
    // ========================================================

    #[Test]
    public function product_created_creates_product_in_database(): void
    {
        // Создаём категорию для привязки
        \App\Models\Category::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000008',
        ]);

        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-00000000002b',
            'name' => 'Вибро-яйцо XYZ',
            'code' => '0T-123213',
            'sku' => 'AAS-123213',
            'category_uuid' => '00000000-0000-4000-a000-000000000008',
            'brand' => 'BrandTest',
            'description' => 'Описание товара',
            'barcodes' => ['4600000000001', '4600000000002'],
            'message_id' => 'msg-prod-created-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('products', [
            'external_id' => '00000000-0000-4000-a000-00000000002b',
            'name' => 'Вибро-яйцо XYZ',
            'code' => '0T-123213',
            'sku' => 'AAS-123213',
        ]);

        $product = Product::where('external_id', '00000000-0000-4000-a000-00000000002b')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->category_id);
        $this->assertNotNull($product->brand_id);
        $this->assertCount(2, $product->barcodes);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-prod-created-001',
            'event' => 'product.created',
        ]);
    }

    #[Test]
    public function product_created_syncs_barcodes(): void
    {
        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-000000000027',
            'name' => 'Товар со штрих-кодами',
            'barcodes' => ['111', '222', '333'],
            'message_id' => 'msg-prod-barcodes-001',
        ]);
        $job->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-000000000027')->first();
        $this->assertNotNull($product);
        $this->assertCount(3, $product->barcodes);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '111']);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '333']);
    }

    #[Test]
    public function product_updated_replaces_barcodes(): void
    {
        // Первое создание с 3 штрих-кодами
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-00000000002a',
            'name' => 'Товар',
            'barcodes' => ['aaa', 'bbb', 'ccc'],
            'message_id' => 'msg-prod-upd-barcodes-create',
        ]);
        $createJob->fire();

        // Обновление с новым набором штрих-кодов
        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-00000000002a',
            'name' => 'Товар обновлён',
            'barcodes' => ['xxx', 'yyy'],
            'message_id' => 'msg-prod-upd-barcodes-update',
        ]);
        $updateJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-00000000002a')->first();
        $this->assertNotNull($product);
        $this->assertEquals('Товар обновлён', $product->name);
        // Старые штрих-коды заменены новыми
        $this->assertCount(2, $product->barcodes);
        $this->assertDatabaseMissing('product_barcodes', ['product_id' => $product->id, 'barcode' => 'aaa']);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => 'xxx']);
    }

    #[Test]
    public function product_created_with_model_creates_product_model(): void
    {
        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-00000000002c',
            'name' => 'Товар с моделью',
            'model' => [
                'uuid' => '00000000-0000-4000-a000-000000000015',
                'name' => 'Модель товара А',
            ],
            'message_id' => 'msg-prod-model-001',
        ]);
        $job->fire();

        $this->assertDatabaseHas('product_models', [
            'external_id' => '00000000-0000-4000-a000-000000000015',
            'name' => 'Модель товара А',
        ]);

        $product = Product::where('external_id', '00000000-0000-4000-a000-00000000002c')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->model_id);
    }

    #[Test]
    public function product_created_with_attributes_saves_attribute_values(): void
    {
        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-000000000026',
            'name' => 'Товар с атрибутами',
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-000000000066',
                    'property_label' => 'weight',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => '150г',
                ],
                [
                    'property_uuid' => '00000000-0000-4000-a000-000000000064',
                    'property_label' => 'color',
                    'value_type' => 'string',
                    'value_uuid' => '00000000-0000-4000-a000-000000000067',
                    'value_label' => 'розовый',
                ],
                [
                    'property_uuid' => '00000000-0000-4000-a000-000000000065',
                    'property_label' => 'material',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'силикон',
                ],
            ],
            'message_id' => 'msg-prod-attrs-001',
        ]);
        $job->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-000000000026')->first();
        $this->assertNotNull($product);

        // Проверяем что атрибуты сохранены
        $this->assertDatabaseHas('attributes', ['slug' => 'weight']);
        $this->assertDatabaseHas('attributes', ['slug' => 'color']);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'text_value' => '150г',
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'text_value' => 'розовый',
        ]);
    }

    #[Test]
    public function product_created_idempotency_prevents_duplicate(): void
    {
        ErpProcessedMessage::create([
            'message_id' => 'msg-prod-dup-001',
            'event' => 'product.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-000000000028',
            'name' => 'Дубликат товара',
            'message_id' => 'msg-prod-dup-001',
        ]);
        $job->fire();

        $this->assertDatabaseMissing('products', ['external_id' => '00000000-0000-4000-a000-000000000028']);
    }

    #[Test]
    public function product_created_twice_is_idempotent_no_duplicate_key_error(): void
    {
        // Регрессионный тест: повторная доставка product.created не должна падать
        // с ConstraintViolation на product_models, brands, attribute_values, attribute_category.
        \App\Models\Category::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000099',
        ]);

        $payload = [
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-000000000091',
            'name' => 'Товар дубль',
            'category_uuid' => '00000000-0000-4000-a000-000000000099',
            'brand' => [
                'uuid' => '00000000-0000-4000-a000-000000000092',
                'name' => 'Бренд Дубль',
            ],
            'model' => [
                'uuid' => '00000000-0000-4000-a000-000000000093',
                'name' => 'Модель Дубль',
            ],
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-000000000094',
                    'property_label' => 'Цвет',
                    'value_type' => 'reference',
                    'value_uuid' => '00000000-0000-4000-a000-000000000095',
                    'value_label' => 'синий',
                ],
            ],
            'message_id' => 'msg-prod-dedup-first',
            'timestamp' => now()->toIso8601String(),
        ];

        // Первая доставка
        $this->makeJob($payload)->fire();

        // Вторая доставка (другой message_id, иначе сработает идемпотентность по message_id)
        $payload['message_id'] = 'msg-prod-dedup-second';
        $this->makeJob($payload)->fire();

        // Должен быть ровно один продукт, один бренд, одна модель, одно значение атрибута
        $this->assertSame(1, Product::where('external_id', '00000000-0000-4000-a000-000000000091')->count());
        $this->assertSame(1, \App\Models\Brand::where('external_id', '00000000-0000-4000-a000-000000000092')->count());
        $this->assertSame(1, \App\Models\ProductModel::where('external_id', '00000000-0000-4000-a000-000000000093')->count());
        $this->assertSame(1, \App\Models\AttributeValue::where('external_id', '00000000-0000-4000-a000-000000000095')->count());
    }

    #[Test]
    public function product_created_without_required_fields_is_skipped(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'payload не соответствует JSON Schema');
            });

        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => null,
            'name' => null,
            'message_id' => 'msg-prod-no-fields',
        ]);
        $job->fire();

        $this->assertEquals(0, Product::count());
    }

    #[Test]
    public function product_created_preserves_existing_base_price(): void
    {
        // Создаём товар с ценой вручную (как будто price.updated уже отработал)
        $existing = Product::factory()->create([
            'external_id' => '00000000-0000-4000-a000-000000000029',
            'base_price' => 12345.00,
        ]);

        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-000000000029',
            'name' => 'Обновлённое имя',
            'message_id' => 'msg-prod-preserve-price',
        ]);
        $job->fire();

        $existing->refresh();
        // Цена должна сохраниться — price.updated управляет ценами (US-02)
        $this->assertEquals(12345.00, (float) $existing->base_price);
        $this->assertEquals('Обновлённое имя', $existing->name);
    }
}
