<?php

namespace Tests\Feature\Listeners;

use App\Events\OrderCreated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * US-06: При отправке заказа в 1С, данные контрагента включаются в payload.
 * 1С сопоставляет контрагента по ИНН.
 */
use App\Models\Region;
use App\Models\Warehouse;

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
        $listener = new \App\Listeners\PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) use ($company) {
            $payload = $job->payload;

            // Проверяем формат события
            if ($payload['event'] !== 'order.created') return false;

            // Проверяем данные контрагента
            if (!isset($payload['contractor'])) return false;
            if ($payload['contractor']['tax_id'] !== '7701234567') return false;
            if ($payload['contractor']['name'] !== 'ООО Тестовая') return false;
            if ($payload['contractor']['legal_name'] !== 'ООО Тестовая компания') return false;

            // Банковские реквизиты
            if (empty($payload['contractor']['bank_accounts'])) return false;
            if ($payload['contractor']['bank_accounts'][0]['bank_name'] !== 'Сбербанк') return false;

            // Позиции заказа
            if (empty($payload['items'])) return false;
            if ($payload['items'][0]['product_uuid'] !== 'test-ext-prod-001') return false;

            return true;
        });
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $address = \App\Models\DeliveryAddress::factory()->create([
            'user_id' => $user->id,
            'address' => 'г. Алматы, ул. Абая, 10',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address_id' => $address->id,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp();
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
        $listener = new \App\Listeners\PublishOrderToErp();
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
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp();
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
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => \App\Enums\OrderType::PREORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? null;

            return is_array($uuids)
                && in_array('wh-preorder-001', $uuids)
                && !in_array('wh-primary-001', $uuids);
        });
    }

    #[Test]
    public function order_created_payload_warehouse_uuids_empty_when_no_region(): void
    {
        Queue::fake();

        $user = User::factory()->create(['erp_id' => 'no-region-erp', 'region_id' => null]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'type'       => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        $event = new OrderCreated($order);
        $listener = new \App\Listeners\PublishOrderToErp();
        $listener->handle($event);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return isset($job->payload['warehouse_uuids'])
                && $job->payload['warehouse_uuids'] === [];
        });
    }
}
