<?php

namespace Tests\Feature\Erp;

use App\Events\ReturnCreated;
use App\Jobs\PublishReturnToErpJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnCreatedEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function return_created_event_dispatches_publish_job_with_correct_payload(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create([
            'erp_id' => 'partner-ret-evt-001',
        ]);

        $product = Product::factory()->create([
            'external_id' => 'product-ret-evt-001',
        ]);

        $company = Company::create([
            'user_id' => $user->id,
            'name' => 'Test Company',
            'legal_name' => 'ООО Тест',
            'tax_id' => '1234567890',
            'country' => 'RU',
        ]);

        $order = Order::create([
            'uuid' => 'order-ret-evt-uuid-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
            'total_amount' => 6000,
            'type' => 'order',
        ]);

        $return = ProductReturn::create([
            'uuid' => 'return-evt-uuid-001',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
            'total_amount' => 6000,
        ]);

        $return->items()->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'quantity' => 2,
            'reason' => 'defective',
            'price' => 3000,
            'subtotal' => 6000,
        ]);

        // Dispatch event manually (as controllers do)
        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            $payload = $job->payload;

            return $payload['event'] === 'return.created'
                && $payload['uuid'] === 'return-evt-uuid-001'
                && $payload['order_uuid'] === 'order-ret-evt-uuid-001'
                && $payload['partner_uuid'] === 'partner-ret-evt-001'
                && count($payload['items']) === 1
                && $payload['items'][0]['product_uuid'] === 'product-ret-evt-001'
                && $payload['items'][0]['quantity'] === 2
                && $payload['items'][0]['reason'] === 'defective';
        });
    }

    #[Test]
    public function return_created_event_handles_null_order_gracefully(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create([
            'erp_id' => 'partner-ret-null-001',
        ]);

        $product = Product::factory()->create([
            'external_id' => 'product-ret-null-001',
        ]);

        // Return without order (order_id = null)
        $return = ProductReturn::create([
            'uuid' => 'return-null-order-001',
            'user_id' => $user->id,
            'order_id' => null,
            'status' => 'pending',
            'total_amount' => 3000,
        ]);

        $return->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'changed_mind',
            'price' => 3000,
            'subtotal' => 3000,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            $payload = $job->payload;

            return $payload['event'] === 'return.created'
                && $payload['order_uuid'] === null
                && $payload['partner_uuid'] === 'partner-ret-null-001'
                && count($payload['items']) === 1;
        });
    }

    #[Test]
    public function return_created_event_includes_multiple_items(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create([
            'erp_id' => 'partner-ret-multi-001',
        ]);

        $product1 = Product::factory()->create(['external_id' => 'product-ret-multi-001']);
        $product2 = Product::factory()->create(['external_id' => 'product-ret-multi-002']);

        $company = Company::create([
            'user_id' => $user->id,
            'name' => 'Multi Test',
            'legal_name' => 'ООО Мульти',
            'tax_id' => '9876543210',
            'country' => 'RU',
        ]);

        $order = Order::create([
            'uuid' => 'order-ret-multi-uuid-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => 'pending',
            'total_amount' => 15000,
            'type' => 'order',
        ]);

        $return = ProductReturn::create([
            'uuid' => 'return-multi-uuid-001',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
            'total_amount' => 9000,
        ]);

        $return->items()->create([
            'product_id' => $product1->id,
            'order_id' => $order->id,
            'quantity' => 2,
            'reason' => 'defective',
            'price' => 3000,
            'subtotal' => 6000,
        ]);

        $return->items()->create([
            'product_id' => $product2->id,
            'order_id' => $order->id,
            'quantity' => 1,
            'reason' => 'wrong_item',
            'price' => 3000,
            'subtotal' => 3000,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            $payload = $job->payload;

            return $payload['event'] === 'return.created'
                && count($payload['items']) === 2;
        });
    }

    #[Test]
    public function return_created_event_handles_user_without_erp_id(): void
    {
        Queue::fake([PublishReturnToErpJob::class]);

        $user = User::factory()->create([
            'erp_id' => null,
        ]);

        $return = ProductReturn::create([
            'uuid' => 'return-no-erp-001',
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 0,
        ]);

        event(new ReturnCreated($return));

        Queue::assertPushed(PublishReturnToErpJob::class, function ($job) {
            return $job->payload['partner_uuid'] === null;
        });
    }
}
