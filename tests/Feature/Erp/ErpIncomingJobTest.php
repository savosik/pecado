<?php

namespace Tests\Feature\Erp;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ErpBusMessage;
use App\Models\ErpProcessedMessage;
use App\Models\ErpPromotion;
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
    public function partner_updated_via_incoming_queue_fills_working_name_only(): void
    {
        // Полный проход через шину: валидация payload по JSON Schema, маршрутизация
        // в HandlePartnerUpdated и запись атрибутов. Проверяем, что наименование
        // из 1С садится в рабочее поле, а имя из кабинета переживает сообщение.
        $user = User::factory()->create([
            'email' => 'renamed-partner@example.com',
            'erp_id' => '00000000-0000-4000-a000-000000000099',
            'name' => 'Как я себя назвал',
            'erp_name' => 'ООО «Ромашка»',
        ]);

        $job = $this->makeJob([
            'event' => 'partner.updated',
            'uuid' => '00000000-0000-4000-a000-000000000099',
            'login' => 'renamed-partner@example.com',
            'name' => 'ООО «Ромашка» (Иванов И.И.)',
            'email' => 'renamed-partner@example.com',
            'city' => 'Тюмень',
            'message_id' => 'msg-partner-upd-name',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $user->refresh();

        $this->assertEquals('ООО «Ромашка» (Иванов И.И.)', $user->erp_name);
        $this->assertEquals('Как я себя назвал', $user->name);
        $this->assertEquals('Тюмень', $user->city);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-partner-upd-name',
            'event' => 'partner.updated',
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
            'status' => 'pending_approval',
            'type' => 'order',
            'message_id' => 'msg-order-upsert-first',
            'partner_uuid' => '00000000-0000-4000-a000-000000001b01',
            'contractor' => [
                'uuid' => '00000000-0000-4000-a000-000000002b01',
                'tax_id' => '7711000001',
                'tax_code' => '770101001',
                'name' => 'ООО Upsert',
            ],
        ];

        $this->makeJob($payload)->fire();

        $this->assertDatabaseHas('orders', [
            'uuid' => '00000000-0000-4000-a000-000000000b01',
            'status' => 'pending_approval',
        ]);

        // Вторая доставка того же сообщения — но с другим message_id (RabbitMQ retry)
        $retryPayload = array_merge($payload, [
            'status' => 'ready_for_provision',
            'message_id' => 'msg-order-upsert-retry',
        ]);

        $this->makeJob($retryPayload)->fire();

        // Заказ один — дубля нет
        $this->assertEquals(1, Order::where('uuid', '00000000-0000-4000-a000-000000000b01')->count());

        $order = Order::where('uuid', '00000000-0000-4000-a000-000000000b01')->first();
        // Статус обновлён из второго payload
        $this->assertEquals('ready_for_provision', $order->status->value);
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
            'status' => 'pending_approval',
            'type' => 'order',
            'message_id' => 'msg-order-dupnumber-first',
            'partner_uuid' => '00000000-0000-4000-a000-000000001b02',
            'contractor' => [
                'uuid' => '00000000-0000-4000-a000-000000002b02',
                'tax_id' => '7711000002',
                'tax_code' => '770101001',
                'name' => 'ООО DupNumber',
            ],
        ];

        $this->makeJob($firstPayload)->fire();

        $secondPayload = [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000000b03',
            'number' => 'ORD-DUP-NUMBER-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'message_id' => 'msg-order-dupnumber-second',
            'partner_uuid' => '00000000-0000-4000-a000-000000001b02',
            'contractor' => [
                'uuid' => '00000000-0000-4000-a000-000000002b02',
                'tax_id' => '7711000002',
                'tax_code' => '770101001',
                'name' => 'ООО DupNumber',
            ],
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
            'status' => 'pending_approval',
            'type' => 'order',
            'message_id' => 'msg-order-no-country',
            'partner_uuid' => '00000000-0000-4000-a000-000000001c01',
            'contractor' => [
                'uuid' => '00000000-0000-4000-a000-000000002c01',
                'tax_id' => '7701234567',
                'tax_code' => '770101001',
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
            'status' => 'pending_approval',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000003f',
            'status' => 'ready_for_provision',
            'items' => [],
            'message_id' => 'msg-order-upd-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();

        $this->assertEquals('ready_for_provision', $order->status->value);
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
            'status' => 'pending_approval',
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
            'status' => 'ready_for_provision',
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
            'status' => 'pending_approval',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-order-dup',
            'event' => 'order.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-000000000040',
            'status' => 'ready_for_provision',
            'message_id' => 'msg-order-dup',
        ]);

        $job->fire();

        $order->refresh();
        $this->assertEquals('pending_approval', $order->status->value);
    }

    /**
     * v15.4: раньше order.updated по неизвестному заказу считался успешно
     * обработанным и попадал в erp_processed_messages. Так за 1–15 июля 2026
     * незаметно потерялось 40 заказов. Теперь такое сообщение не считается
     * обработанным, но и не роняет воркер: job ловит ошибку и снимает сообщение
     * с очереди — повтор всё равно не помог бы.
     */
    #[Test]
    public function order_updated_for_unknown_order_is_not_marked_processed_and_does_not_crash(): void
    {
        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000001d',
            'status' => 'ready_for_provision',
            'message_id' => 'msg-order-unknown',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseMissing('erp_processed_messages', [
            'message_id' => 'msg-order-unknown',
        ]);
        $this->assertDatabaseMissing('orders', [
            'uuid' => '00000000-0000-4000-a000-00000000001d',
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
            'status' => 'ready_for_provision',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000a25a',
            'status' => 'ready_for_shipment',
            'message_id' => 'msg-order-r2s-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();
        $this->assertEquals('ready_for_shipment', $order->status->value);
        $this->assertDatabaseHas('orders', ['uuid' => '00000000-0000-4000-a000-00000000a25a', 'status' => 'ready_for_shipment']);
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
            'status' => 'ready_for_shipment',
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
            'status' => 'ready_for_provision',
        ]);

        $job = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000003e',
            'message_id' => 'msg-order-del-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $fresh = \App\Models\Order::withTrashed()->where('uuid', '00000000-0000-4000-a000-00000000003e')->first();

        $this->assertEquals('closed', $fresh->status->value, 'v14: order.deleted → soft-delete + closed');
        $this->assertNotNull($fresh->deleted_at, 'Заказ должен быть soft-deleted (v14)');
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
            'status' => 'pending_approval',
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000043',
            'status' => 'in_reserve',
            'message_id' => 'msg-return-upd-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $return->refresh();

        $this->assertEquals('in_reserve', $return->status->value);
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
            'status' => 'pending_approval',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-return-dup',
            'event' => 'return.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000044',
            'status' => 'in_reserve',
            'message_id' => 'msg-return-dup',
        ]);

        $job->fire();

        $return->refresh();
        $this->assertEquals('pending_approval', $return->status->value);
    }

    #[Test]
    public function return_updated_for_unknown_return_completes_without_error(): void
    {
        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000021',
            'status' => 'in_reserve',
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
            'status' => 'pending_approval',
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
            'status' => 'pending_approval',
        ]);

        // 1. order.updated — подтверждение + добавление позиций
        $updateJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'status' => 'ready_for_provision',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-00000000000e', 'quantity' => 5, 'price' => 3000.00],
            ],
            'message_id' => 'msg-life-ord-upd',
        ]);
        $updateJob->fire();

        $order->refresh();
        $this->assertEquals('ready_for_provision', $order->status->value);
        $this->assertCount(1, $order->items);

        // 2. order.deleted — отмена (v14: soft-delete + closed)
        $deleteJob = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'message_id' => 'msg-life-ord-del',
        ]);
        $deleteJob->fire();

        $fresh = Order::withTrashed()->where('uuid', '00000000-0000-4000-a000-00000000000f')->first();
        $this->assertEquals('closed', $fresh->status->value, 'v14: order.deleted → soft-delete + closed');
        $this->assertNotNull($fresh->deleted_at, 'v14: Заказ должен быть soft-deleted');

        // 3. Дубль order.updated — не должен обработаться (идемпотентность по message_id)
        $dupJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-a000-00000000000f',
            'status' => 'ready_for_provision',
            'message_id' => 'msg-life-ord-upd',
        ]);
        $dupJob->fire();

        // Статус не должен измениться — дубликат
        $fresh = Order::withTrashed()->where('uuid', '00000000-0000-4000-a000-00000000000f')->first();
        $this->assertEquals('closed', $fresh->status->value);
        $this->assertNotNull($fresh->deleted_at);
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
            'status' => 'pending_approval',
        ]);

        // 1. return.updated — смена статуса на confirmed
        $updJob = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => '00000000-0000-4000-a000-000000000014',
            'status' => 'in_reserve',
            'message_id' => 'msg-ret-life-upd',
            'timestamp' => now()->toIso8601String(),
        ]);
        $updJob->fire();

        $return->refresh();
        $this->assertEquals('in_reserve', $return->status->value);
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

        // Статус остался confirmed (soft-deleted, но данные в БД)
        $return = ProductReturn::withTrashed()->where('uuid', '00000000-0000-4000-a000-000000000014')->first();
        $this->assertEquals('in_reserve', $return->status->value);
        $this->assertNotNull($return->deleted_at);
    }

    // ========================================================
    // US-09: shipment.created / shipment.updated / shipment.deleted
    // ========================================================

    #[Test]
    public function shipment_created_creates_shipment_through_job(): void
    {
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-a000-00000000000d']);
        $user = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000003100']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1234567890',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000031',
            'contractor_uuid' => '00000000-0000-4000-a000-000000031000',
            'tax_id' => '1234567890',
            'partner_uuid' => '00000000-0000-4000-a000-000000003100',
            'number' => '29УТ-000031',
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
            'contractor_uuid' => '00000000-0000-4000-a000-000000032000',
            'tax_id' => '1234567890',
            'number' => '29УТ-000032',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                [
                    'product_uuid' => '00000000-0000-4000-a000-00000000000d',
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
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
            'number' => '29УТ-000034',
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
            'contractor_uuid' => '00000000-0000-4000-a000-00000002f000',
            'tax_id' => '5555555555',
            'number' => '29УТ-00002F',
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
            'contractor_uuid' => '00000000-0000-4000-a000-00000002f000',
            'tax_id' => '5555555555',
            'number' => '29УТ-00002F',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'RUB',
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
            'contractor_uuid' => '00000000-0000-4000-a000-00000002f000',
            'tax_id' => '5555555555',
            'number' => '29УТ-00002F',
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
            'contractor_uuid' => '00000000-0000-4000-a000-000000a02000',
            'message_id' => 'msg-ship-neg-disc-001',
            'tax_id' => '1234567890',
            'number' => '29УТ-000A02',
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

    #[Test]
    public function shipment_created_without_number_fails_schema_validation(): void
    {
        // v12.12: поле `number` обязательно. Если 1С не прислал номер реализации —
        // сообщение должно быть отклонено валидатором, реализация не создаётся.
        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000b01',
            'tax_id' => '1234567890',
            'date' => '2026-04-21',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000000b02', 'quantity' => 1, 'price' => 500.00],
            ],
            'message_id' => 'msg-ship-no-number-001',
        ]);

        $job->fire();

        $this->assertDatabaseMissing('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000b01',
        ]);
        $this->assertDatabaseMissing('erp_processed_messages', [
            'message_id' => 'msg-ship-no-number-001',
        ]);
    }

    #[Test]
    public function shipment_updated_without_number_fails_schema_validation(): void
    {
        // v12.12: поле `number` обязательно для shipment.updated.
        $existing = \App\Models\Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-000000000b03',
            'status' => 'new',
            'erp_number' => 'OLD-ERP-NUM',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.updated',
            'uuid' => '00000000-0000-4000-a000-000000000b03',
            'tax_id' => '1234567890',
            'date' => '2026-04-21',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000000b04', 'quantity' => 1, 'price' => 500.00],
            ],
            'message_id' => 'msg-ship-upd-no-number-001',
        ]);

        $job->fire();

        $existing->refresh();
        $this->assertSame('new', $existing->status);
        $this->assertSame('OLD-ERP-NUM', $existing->erp_number);
        $this->assertDatabaseMissing('erp_processed_messages', [
            'message_id' => 'msg-ship-upd-no-number-001',
        ]);
    }

    #[Test]
    public function shipment_created_with_empty_number_fails_schema_validation(): void
    {
        // v12.12: пустая строка в `number` тоже не принимается (minLength: 1).
        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000000b05',
            'tax_id' => '1234567890',
            'number' => '',
            'date' => '2026-04-21',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000000b06', 'quantity' => 1, 'price' => 500.00],
            ],
            'message_id' => 'msg-ship-empty-number-001',
        ]);

        $job->fire();

        $this->assertDatabaseMissing('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000000b05',
        ]);
    }

    #[Test]
    public function order_created_without_contractor_uuid_fails_schema_validation(): void
    {
        // v13.6: contractor.uuid и partner_uuid обязательны. Сообщения без них
        // должны отклоняться валидатором — Order и Company не создаются.
        $job = $this->makeJob([
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-000000d6c01',
            'message_id' => 'msg-order-no-contractor-uuid',
            'number' => 'ORD-NO-UUID-001',
            'status' => 'pending',
            'type' => 'order',
            'partner_uuid' => '00000000-0000-4000-a000-000000d6c02',
            'contractor' => [
                'tax_id' => '5410165679',
                'name' => '21 ООО',
                // contractor.uuid намеренно отсутствует — должно отклоняться
            ],
            'items' => [],
        ]);

        $job->fire();

        $this->assertDatabaseMissing('orders', [
            'uuid' => '00000000-0000-4000-a000-000000d6c01',
        ]);
        $this->assertDatabaseMissing('companies', [
            'tax_id' => '5410165679',
        ]);
    }

    #[Test]
    public function shipment_created_without_contractor_uuid_fails_schema_validation(): void
    {
        // v13.6: contractor_uuid обязателен в shipment.created.
        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-000000d6c03',
            'tax_id' => '1234567890',
            'number' => '29УТ-D6C03',
            'date' => '2026-04-25',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-000000d6c04', 'quantity' => 1, 'price' => 500.00],
            ],
            'message_id' => 'msg-ship-no-contractor-uuid',
            // contractor_uuid намеренно отсутствует
        ]);

        $job->fire();

        $this->assertDatabaseMissing('shipments', [
            'uuid' => '00000000-0000-4000-a000-000000d6c03',
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
    public function product_updated_full_replace_removes_stale_attributes_through_queue(): void
    {
        // Создаём товар с двумя атрибутами через product.created
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000a1',
            'name' => 'Товар с двумя атрибутами',
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-0000000000a2',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Красный',
                ],
                [
                    'property_uuid' => '00000000-0000-4000-a000-0000000000a3',
                    'property_label' => 'Размер',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'XL',
                ],
            ],
            'message_id' => 'msg-fullrepl-create',
        ]);
        $createJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000a1')->first();
        $this->assertEquals(2, $product->attributeValues()->count());

        // product.updated с полным списком из одного атрибута — второй должен быть удалён
        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-0000000000a1',
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-0000000000a2',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Красный',
                ],
            ],
            'message_id' => 'msg-fullrepl-update',
        ]);
        $updateJob->fire();

        $product->refresh();
        $this->assertEquals(1, $product->attributeValues()->count());
    }

    #[Test]
    public function product_updated_empty_attributes_wipes_all_through_queue(): void
    {
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000b1',
            'name' => 'Товар для полной очистки',
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-0000000000b2',
                    'property_label' => 'Материал',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Силикон',
                ],
            ],
            'message_id' => 'msg-wipe-create',
        ]);
        $createJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000b1')->first();
        $this->assertEquals(1, $product->attributeValues()->count());

        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-0000000000b1',
            'attributes' => [],
            'message_id' => 'msg-wipe-update',
        ]);
        $updateJob->fire();

        $product->refresh();
        $this->assertEquals(0, $product->attributeValues()->count());
    }

    #[Test]
    public function product_updated_without_attributes_field_keeps_them_through_queue(): void
    {
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000c1',
            'name' => 'Товар с сохранением атрибутов',
            'attributes' => [
                [
                    'property_uuid' => '00000000-0000-4000-a000-0000000000c2',
                    'property_label' => 'Длина',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => '20см',
                ],
            ],
            'message_id' => 'msg-keep-create',
        ]);
        $createJob->fire();

        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-0000000000c1',
            'name' => 'Только имя обновлено',
            'message_id' => 'msg-keep-update',
        ]);
        $updateJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000c1')->first();
        $this->assertEquals('Только имя обновлено', $product->name);
        $this->assertEquals(1, $product->attributeValues()->count());
    }

    #[Test]
    public function product_created_saves_description_html_through_queue(): void
    {
        $job = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000d1',
            'name' => 'Товар с HTML-описанием',
            'description' => 'Короткое описание',
            'description_html' => '<p>Подробное <strong>HTML</strong> описание</p>',
            'message_id' => 'msg-desc-html-created',
        ]);
        $job->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000d1')->first();
        $this->assertNotNull($product);
        $this->assertSame('Короткое описание', $product->description);
        $this->assertSame('<p>Подробное <strong>HTML</strong> описание</p>', $product->description_html);
    }

    #[Test]
    public function product_updated_overwrites_description_html_through_queue(): void
    {
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000d2',
            'name' => 'Товар',
            'description_html' => '<p>старый</p>',
            'message_id' => 'msg-desc-html-create',
        ]);
        $createJob->fire();

        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-0000000000d2',
            'description_html' => '<p>новый</p>',
            'message_id' => 'msg-desc-html-update',
        ]);
        $updateJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000d2')->first();
        $this->assertSame('<p>новый</p>', $product->description_html);
    }

    #[Test]
    public function product_updated_without_description_html_keeps_it_through_queue(): void
    {
        $createJob = $this->makeJob([
            'event' => 'product.created',
            'uuid' => '00000000-0000-4000-a000-0000000000d3',
            'name' => 'Товар',
            'description_html' => '<p>не трогать</p>',
            'message_id' => 'msg-desc-html-keep-create',
        ]);
        $createJob->fire();

        $updateJob = $this->makeJob([
            'event' => 'product.updated',
            'uuid' => '00000000-0000-4000-a000-0000000000d3',
            'name' => 'Только имя',
            'message_id' => 'msg-desc-html-keep-update',
        ]);
        $updateJob->fire();

        $product = Product::where('external_id', '00000000-0000-4000-a000-0000000000d3')->first();
        $this->assertEquals('Только имя', $product->name);
        $this->assertSame('<p>не трогать</p>', $product->description_html);
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

    // ========================================================
    // US-16: promotion.* — промо-флаги товаров через шину ERP
    // ========================================================

    #[Test]
    public function promotion_created_flow_sets_is_new_through_job(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000010',
            'is_new' => false,
        ]);

        $job = $this->makeJob([
            'event' => 'promotion.created',
            'uuid' => '00000000-0000-4000-b000-0000000000a1',
            'type' => 'new',
            'items' => [['uuid' => '00000000-0000-4000-b000-000000000010']],
            'message_id' => 'msg-promo-created-1',
        ]);
        $job->fire();

        $this->assertTrue((bool) $product->fresh()->is_new);
        $this->assertDatabaseHas('erp_promotions', [
            'uuid' => '00000000-0000-4000-b000-0000000000a1',
            'type' => 'new',
        ]);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-promo-created-1',
            'event' => 'promotion.created',
        ]);
    }

    #[Test]
    public function promotion_updated_flow_replaces_items_and_recalculates_flags(): void
    {
        $keep = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000011',
            'is_liquidation' => false,
        ]);
        $dropped = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000012',
            'is_liquidation' => false,
        ]);
        $added = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000013',
            'is_liquidation' => false,
        ]);

        $this->makeJob([
            'event' => 'promotion.created',
            'uuid' => '00000000-0000-4000-b000-0000000000b1',
            'type' => 'liquidation',
            'items' => [
                ['uuid' => $keep->external_id],
                ['uuid' => $dropped->external_id],
            ],
            'message_id' => 'msg-promo-liq-create',
        ])->fire();

        $this->assertTrue((bool) $keep->fresh()->is_liquidation);
        $this->assertTrue((bool) $dropped->fresh()->is_liquidation);

        $this->makeJob([
            'event' => 'promotion.updated',
            'uuid' => '00000000-0000-4000-b000-0000000000b1',
            'type' => 'liquidation',
            'items' => [
                ['uuid' => $keep->external_id],
                ['uuid' => $added->external_id],
            ],
            'message_id' => 'msg-promo-liq-update',
        ])->fire();

        $this->assertTrue((bool) $keep->fresh()->is_liquidation);
        $this->assertFalse((bool) $dropped->fresh()->is_liquidation);
        $this->assertTrue((bool) $added->fresh()->is_liquidation);
    }

    #[Test]
    public function promotion_deleted_flow_clears_flag(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000020',
            'is_bestseller' => false,
        ]);

        $this->makeJob([
            'event' => 'promotion.created',
            'uuid' => '00000000-0000-4000-b000-0000000000c1',
            'type' => 'bestseller',
            'items' => [['uuid' => $product->external_id]],
            'message_id' => 'msg-promo-bs-create',
        ])->fire();

        $this->assertTrue((bool) $product->fresh()->is_bestseller);

        $this->makeJob([
            'event' => 'promotion.deleted',
            'uuid' => '00000000-0000-4000-b000-0000000000c1',
            'message_id' => 'msg-promo-bs-delete',
        ])->fire();

        $this->assertFalse((bool) $product->fresh()->is_bestseller);
        $this->assertEquals(0, ErpPromotion::count());
    }

    #[Test]
    public function promotion_created_with_invalid_type_is_rejected_by_validator(): void
    {
        $product = Product::factory()->create([
            'external_id' => '00000000-0000-4000-b000-000000000030',
            'is_new' => false,
        ]);

        $job = $this->makeJob([
            'event' => 'promotion.created',
            'uuid' => '00000000-0000-4000-b000-0000000000d1',
            'type' => 'invalid_type',
            'items' => [['uuid' => $product->external_id]],
            'message_id' => 'msg-promo-invalid-type',
        ]);
        $job->fire();

        $this->assertEquals(0, ErpPromotion::count());
        $this->assertFalse((bool) $product->fresh()->is_new);
    }

    /**
     * v15.4: order.updated по заказу, которого нет, но данных хватает —
     * заказ достраивается, сообщение помечается `recovered`.
     */
    #[Test]
    public function order_updated_for_missing_order_recovers_it_and_logs_recovered(): void
    {
        config(['erp.bus_logging_enabled' => true]);

        $user = User::factory()->create(['erp_id' => '00000000-0000-4000-b000-0000000000e1']);
        $product = Product::factory()->create(['external_id' => '00000000-0000-4000-b000-0000000000e2']);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-b000-0000000000e3',
            'number' => '29УТ-010318',
            'status' => 'ready_for_shipment',
            'partner_uuid' => $user->erp_id,
            'contractor' => ['uuid' => '00000000-0000-4000-b000-0000000000e4', 'tax_id' => '780528446072'],
            'message_id' => 'msg-order-recover',
            'items' => [
                ['product_uuid' => $product->external_id, 'quantity' => 2, 'base_price' => 100, 'final_price' => 80],
            ],
        ]);
        $job->fire();

        $order = Order::where('uuid', '00000000-0000-4000-b000-0000000000e3')->first();
        $this->assertNotNull($order, 'Заказ должен быть восстановлен');
        $this->assertSame('29УТ-010318', $order->erp_number);
        $this->assertEqualsWithDelta(160.0, (float) $order->total_amount, 0.01);

        $logged = ErpBusMessage::where('message_id', 'msg-order-recover')->first();
        $this->assertNotNull($logged);
        $this->assertSame('recovered', $logged->status, 'Восстановление — не рядовой success');
        $this->assertStringContainsString('29УТ-010318', (string) $logged->error_message);
    }

    /**
     * v15.4: восстановить нечем — сообщение помечается `failed` с причиной,
     * чтобы ошибка была видна в админке, а не терялась молча.
     */
    #[Test]
    public function order_updated_for_missing_order_without_data_is_logged_as_failed(): void
    {
        config(['erp.bus_logging_enabled' => true]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => '00000000-0000-4000-b000-0000000000e5',
            'number' => '29УТ-009892',
            'status' => 'ready_for_closure',
            'message_id' => 'msg-order-unrecoverable',
            'items' => [],
        ]);
        $job->fire();

        $this->assertDatabaseMissing('orders', ['uuid' => '00000000-0000-4000-b000-0000000000e5']);

        $logged = ErpBusMessage::where('message_id', 'msg-order-unrecoverable')->first();
        $this->assertNotNull($logged, 'Сообщение обязано попасть в лог шины');
        $this->assertSame('failed', $logged->status);
        $this->assertStringContainsString('29УТ-009892', (string) $logged->error_message);
        $this->assertStringContainsString('order.created', (string) $logged->error_message);
    }
}
