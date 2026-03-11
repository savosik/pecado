<?php

namespace Tests\Feature\Erp;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\Discount;
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
    public function partner_created_via_incoming_queue_is_ignored_in_v2(): void
    {
        // v2: partner.created теперь исходящее событие (Сайт → 1С).
        // Входящее сообщение с event="partner.created" должно быть проигнорировано как неизвестное.
        $user = User::factory()->create([
            'email'  => 'partner-test@example.com',
            'status' => UserStatus::PROCESSING,
            'erp_id' => null,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'неизвестный тип события');
            });

        $job = $this->makeJob([
            'event'      => 'partner.created',
            'uuid'       => 'test-uuid-001',
            'login'      => 'partner-test@example.com',
            'message_id' => 'msg-001',
            'timestamp'  => now()->toIso8601String(),
        ]);

        $job->fire();

        $user->refresh();

        // Пользователь НЕ активирован — входящий partner.created игнорируется
        $this->assertEquals(UserStatus::PROCESSING, $user->status);
        $this->assertNull($user->erp_id);
        $this->assertDatabaseMissing('erp_processed_messages', [
            'message_id' => 'msg-001',
        ]);
    }

    #[Test]
    public function partner_deleted_blocks_user_through_job(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'erp_id' => 'test-uuid-002',
        ]);

        $job = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid' => 'test-uuid-002',
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
            'erp_id' => 'blocked-uuid',
        ]);

        // Имитируем уже обработанное сообщение
        ErpProcessedMessage::create([
            'message_id' => 'msg-duplicate',
            'event' => 'partner.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'partner.created',
            'uuid' => 'new-uuid',
            'login' => 'partner-idem@example.com',
            'message_id' => 'msg-duplicate',
        ]);

        $job->fire();

        $user->refresh();

        // Статус НЕ должен измениться — дубликат проигнорирован
        $this->assertEquals(UserStatus::BLOCKED, $user->status);
        $this->assertEquals('blocked-uuid', $user->erp_id);
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
            'uuid' => 'some-uuid',
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
            'erp_id' => 'uuid-no-msgid',
        ]);

        $job = $this->makeJob([
            'event' => 'partner.deleted',
            'uuid'  => 'uuid-no-msgid',
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
            'email'  => 'full-cycle@example.com',
            'status' => UserStatus::ACTIVE,
            'erp_id' => 'cycle-uuid-001',
        ]);

        // partner.deleted (1С → Сайт)
        $deletedJob = $this->makeJob([
            'event'      => 'partner.deleted',
            'uuid'       => 'cycle-uuid-001',
            'message_id' => 'msg-cycle-deleted',
        ]);
        $deletedJob->fire();

        $user->refresh();
        $this->assertEquals(UserStatus::BLOCKED, $user->status);

        // Дубль partner.deleted (тот же message_id) — не должен повторно обработаться
        $duplicateJob = $this->makeJob([
            'event'      => 'partner.deleted',
            'uuid'       => 'cycle-uuid-001',
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
            'external_id' => 'product-uuid-price-001',
            'base_price' => 10000.00,
        ]);

        $job = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => 'product-uuid-price-001',
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
            'external_id' => 'product-uuid-idem',
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
            'product_uuid' => 'product-uuid-idem',
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
            'product_uuid' => 'nonexistent-product-uuid',
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
            'external_id' => 'multi-price-uuid-1',
            'base_price' => 5000.00,
        ]);

        $product2 = Product::factory()->create([
            'external_id' => 'multi-price-uuid-2',
            'base_price' => 8000.00,
        ]);

        // Обновляем цену первого товара
        $job1 = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => 'multi-price-uuid-1',
            'price' => 6000.00,
            'message_id' => 'msg-multi-price-1',
        ]);
        $job1->fire();

        // Обновляем цену второго товара
        $job2 = $this->makeJob([
            'event' => 'price.updated',
            'product_uuid' => 'multi-price-uuid-2',
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
    // US-03: discount.created / discount.updated / discount.deleted
    // ========================================================

    #[Test]
    public function discount_created_creates_discount_with_relations_through_job(): void
    {
        $product = Product::factory()->create(['external_id' => 'disc-prod-uuid-001']);
        $user = User::factory()->create(['erp_id' => 'disc-partner-uuid-001']);

        $job = $this->makeJob([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-int-0001-0001-000000000001',
            'type' => 'agreement',
            'value' => 15.00,
            'starts_at' => '2026-01-01T00:00:00',
            'ends_at' => '2026-06-30T23:59:59',
            'product_uuids' => ['disc-prod-uuid-001'],
            'partner_uuids' => ['disc-partner-uuid-001'],
            'message_id' => 'msg-disc-created-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('discounts', [
            'external_id' => 'd1e2f3a4-int-0001-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 15.00,
            'is_posted' => true,
        ]);

        $discount = Discount::where('external_id', 'd1e2f3a4-int-0001-0001-000000000001')->first();
        $this->assertCount(1, $discount->products);
        $this->assertCount(1, $discount->users);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-disc-created-001',
            'event' => 'discount.created',
        ]);
    }

    #[Test]
    public function discount_updated_changes_discount_through_job(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'disc-upd-prod-001']);
        $product2 = Product::factory()->create(['external_id' => 'disc-upd-prod-002']);

        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-int-0002-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);
        $discount->products()->attach($product1);

        $job = $this->makeJob([
            'event' => 'discount.updated',
            'uuid' => 'd1e2f3a4-int-0002-0001-000000000001',
            'type' => 'promotion',
            'value' => 30.00,
            'starts_at' => '2026-04-01T00:00:00',
            'ends_at' => '2026-12-31T23:59:59',
            'product_uuids' => ['disc-upd-prod-002'],
            'partner_uuids' => [],
            'message_id' => 'msg-disc-updated-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $discount->refresh();
        $this->assertEquals('promotion', $discount->type);
        $this->assertEquals(30.00, (float) $discount->percentage);
        $this->assertCount(1, $discount->products);
        $this->assertTrue($discount->products->contains($product2));
        $this->assertFalse($discount->products->contains($product1));

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-disc-updated-001',
            'event' => 'discount.updated',
        ]);
    }

    #[Test]
    public function discount_deleted_deactivates_discount_through_job(): void
    {
        $discount = Discount::create([
            'external_id' => 'd1e2f3a4-int-0003-0001-000000000001',
            'type' => 'agreement',
            'percentage' => 10.00,
            'is_posted' => true,
        ]);

        $job = $this->makeJob([
            'event' => 'discount.deleted',
            'uuid' => 'd1e2f3a4-int-0003-0001-000000000001',
            'message_id' => 'msg-disc-deleted-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $discount->refresh();
        $this->assertFalse($discount->is_posted);
        $this->assertNotNull($discount->deleted_at);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-disc-deleted-001',
            'event' => 'discount.deleted',
        ]);
    }

    #[Test]
    public function discount_created_idempotency_prevents_reprocessing(): void
    {
        ErpProcessedMessage::create([
            'message_id' => 'msg-disc-dup-001',
            'event' => 'discount.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-int-0004-0001-000000000001',
            'type' => 'agreement',
            'value' => 10.00,
            'product_uuids' => [],
            'partner_uuids' => [],
            'message_id' => 'msg-disc-dup-001',
        ]);

        $job->fire();

        // Скидка не должна быть создана — дубликат проигнорирован
        $this->assertDatabaseMissing('discounts', [
            'external_id' => 'd1e2f3a4-int-0004-0001-000000000001',
        ]);
    }

    #[Test]
    public function full_discount_lifecycle_created_then_updated_then_deleted(): void
    {
        $product1 = Product::factory()->create(['external_id' => 'life-prod-001']);
        $product2 = Product::factory()->create(['external_id' => 'life-prod-002']);
        $user = User::factory()->create(['erp_id' => 'life-partner-001']);

        // 1. discount.created
        $createJob = $this->makeJob([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-life-0001-0001-000000000001',
            'type' => 'agreement',
            'value' => 10.00,
            'starts_at' => '2026-01-01T00:00:00',
            'ends_at' => '2026-06-30T23:59:59',
            'product_uuids' => ['life-prod-001'],
            'partner_uuids' => ['life-partner-001'],
            'message_id' => 'msg-life-create',
        ]);
        $createJob->fire();

        $discount = Discount::where('external_id', 'd1e2f3a4-life-0001-0001-000000000001')->first();
        $this->assertNotNull($discount);
        $this->assertEquals('agreement', $discount->type);
        $this->assertEquals(10.00, (float) $discount->percentage);
        $this->assertCount(1, $discount->products);
        $this->assertCount(1, $discount->users);

        // 2. discount.updated
        $updateJob = $this->makeJob([
            'event' => 'discount.updated',
            'uuid' => 'd1e2f3a4-life-0001-0001-000000000001',
            'type' => 'promotion',
            'value' => 20.00,
            'starts_at' => '2026-03-01T00:00:00',
            'ends_at' => '2026-12-31T23:59:59',
            'product_uuids' => ['life-prod-001', 'life-prod-002'],
            'partner_uuids' => [],
            'message_id' => 'msg-life-update',
        ]);
        $updateJob->fire();

        $discount->refresh();
        $this->assertEquals('promotion', $discount->type);
        $this->assertEquals(20.00, (float) $discount->percentage);
        $this->assertCount(2, $discount->products);
        $this->assertCount(0, $discount->users);

        // 3. discount.deleted
        $deleteJob = $this->makeJob([
            'event' => 'discount.deleted',
            'uuid' => 'd1e2f3a4-life-0001-0001-000000000001',
            'message_id' => 'msg-life-delete',
        ]);
        $deleteJob->fire();

        $discount->refresh();
        $this->assertFalse($discount->is_posted);
        $this->assertNotNull($discount->deleted_at);

        // 4. Дубль discount.created (тот же message_id) — не должен восстановить
        $duplicateJob = $this->makeJob([
            'event' => 'discount.created',
            'uuid' => 'd1e2f3a4-life-0001-0001-000000000001',
            'type' => 'agreement',
            'value' => 5.00,
            'product_uuids' => [],
            'partner_uuids' => [],
            'message_id' => 'msg-life-create',
        ]);
        $duplicateJob->fire();

        $discount->refresh();
        $this->assertNotNull($discount->deleted_at);
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
            'external_id' => 'stock-product-uuid-001',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'stock-warehouse-uuid-001',
        ]);

        $product->warehouses()->attach($warehouse->id, ['quantity' => 10]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'stock-product-uuid-001',
            'warehouse_uuid' => 'stock-warehouse-uuid-001',
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
            'external_id' => 'stock-product-uuid-002',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'stock-warehouse-uuid-002',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'stock-product-uuid-002',
            'warehouse_uuid' => 'stock-warehouse-uuid-002',
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
            'external_id' => 'stock-product-idem',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'stock-warehouse-idem',
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
            'product_uuid' => 'stock-product-idem',
            'warehouse_uuid' => 'stock-warehouse-idem',
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
            'external_id' => 'stock-warehouse-unknown-prod',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'nonexistent-stock-product-uuid',
            'warehouse_uuid' => 'stock-warehouse-unknown-prod',
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
            'external_id' => 'stock-product-unknown-wh',
        ]);

        $job = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'stock-product-unknown-wh',
            'warehouse_uuid' => 'nonexistent-stock-warehouse-uuid',
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
        $product1 = Product::factory()->create(['external_id' => 'multi-stock-prod-1']);
        $product2 = Product::factory()->create(['external_id' => 'multi-stock-prod-2']);
        $warehouse = Warehouse::factory()->create(['external_id' => 'multi-stock-wh-1']);

        // Обновляем остаток первого товара
        $job1 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'multi-stock-prod-1',
            'warehouse_uuid' => 'multi-stock-wh-1',
            'quantity' => 50,
            'message_id' => 'msg-multi-stock-1',
        ]);
        $job1->fire();

        // Обновляем остаток второго товара
        $job2 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'multi-stock-prod-2',
            'warehouse_uuid' => 'multi-stock-wh-1',
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
            'external_id' => 'life-stock-prod',
        ]);
        $warehouse = Warehouse::factory()->create([
            'external_id' => 'life-stock-wh',
        ]);

        // 1. Первое обновление — создание записи
        $job1 = $this->makeJob([
            'event' => 'stock.updated',
            'product_uuid' => 'life-stock-prod',
            'warehouse_uuid' => 'life-stock-wh',
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
            'product_uuid' => 'life-stock-prod',
            'warehouse_uuid' => 'life-stock-wh',
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
            'product_uuid' => 'life-stock-prod',
            'warehouse_uuid' => 'life-stock-wh',
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
            'product_uuid' => 'life-stock-prod',
            'warehouse_uuid' => 'life-stock-wh',
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
    // US-06/07: order.updated / order.deleted — синхронизация заказов
    // ========================================================

    #[Test]
    public function order_updated_changes_order_status_through_job(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => 'test-order-uuid-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => 'test-order-uuid-001',
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
        $product1 = Product::factory()->create(['external_id' => 'ord-prod-uuid-001']);
        $product2 = Product::factory()->create(['external_id' => 'ord-prod-uuid-002']);

        $order = Order::factory()->create([
            'uuid' => 'test-order-uuid-items',
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
            'uuid' => 'test-order-uuid-items',
            'status' => 'confirmed',
            'items' => [
                [
                    'product_uuid' => 'ord-prod-uuid-002',
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
            'uuid' => 'test-order-uuid-idem',
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
            'uuid' => 'test-order-uuid-idem',
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
            'uuid' => 'nonexistent-order-uuid',
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
    public function order_deleted_soft_deletes_order_through_job(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'uuid' => 'test-order-del-uuid-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'confirmed',
        ]);

        $job = $this->makeJob([
            'event' => 'order.deleted',
            'uuid' => 'test-order-del-uuid-001',
            'message_id' => 'msg-order-del-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $order->refresh();

        $this->assertEquals('cancelled', $order->status->value);
        $this->assertNull($order->deleted_at, 'Заказ не должен быть soft-deleted — остаётся как лог');
        $this->assertDatabaseHas('orders', ['uuid' => 'test-order-del-uuid-001', 'status' => 'cancelled']);
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
            'uuid' => 'nonexistent-order-del-uuid',
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
            'uuid' => 'test-return-uuid-001',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => 'test-return-uuid-001',
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
            'uuid' => 'test-return-uuid-idem',
            'status' => 'pending',
        ]);

        ErpProcessedMessage::create([
            'message_id' => 'msg-return-dup',
            'event' => 'return.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => 'test-return-uuid-idem',
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
            'uuid' => 'nonexistent-return-uuid',
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
            'uuid' => 'test-return-del-uuid-001',
            'status' => 'pending',
        ]);

        $job = $this->makeJob([
            'event' => 'return.deleted',
            'uuid' => 'test-return-del-uuid-001',
            'message_id' => 'msg-return-del-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertSoftDeleted('returns', ['uuid' => 'test-return-del-uuid-001']);
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
            'uuid' => 'nonexistent-return-del-uuid',
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
            'erp_id' => 'balance-partner-uuid-001',
        ]);

        $job = $this->makeJob([
            'event'       => 'balance.updated',
            'partner_uuid' => 'balance-partner-uuid-001',
            'contractors' => [
                [
                    'contractor_uuid' => 'c-uuid-job-001',
                    'contractor_inn'  => '1234567890',
                    'current_balance' => -125000.00,
                    'overdue_debt'    => 50000.00,
                    'overdue_details' => [
                        ['shipment_uuid' => 's-uuid-job-001', 'amount' => 50000.00, 'due_date' => '2026-01-15'],
                    ],
                ],
            ],
            'updated_at'  => '2026-02-16T10:00:00',
            'message_id'  => 'msg-balance-001',
            'timestamp'   => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('contractor_balances', [
            'user_id'         => $user->id,
            'contractor_inn'  => '1234567890',
            'current_balance' => -125000.00,
            'overdue_debt'    => 50000.00,
        ]);

        $balance = ContractorBalance::where('user_id', $user->id)->first();
        $this->assertEquals('2026-02-16', $balance->balance_erp_updated_at->format('Y-m-d'));
        $this->assertCount(1, $balance->overdueDetails);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-balance-001',
            'event'      => 'balance.updated',
        ]);
    }

    #[Test]
    public function balance_updated_overwrites_existing_balance_through_job(): void
    {
        $user = User::factory()->create([
            'erp_id' => 'balance-partner-uuid-002',
        ]);
        ContractorBalance::create([
            'user_id'         => $user->id,
            'contractor_inn'  => '9876543210',
            'current_balance' => -50000.00,
            'overdue_debt'    => 10000.00,
        ]);

        $job = $this->makeJob([
            'event'        => 'balance.updated',
            'partner_uuid' => 'balance-partner-uuid-002',
            'contractors'  => [
                [
                    'contractor_inn'  => '9876543210',
                    'current_balance' => -200000.00,
                    'overdue_debt'    => 75000.00,
                    'overdue_details' => [],
                ],
            ],
            'message_id' => 'msg-balance-002',
            'timestamp'  => now()->toIso8601String(),
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
            'erp_id' => 'balance-partner-uuid-idem',
        ]);

        ErpProcessedMessage::create([
            'message_id'   => 'msg-balance-dup',
            'event'        => 'balance.updated',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event'        => 'balance.updated',
            'partner_uuid' => 'balance-partner-uuid-idem',
            'contractors'  => [
                [
                    'contractor_inn'  => '9999999999',
                    'current_balance' => -999999.00,
                    'overdue_debt'    => 0,
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
            'partner_uuid' => 'nonexistent-partner-uuid',
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
        $product = Product::factory()->create(['external_id' => 'life-ord-prod-001']);

        $order = Order::factory()->create([
            'uuid' => 'life-order-uuid-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        // 1. order.updated — подтверждение + добавление позиций
        $updateJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => 'life-order-uuid-001',
            'status' => 'confirmed',
            'items' => [
                ['product_uuid' => 'life-ord-prod-001', 'quantity' => 5, 'price' => 3000.00],
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
            'uuid' => 'life-order-uuid-001',
            'message_id' => 'msg-life-ord-del',
        ]);
        $deleteJob->fire();

        $order->refresh();
        $this->assertEquals('cancelled', $order->status->value);
        $this->assertNull($order->deleted_at, 'Заказ не должен быть soft-deleted — остаётся как лог');
        $this->assertDatabaseHas('orders', ['uuid' => 'life-order-uuid-001', 'status' => 'cancelled']);

        // 3. Дубль order.updated — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'order.updated',
            'uuid' => 'life-order-uuid-001',
            'status' => 'confirmed',
            'message_id' => 'msg-life-ord-upd',
        ]);
        $dupJob->fire();

        // Статус не должен измениться — дубликат
        $order->refresh();
        $this->assertEquals('cancelled', $order->status->value);
    }

    // ========================================================
    // US-08: full_return_lifecycle — полный цикл возвратов
    // ========================================================

    #[Test]
    public function full_return_lifecycle_updated_then_deleted(): void
    {
        $user = User::factory()->create(['erp_id' => 'lifecycle-ret-partner']);
        $return = ProductReturn::factory()->create([
            'uuid' => 'lifecycle-return-uuid-001',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // 1. return.updated — смена статуса на approved
        $updJob = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => 'lifecycle-return-uuid-001',
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
            'uuid' => 'lifecycle-return-uuid-001',
            'message_id' => 'msg-ret-life-del',
            'timestamp' => now()->toIso8601String(),
        ]);
        $delJob->fire();

        $this->assertSoftDeleted('returns', ['uuid' => 'lifecycle-return-uuid-001']);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-ret-life-del',
            'event' => 'return.deleted',
        ]);

        // 3. Дубль return.updated (тот же message_id) — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'return.updated',
            'uuid' => 'lifecycle-return-uuid-001',
            'status' => 'completed',
            'message_id' => 'msg-ret-life-upd',
        ]);
        $dupJob->fire();

        // Статус остался approved (soft-deleted, но данные в БД)
        $return = ProductReturn::withTrashed()->where('uuid', 'lifecycle-return-uuid-001')->first();
        $this->assertEquals('approved', $return->status->value);
        $this->assertNotNull($return->deleted_at);
    }

    // ========================================================
    // US-09: shipment.created / shipment.updated / shipment.deleted
    // ========================================================

    #[Test]
    public function shipment_created_creates_shipment_through_job(): void
    {
        $product = Product::factory()->create(['external_id' => 'int-ship-prod-001']);
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1234567890',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => 's1a2b3c4-int-001',
            'contractor_inn' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'currency_code' => 'KZT',
            'items' => [
                [
                    'product_uuid' => 'int-ship-prod-001',
                    'quantity' => 10,
                    'price' => 3000.00,
                ],
            ],
            'message_id' => 'msg-ship-created-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('shipments', [
            'uuid' => 's1a2b3c4-int-001',
            'contractor_inn' => '1234567890',
            'status' => 'completed',
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $shipment = \App\Models\Shipment::where('uuid', 's1a2b3c4-int-001')->first();
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
            'uuid' => 's1a2b3c4-int-002',
            'status' => 'new',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.updated',
            'uuid' => 's1a2b3c4-int-002',
            'status' => 'completed',
            'message_id' => 'msg-ship-updated-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('shipments', [
            'uuid' => 's1a2b3c4-int-002',
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
            'uuid' => 's1a2b3c4-int-003',
        ]);

        $job = $this->makeJob([
            'event' => 'shipment.deleted',
            'uuid' => 's1a2b3c4-int-003',
            'message_id' => 'msg-ship-deleted-001',
            'timestamp' => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertSoftDeleted('shipments', ['uuid' => 's1a2b3c4-int-003']);

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
            'uuid' => 's1a2b3c4-int-dup',
            'contractor_inn' => '1234567890',
            'date' => '2026-02-16',
            'status' => 'completed',
            'items' => [],
            'message_id' => 'msg-ship-dup-001',
        ]);

        $job->fire();

        // Реализация не должна быть создана — дубликат проигнорирован
        $this->assertDatabaseMissing('shipments', [
            'uuid' => 's1a2b3c4-int-dup',
        ]);
    }

    #[Test]
    public function full_shipment_lifecycle_created_updated_deleted(): void
    {
        $product = Product::factory()->create(['external_id' => 'life-ship-prod-001']);

        // 1. shipment.created
        $createJob = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => 's-lifecycle-001',
            'contractor_inn' => '5555555555',
            'date' => '2026-02-16',
            'status' => 'new',
            'currency_code' => 'RUB',
            'items' => [
                ['product_uuid' => 'life-ship-prod-001', 'quantity' => 5, 'price' => 1000.00],
            ],
            'message_id' => 'msg-ship-life-create',
            'timestamp' => now()->toIso8601String(),
        ]);
        $createJob->fire();

        $shipment = \App\Models\Shipment::where('uuid', 's-lifecycle-001')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals('new', $shipment->status);
        $this->assertCount(1, $shipment->items);
        $this->assertEquals(5000.00, (float) $shipment->total_amount);

        // 2. shipment.updated — изменение статуса и позиций
        $updateJob = $this->makeJob([
            'event' => 'shipment.updated',
            'uuid' => 's-lifecycle-001',
            'status' => 'completed',
            'items' => [
                ['product_uuid' => 'life-ship-prod-001', 'quantity' => 10, 'price' => 1500.00],
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
            'uuid' => 's-lifecycle-001',
            'message_id' => 'msg-ship-life-delete',
            'timestamp' => now()->toIso8601String(),
        ]);
        $deleteJob->fire();

        $this->assertSoftDeleted('shipments', ['uuid' => 's-lifecycle-001']);

        // 4. Дубль shipment.created (тот же message_id) — не должен обработаться
        $dupJob = $this->makeJob([
            'event' => 'shipment.created',
            'uuid' => 's-lifecycle-001',
            'contractor_inn' => '5555555555',
            'items' => [],
            'message_id' => 'msg-ship-life-create',
        ]);
        $dupJob->fire();

        // Проверяем что реализация осталась soft-deleted
        $shipment = \App\Models\Shipment::withTrashed()->where('uuid', 's-lifecycle-001')->first();
        $this->assertNotNull($shipment->deleted_at);
    }

    // ========================================================
    // US-13: category.* — синхронизация категорий
    // ========================================================

    #[Test]
    public function category_created_creates_category_in_database(): void
    {
        $job = $this->makeJob([
            'event'       => 'category.created',
            'uuid'        => 'cat-us13-001',
            'parent_uuid' => null,
            'name'        => 'Бельё и одежда',
            'is_group'    => true,
            'message_id'  => 'msg-cat-created-001',
            'timestamp'   => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('categories', [
            'uuid'     => 'cat-us13-001',
            'name'     => 'Бельё и одежда',
            'is_group' => true,
        ]);
        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-cat-created-001',
            'event'      => 'category.created',
        ]);
    }

    #[Test]
    public function category_created_with_parent_links_to_parent(): void
    {
        // Сначала создаём родительскую категорию
        $parentJob = $this->makeJob([
            'event'      => 'category.created',
            'uuid'       => 'cat-parent-001',
            'name'       => 'Корневая категория',
            'is_group'   => true,
            'message_id' => 'msg-cat-parent-001',
        ]);
        $parentJob->fire();

        $parent = \App\Models\Category::where('uuid', 'cat-parent-001')->first();
        $this->assertNotNull($parent);

        // Теперь создаём дочернюю категорию
        $childJob = $this->makeJob([
            'event'       => 'category.created',
            'uuid'        => 'cat-child-001',
            'parent_uuid' => 'cat-parent-001',
            'name'        => 'Дочерняя категория',
            'is_group'    => false,
            'message_id'  => 'msg-cat-child-001',
        ]);
        $childJob->fire();

        $child = \App\Models\Category::where('uuid', 'cat-child-001')->first();
        $this->assertNotNull($child);
        $this->assertEquals($parent->id, $child->parent_id);
    }

    #[Test]
    public function category_updated_updates_existing_category(): void
    {
        // Создаём исходную категорию
        $createJob = $this->makeJob([
            'event'      => 'category.created',
            'uuid'       => 'cat-upd-001',
            'name'       => 'Старое название',
            'is_group'   => false,
            'message_id' => 'msg-cat-upd-create',
        ]);
        $createJob->fire();

        // Обновляем через category.updated
        $updateJob = $this->makeJob([
            'event'      => 'category.updated',
            'uuid'       => 'cat-upd-001',
            'name'       => 'Новое название',
            'is_group'   => true,
            'message_id' => 'msg-cat-upd-update',
        ]);
        $updateJob->fire();

        $this->assertDatabaseHas('categories', [
            'uuid'     => 'cat-upd-001',
            'name'     => 'Новое название',
            'is_group' => true,
        ]);
        // Убедимся, что нет дублей
        $this->assertEquals(1, \App\Models\Category::where('uuid', 'cat-upd-001')->count());
    }

    #[Test]
    public function category_created_idempotency_prevents_duplicate(): void
    {
        ErpProcessedMessage::create([
            'message_id'   => 'msg-cat-dup-001',
            'event'        => 'category.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event'      => 'category.created',
            'uuid'       => 'cat-dup-001',
            'name'       => 'Дубликат категории',
            'message_id' => 'msg-cat-dup-001',
        ]);
        $job->fire();

        $this->assertDatabaseMissing('categories', ['uuid' => 'cat-dup-001']);
    }

    #[Test]
    public function category_created_without_required_fields_is_skipped(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствуют обязательные поля');
            });

        $job = $this->makeJob([
            'event'      => 'category.created',
            'uuid'       => null,
            'name'       => null,
            'message_id' => 'msg-cat-no-fields',
        ]);
        $job->fire();

        $this->assertEquals(0, \App\Models\Category::count());
    }

    // ========================================================
    // US-13: product.* — синхронизация товаров
    // ========================================================

    #[Test]
    public function product_created_creates_product_in_database(): void
    {
        // Создаём категорию для привязки
        \App\Models\Category::factory()->create([
            'uuid' => 'cat-for-product-001',
        ]);

        $job = $this->makeJob([
            'event'         => 'product.created',
            'uuid'          => 'prod-us13-001',
            'name'          => 'Вибро-яйцо XYZ',
            'code'          => '0T-123213',
            'sku'           => 'AAS-123213',
            'category_uuid' => 'cat-for-product-001',
            'brand'         => 'BrandTest',
            'description'   => 'Описание товара',
            'barcodes'      => ['4600000000001', '4600000000002'],
            'message_id'    => 'msg-prod-created-001',
            'timestamp'     => now()->toIso8601String(),
        ]);

        $job->fire();

        $this->assertDatabaseHas('products', [
            'external_id' => 'prod-us13-001',
            'name'        => 'Вибро-яйцо XYZ',
            'code'        => '0T-123213',
            'sku'         => 'AAS-123213',
        ]);

        $product = Product::where('external_id', 'prod-us13-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->category_id);
        $this->assertNotNull($product->brand_id);
        $this->assertCount(2, $product->barcodes);

        $this->assertDatabaseHas('erp_processed_messages', [
            'message_id' => 'msg-prod-created-001',
            'event'      => 'product.created',
        ]);
    }

    #[Test]
    public function product_created_syncs_barcodes(): void
    {
        $job = $this->makeJob([
            'event'      => 'product.created',
            'uuid'       => 'prod-barcodes-001',
            'name'       => 'Товар со штрих-кодами',
            'barcodes'   => ['111', '222', '333'],
            'message_id' => 'msg-prod-barcodes-001',
        ]);
        $job->fire();

        $product = Product::where('external_id', 'prod-barcodes-001')->first();
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
            'event'      => 'product.created',
            'uuid'       => 'prod-upd-barcodes',
            'name'       => 'Товар',
            'barcodes'   => ['aaa', 'bbb', 'ccc'],
            'message_id' => 'msg-prod-upd-barcodes-create',
        ]);
        $createJob->fire();

        // Обновление с новым набором штрих-кодов
        $updateJob = $this->makeJob([
            'event'      => 'product.updated',
            'uuid'       => 'prod-upd-barcodes',
            'name'       => 'Товар обновлён',
            'barcodes'   => ['xxx', 'yyy'],
            'message_id' => 'msg-prod-upd-barcodes-update',
        ]);
        $updateJob->fire();

        $product = Product::where('external_id', 'prod-upd-barcodes')->first();
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
            'event'      => 'product.created',
            'uuid'       => 'prod-with-model-001',
            'name'       => 'Товар с моделью',
            'model'      => [
                'uuid' => 'model-uuid-001',
                'name' => 'Модель товара А',
            ],
            'message_id' => 'msg-prod-model-001',
        ]);
        $job->fire();

        $this->assertDatabaseHas('product_models', [
            'external_id' => 'model-uuid-001',
            'name'        => 'Модель товара А',
        ]);

        $product = Product::where('external_id', 'prod-with-model-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->model_id);
    }

    #[Test]
    public function product_created_with_attributes_saves_attribute_values(): void
    {
        $job = $this->makeJob([
            'event'      => 'product.created',
            'uuid'       => 'prod-attrs-001',
            'name'       => 'Товар с атрибутами',
            'attributes' => [
                'weight'   => '150г',
                'color'    => 'розовый',
                'material' => 'силикон',
            ],
            'message_id' => 'msg-prod-attrs-001',
        ]);
        $job->fire();

        $product = Product::where('external_id', 'prod-attrs-001')->first();
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
            'message_id'   => 'msg-prod-dup-001',
            'event'        => 'product.created',
            'processed_at' => now(),
        ]);

        $job = $this->makeJob([
            'event'      => 'product.created',
            'uuid'       => 'prod-dup-001',
            'name'       => 'Дубликат товара',
            'message_id' => 'msg-prod-dup-001',
        ]);
        $job->fire();

        $this->assertDatabaseMissing('products', ['external_id' => 'prod-dup-001']);
    }

    #[Test]
    public function product_created_without_required_fields_is_skipped(): void
    {
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствуют обязательные поля');
            });

        $job = $this->makeJob([
            'event'      => 'product.created',
            'uuid'       => null,
            'name'       => null,
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
            'external_id' => 'prod-preserve-price',
            'base_price'  => 12345.00,
        ]);

        $job = $this->makeJob([
            'event'      => 'product.created',
            'uuid'       => 'prod-preserve-price',
            'name'       => 'Обновлённое имя',
            'message_id' => 'msg-prod-preserve-price',
        ]);
        $job->fire();

        $existing->refresh();
        // Цена должна сохраниться — price.updated управляет ценами (US-02)
        $this->assertEquals(12345.00, (float) $existing->base_price);
        $this->assertEquals('Обновлённое имя', $existing->name);
    }
}
