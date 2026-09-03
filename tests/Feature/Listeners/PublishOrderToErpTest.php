<?php

namespace Tests\Feature\Listeners;

use App\Events\OrderCreated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
/**
 * US-06: При отправке заказа в 1С, данные контрагента включаются в payload.
 * 1С сопоставляет контрагента по ИНН.
 */
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishOrderToErpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_created_dispatches_job_with_contractor_data(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'test-user-erp-id']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'name' => 'ООО Тестовая',
            'legal_name' => 'ООО Тестовая компания',
            'tax_id' => '7701234567',
        ]);

        CompanyBankAccount::factory()->create([
            'company_id' => $company->id,
            'bank_name' => 'Сбербанк',
            'account_number' => '40702810100000000001',
            'is_primary' => true,
        ]);

        $product = Product::factory()->create(['external_id' => 'test-ext-prod-001']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 5,
            'price' => 1000.00,
            'subtotal' => 5000.00,
        ]);

        Queue::fake(); // сброс после User creation

        // Вызов listener'а вручную
        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $payload = $job->payload;

            // Проверяем формат события
            if ($payload['event'] !== 'order.created') {
                return false;
            }

            // Проверяем данные контрагента
            if (! isset($payload['contractor'])) {
                return false;
            }
            if ($payload['contractor']['tax_id'] !== '7701234567') {
                return false;
            }
            if ($payload['contractor']['name'] !== 'ООО Тестовая') {
                return false;
            }
            if ($payload['contractor']['legal_name'] !== 'ООО Тестовая компания') {
                return false;
            }

            // Банковские реквизиты
            if (empty($payload['contractor']['bank_accounts'])) {
                return false;
            }
            if ($payload['contractor']['bank_accounts'][0]['bank_name'] !== 'Сбербанк') {
                return false;
            }

            // Позиции заказа
            if (empty($payload['items'])) {
                return false;
            }
            if ($payload['items'][0]['product_uuid'] !== 'test-ext-prod-001') {
                return false;
            }

            return true;
        });
    }

    #[Test]
    public function order_created_payload_includes_message_id(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'msg-id-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $messageId = $job->payload['message_id'] ?? null;

            return is_string($messageId)
                && str_starts_with($messageId, 'msg-')
                && preg_match('/^msg-[0-9a-f-]{36}$/', $messageId) === 1;
        });
    }

    /**
     * v16.9.0 (режим «Заказы в резерве»): модельный OrderUpdated листенер игнорирует —
     * исходящий order.updated несёт обязательный base_items_version и публикуется
     * только явно через Erp\OrderReservePublisher из действий клиента.
     */
    #[Test]
    public function order_updated_model_event_is_ignored(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'msg-id-upd-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new \App\Events\OrderUpdated($order));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    /**
     * v16.9.0 (режим «Заказы в резерве»): модельный OrderDeleted листенер игнорирует —
     * исходящий order.deleted несёт обязательный reason (client_cancelled |
     * reserve_expired) и публикуется только явно через Erp\OrderReservePublisher.
     */
    #[Test]
    public function order_deleted_model_event_is_ignored(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'msg-id-del-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new \App\Events\OrderDeleted($order));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function order_created_payload_includes_partner_uuid(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'partner-erp-uuid-test']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['partner_uuid'] === 'partner-erp-uuid-test';
        });
    }

    #[Test]
    public function order_created_contractor_without_bank_accounts(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '9901234567',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $payload = $job->payload;

            return isset($payload['contractor'])
                && $payload['contractor']['tax_id'] === '9901234567'
                && empty($payload['contractor']['bank_accounts']);
        });
    }

    #[Test]
    public function order_created_payload_includes_number_and_type(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'type-number-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'number' => 'ORD-2026-0042',
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $payload = $job->payload;

            return $payload['number'] === 'ORD-2026-0042'
                && $payload['type'] === 'order';
        });
    }

    #[Test]
    public function order_created_payload_preorder_type(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'preorder-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::PREORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['type'] === 'preorder';
        });
    }

    #[Test]
    public function order_created_payload_includes_currency_and_exchange_data(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'currency-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'currency_code' => 'KZT',
            'exchange_rate' => 5.45,
            'rate_coefficient' => 1.05,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $payload = $job->payload;

            return $payload['currency_code'] === 'KZT'
                && abs($payload['exchange_rate'] - 5.45) < 0.001
                && abs($payload['rate_coefficient'] - 1.05) < 0.001;
        });
    }

    #[Test]
    public function order_created_payload_includes_delivery_address(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'delivery-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address' => 'г. Алматы, ул. Абая, 10',
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['delivery_address'] === 'г. Алматы, ул. Абая, 10';
        });
    }

    #[Test]
    public function order_created_payload_includes_items_with_product_uuid(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'items-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product1 = Product::factory()->create(['external_id' => 'items-test-prod-001']);
        $product2 = Product::factory()->create(['external_id' => 'items-test-prod-002']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'name' => $product1->name,
            'quantity' => 5,
            'price' => 1000.00,
            'subtotal' => 5000.00,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'name' => $product2->name,
            'quantity' => 3,
            'price' => 2000.00,
            'subtotal' => 6000.00,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $items = $job->payload['items'];

            return count($items) === 2
                && $items[0]['product_uuid'] === 'items-test-prod-001'
                && $items[0]['quantity'] === 5
                && $items[0]['base_price'] === 1000.00
                && $items[0]['final_price'] === 1000.00
                && $items[1]['product_uuid'] === 'items-test-prod-002'
                && $items[1]['quantity'] === 3
                && $items[1]['base_price'] === 2000.00
                && $items[1]['final_price'] === 2000.00;
        });
    }

    #[Test]
    public function order_created_payload_includes_warehouse_uuids_for_order_type(): void
    {
        Queue::fake();

        $region = Region::factory()->create();
        $warehouse1 = Warehouse::factory()->create(['external_id' => 'wh-primary-001']);
        $warehouse2 = Warehouse::factory()->create(['external_id' => 'wh-primary-002']);
        $region->primaryWarehouses()->attach([
            $warehouse1->id => ['type' => 'primary'],
            $warehouse2->id => ['type' => 'primary'],
        ]);

        $user = User::factory()->create(['erp_id' => 'wh-test-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? null;

            return is_array($uuids)
                && in_array('wh-primary-001', $uuids)
                && in_array('wh-primary-002', $uuids);
        });
    }

    #[Test]
    public function order_created_payload_includes_warehouse_uuids_for_preorder_type(): void
    {
        Queue::fake();

        $region = Region::factory()->create();
        $warehouse = Warehouse::factory()->create(['external_id' => 'wh-preorder-001']);
        $region->preorderWarehouses()->attach($warehouse->id, ['type' => 'preorder']);

        $user = User::factory()->create(['erp_id' => 'wh-preorder-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::PREORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? null;

            return is_array($uuids)
                && in_array('wh-preorder-001', $uuids)
                && ! in_array('wh-primary-001', $uuids);
        });
    }

    /**
     * ⚠️ КОСТЫЛЬ: для предзаказа UUID склада «Тюмень Основной» (source)
     * подменяется на target в массиве warehouse_uuids.
     */
    #[Test]
    public function preorder_warehouse_uuid_override_replaces_tyumen_uuid(): void
    {
        Queue::fake();

        config()->set('erp.preorder_warehouse_uuid_override', [
            'enabled' => true,
            'source_uuid' => 'tyumen-source-uuid',
            'target_uuid' => 'tyumen-target-uuid',
        ]);

        $region = Region::factory()->create();
        $tyumen = Warehouse::factory()->create(['external_id' => 'tyumen-source-uuid']);
        $other = Warehouse::factory()->create(['external_id' => 'wh-preorder-other']);
        $region->preorderWarehouses()->attach([
            $tyumen->id => ['type' => 'preorder'],
            $other->id => ['type' => 'preorder'],
        ]);

        $user = User::factory()->create(['erp_id' => 'preorder-override-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::PREORDER,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? [];

            return in_array('tyumen-target-uuid', $uuids, true)
                && ! in_array('tyumen-source-uuid', $uuids, true)
                && in_array('wh-preorder-other', $uuids, true);
        });
    }

    /**
     * ⚠️ КОСТЫЛЬ не затрагивает обычные заказы (type = order) —
     * UUID Тюмени остаётся исходным.
     */
    #[Test]
    public function order_type_is_not_affected_by_preorder_warehouse_override(): void
    {
        Queue::fake();

        config()->set('erp.preorder_warehouse_uuid_override', [
            'enabled' => true,
            'source_uuid' => 'tyumen-source-uuid',
            'target_uuid' => 'tyumen-target-uuid',
        ]);

        $region = Region::factory()->create();
        $tyumen = Warehouse::factory()->create(['external_id' => 'tyumen-source-uuid']);
        $region->primaryWarehouses()->attach($tyumen->id, ['type' => 'primary']);

        $user = User::factory()->create(['erp_id' => 'order-no-override-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? [];

            return in_array('tyumen-source-uuid', $uuids, true)
                && ! in_array('tyumen-target-uuid', $uuids, true);
        });
    }

    /**
     * ⚠️ КОСТЫЛЬ откатывается флагом enabled=false — подмена не выполняется.
     */
    #[Test]
    public function preorder_warehouse_uuid_override_disabled_keeps_source(): void
    {
        Queue::fake();

        config()->set('erp.preorder_warehouse_uuid_override', [
            'enabled' => false,
            'source_uuid' => 'tyumen-source-uuid',
            'target_uuid' => 'tyumen-target-uuid',
        ]);

        $region = Region::factory()->create();
        $tyumen = Warehouse::factory()->create(['external_id' => 'tyumen-source-uuid']);
        $region->preorderWarehouses()->attach($tyumen->id, ['type' => 'preorder']);

        $user = User::factory()->create(['erp_id' => 'preorder-disabled-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::PREORDER,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? [];

            return in_array('tyumen-source-uuid', $uuids, true)
                && ! in_array('tyumen-target-uuid', $uuids, true);
        });
    }

    #[Test]
    public function defect_order_uses_defect_warehouse_uuid(): void
    {
        Queue::fake();

        // Склад некондиции не привязан к региону — регион здесь не участвует.
        Warehouse::factory()->create(['external_id' => 'defect-wh-uuid', 'is_defect' => true]);
        Warehouse::factory()->create(['external_id' => 'primary-wh-uuid', 'is_defect' => false]);

        $user = User::factory()->create(['erp_id' => 'defect-erp', 'region_id' => null]);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::DEFECT,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? [];

            return ($job->payload['type'] ?? null) === 'defect'
                && in_array('defect-wh-uuid', $uuids, true)
                && ! in_array('primary-wh-uuid', $uuids, true);
        });
    }

    #[Test]
    public function defect_order_is_not_published_without_defect_warehouse_uuid(): void
    {
        // ⚠️ Блокер: склад некондиции без external_id — заказ не уходит в 1С.
        Queue::fake();

        Warehouse::factory()->create(['external_id' => null, 'is_defect' => true]);

        $user = User::factory()->create(['erp_id' => 'defect-noext-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::DEFECT,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }

    #[Test]
    public function order_created_payload_warehouse_uuids_empty_when_no_region(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'no-region-erp', 'region_id' => null]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return isset($job->payload['warehouse_uuids'])
                && $job->payload['warehouse_uuids'] === [];
        });
    }

    // v15: manager_comment / warehouse_comment
    // ──────────────────────────────────────────────

    #[Test]
    public function order_created_payload_includes_manager_and_warehouse_comments(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'comments-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'comment' => 'Общий комментарий',
            'manager_comment' => 'Клиент просил счёт на ИП',
            'warehouse_comment' => 'Упаковать без логотипа',
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['comment'] === 'Общий комментарий'
                && $job->payload['manager_comment'] === 'Клиент просил счёт на ИП'
                && $job->payload['warehouse_comment'] === 'Упаковать без логотипа';
        });
    }

    #[Test]
    public function order_created_payload_sends_null_when_comments_empty(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'empty-comments-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'manager_comment' => null,
            'warehouse_comment' => null,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return array_key_exists('manager_comment', $job->payload)
                && $job->payload['manager_comment'] === null
                && array_key_exists('warehouse_comment', $job->payload)
                && $job->payload['warehouse_comment'] === null;
        });
    }

    // v15.3: delivery_method (Самовывоз)
    // ──────────────────────────────────────────────

    #[Test]
    public function order_created_payload_includes_pickup_delivery_method_with_null_address(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'pickup-test-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_method' => \App\Enums\DeliveryMethod::PICKUP,
            'delivery_address' => null,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['delivery_method'] === 'pickup'
                && array_key_exists('delivery_address', $job->payload)
                && $job->payload['delivery_address'] === null;
        });
    }

    #[Test]
    public function order_created_payload_defaults_to_delivery_method_delivery(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'delivery-default-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);

        // Заказ без явного указания способа доставки (legacy-путь) — default колонки
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['delivery_method'] === 'delivery';
        });
    }

    #[Test]
    public function pickup_order_payload_passes_outbound_schema_validation(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'pickup-schema-erp']);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['external_id' => 'pickup-prod-001']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_method' => \App\Enums\DeliveryMethod::PICKUP,
            'delivery_address' => null,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 500.00,
            'subtotal' => 500.00,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp;
        $listener->handle($event);

        $validator = app(\App\Services\Erp\ErpMessageValidator::class);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) use ($validator) {
            $result = $validator->validateOutbound('order.created', $job->payload);

            return $result['valid'] === true;
        });
    }

    #[Test]
    public function payload_with_invalid_delivery_method_fails_outbound_schema_validation(): void
    {
        $validator = app(\App\Services\Erp\ErpMessageValidator::class);

        $payload = [
            'event' => 'order.created',
            'message_id' => 'msg-invalid-dm-test',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'delivery_method' => 'courier',
            'items' => [
                ['product_uuid' => 'x', 'quantity' => 1, 'base_price' => 1, 'discount_percent' => 0, 'final_price' => 1],
            ],
        ];

        $result = $validator->validateOutbound('order.created', $payload);

        $this->assertFalse($result['valid']);
    }
}
