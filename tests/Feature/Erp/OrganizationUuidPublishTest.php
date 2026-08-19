<?php

namespace Tests\Feature\Erp;

use App\Events\OrderCreated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * organization_uuid в исходящем order.created (v16.6.0) — опциональная
 * подсказка сайта по привязке склад→организация. Переходный флаг
 * ERP_ORGANIZATION_UUID_ENABLED, независимый от stack_warehouse_pinning:
 * выключен по умолчанию, поле не появляется в payload вовсе.
 */
class OrganizationUuidPublishTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function поле_отсутствует_в_payload_пока_флаг_выключен(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create(['external_id' => 'org-flag-off-uuid']);
        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $region->primaryWarehouses()->attach($warehouse->id, ['type' => 'primary', 'priority' => 1]);

        $user = User::factory()->create(['erp_id' => 'org-flag-off-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
            'assigned_warehouse_id' => $warehouse->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return ! array_key_exists('organization_uuid', $job->payload);
        });
    }

    #[Test]
    public function включённый_флаг_отдаёт_организацию_зафиксированного_склада(): void
    {
        Queue::fake();

        config()->set('erp.organization_uuid_publishing.enabled', true);

        $organization = Organization::factory()->create(['external_id' => 'org-assigned-uuid']);
        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $region->primaryWarehouses()->attach($warehouse->id, ['type' => 'primary', 'priority' => 1]);

        $user = User::factory()->create(['erp_id' => 'org-assigned-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
            'assigned_warehouse_id' => $warehouse->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['organization_uuid'] === 'org-assigned-uuid';
        });
    }

    #[Test]
    public function независим_от_флага_фиксации_склада(): void
    {
        Queue::fake();

        // Флаг организации включён, флаг фиксации склада — нет: warehouse_uuids
        // уйдёт легаси-перечислением, но organization_uuid всё равно определится
        // по факту зафиксированного на сайте склада.
        config()->set('erp.organization_uuid_publishing.enabled', true);
        config()->set('erp.stack_warehouse_pinning.enabled', false);

        $organization = Organization::factory()->create(['external_id' => 'org-independent-uuid']);
        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $organization->id, 'external_id' => 'wh-independent']);
        $region->primaryWarehouses()->attach($warehouse->id, ['type' => 'primary', 'priority' => 1]);

        $user = User::factory()->create(['erp_id' => 'org-independent-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
            'assigned_warehouse_id' => $warehouse->id,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            $uuids = $job->payload['warehouse_uuids'] ?? [];

            // Легаси-перечисление складов (пинning выключен)...
            return count($uuids) === 1 && $uuids[0] === 'wh-independent'
                // ...но организация всё равно подсказана.
                && $job->payload['organization_uuid'] === 'org-independent-uuid';
        });
    }

    #[Test]
    public function без_зафиксированного_склада_и_нескольких_организаций_отдаёт_null(): void
    {
        Queue::fake();

        config()->set('erp.organization_uuid_publishing.enabled', true);

        $orgA = Organization::factory()->create(['external_id' => 'org-a-uuid']);
        $orgB = Organization::factory()->create(['external_id' => 'org-b-uuid']);

        $region = Region::factory()->create(); // без стопки
        $warehouseA = Warehouse::factory()->create(['organization_id' => $orgA->id]);
        $warehouseB = Warehouse::factory()->create(['organization_id' => $orgB->id]);
        $region->primaryWarehouses()->attach([
            $warehouseA->id => ['type' => 'primary'],
            $warehouseB->id => ['type' => 'primary'],
        ]);

        $user = User::factory()->create(['erp_id' => 'org-ambiguous-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
            'assigned_warehouse_id' => null,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return array_key_exists('organization_uuid', $job->payload)
                && $job->payload['organization_uuid'] === null;
        });
    }

    #[Test]
    public function несколько_складов_одной_организации_дают_её_uuid(): void
    {
        Queue::fake();

        config()->set('erp.organization_uuid_publishing.enabled', true);

        $organization = Organization::factory()->create(['external_id' => 'org-shared-uuid']);
        $region = Region::factory()->create(); // без стопки — несколько складов
        $w1 = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $w2 = Warehouse::factory()->create(['organization_id' => $organization->id]);
        $region->primaryWarehouses()->attach([
            $w1->id => ['type' => 'primary'],
            $w2->id => ['type' => 'primary'],
        ]);

        $user = User::factory()->create(['erp_id' => 'org-shared-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) {
            return $job->payload['organization_uuid'] === 'org-shared-uuid';
        });
    }

    #[Test]
    public function payload_с_organization_uuid_проходит_валидацию_схемы(): void
    {
        Queue::fake();

        config()->set('erp.organization_uuid_publishing.enabled', true);

        $organization = Organization::factory()->create(['external_id' => 'org-schema-uuid']);
        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $organization->id,
            'external_id' => 'wh-schema-uuid',
        ]);
        $region->primaryWarehouses()->attach($warehouse->id, ['type' => 'primary', 'priority' => 1]);

        $user = User::factory()->create(['erp_id' => 'org-schema-erp', 'region_id' => $region->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create(['external_id' => 'org-schema-prod']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'type' => \App\Enums\OrderType::ORDER,
            'assigned_warehouse_id' => $warehouse->id,
        ]);

        \App\Models\OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        Queue::fake();

        (new \App\Listeners\PublishOrderToErp)->handle(new OrderCreated($order));

        $validator = app(\App\Services\Erp\ErpMessageValidator::class);

        Queue::assertPushed(PublishOrderToErpJob::class, function ($job) use ($validator) {
            return $validator->validateOutbound('order.created', $job->payload)['valid'] === true
                && $job->payload['organization_uuid'] === 'org-schema-uuid';
        });
    }
}
