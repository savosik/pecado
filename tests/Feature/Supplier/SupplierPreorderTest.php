<?php

namespace Tests\Feature\Supplier;

use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Jobs\SendPreorderToSupplierJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplierPreorderRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Отправка предзаказов поставщику (Customer API sex-opt.ru, метод send_order).
 */
class SupplierPreorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sex_opt.order_api_url', 'https://api.sex-opt.ru/customer/api/v1');
        config()->set('services.sex_opt.order_api_key', 'test-key');
        config()->set('services.sex_opt.preorder.enabled', true);
        config()->set('services.sex_opt.preorder.stock', 'tmn');
        config()->set('services.sex_opt.preorder.testmode', false);
        config()->set('services.sex_opt.preorder.rollback_on_warnings', false);
    }

    /**
     * Предзаказ из двух позиций с кодами 1С.
     */
    private function preorder(array $attributes = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'type' => OrderType::PREORDER,
            'number' => 'ORD-2026-0042',
        ], $attributes));

        $first = Product::factory()->create(['code' => 'УТ-00007466']);
        $second = Product::factory()->create(['code' => '0T-00002013']);

        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $first->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $second->id, 'quantity' => 2]);

        return $order->fresh();
    }

    /**
     * Тело запроса, реально ушедшее поставщику.
     */
    private function sentPayload(): array
    {
        $sent = Http::recorded();

        $this->assertNotEmpty($sent, 'Запрос поставщику не отправлялся');

        /** @var \Illuminate\Http\Client\Request $request */
        $request = $sent[0][0];

        return json_decode($request->data()['post'], true);
    }

    #[Test]
    public function preorder_creation_queues_supplier_job(): void
    {
        Queue::fake();

        $order = $this->preorder();
        OrderCreated::dispatch($order);

        Queue::assertPushed(SendPreorderToSupplierJob::class,
            fn (SendPreorderToSupplierJob $job) => $job->orderId === $order->id);
    }

    #[Test]
    public function regular_order_is_not_sent_to_supplier(): void
    {
        Queue::fake();

        $order = $this->preorder(['type' => OrderType::ORDER]);
        OrderCreated::dispatch($order);

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function order_arrived_from_erp_is_not_sent_to_supplier(): void
    {
        Queue::fake();

        $order = $this->preorder();
        $order->fromErp = true;
        OrderCreated::dispatch($order);

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function nothing_is_queued_when_integration_disabled(): void
    {
        config()->set('services.sex_opt.preorder.enabled', false);
        Queue::fake();

        OrderCreated::dispatch($this->preorder());

        Queue::assertNotPushed(SendPreorderToSupplierJob::class);
    }

    #[Test]
    public function job_sends_items_stock_and_reserve_comment(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 999999, 'total_price' => 8800, 'total_items' => 5],
                'transaction' => 'commit',
            ]),
        ]);

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $payload = $this->sentPayload();

        $this->assertSame('test-key', $payload['api_key']);
        $this->assertSame('send_order', $payload['method']);
        $this->assertSame('tmn', $payload['data']['stock']);
        $this->assertSame('предзаказ ORD-2026-0042 поставьте в резерв.', $payload['data']['comment']);
        $this->assertFalse($payload['data']['testmode']);
        $this->assertSame(
            ['УТ-00007466' => 3, '0T-00002013' => 2],
            $payload['data']['items'],
        );

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_SUCCESS, $record->status);
        $this->assertSame('999999', $record->supplier_order_id);
        $this->assertSame(2, $record->items_count);
        $this->assertSame('tmn', $record->stock);
        $this->assertNull($record->error_message);
        // Ответ поставщика сохранён целиком — админка показывает его как есть
        $this->assertSame('commit', $record->response_payload['transaction']);
    }

    #[Test]
    public function testmode_response_is_logged_as_testmode(): void
    {
        config()->set('services.sex_opt.preorder.testmode', true);

        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 123, 'total_price' => 100, 'total_items' => 1],
                'transaction' => 'rollback',
            ]),
        ]);

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $payload = $this->sentPayload();
        $this->assertTrue($payload['data']['testmode']);

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_TESTMODE, $record->status);
        $this->assertTrue($record->testmode);
    }

    #[Test]
    public function rollback_transaction_is_logged_as_rollback(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 555, 'total_price' => 0, 'total_items' => 0],
                'warnings' => ['unknown_items' => ['0T-2015']],
                'transaction' => 'rollback',
            ]),
        ]);

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_ROLLBACK, $record->status);
        $this->assertSame(['0T-2015'], $record->warnings()['unknown_items']);
    }

    #[Test]
    public function api_error_is_logged_as_failed(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'error',
                'error' => ['code' => 'auth_error', 'message' => 'Неверный ключ API'],
            ]),
        ]);

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_FAILED, $record->status);
        $this->assertStringContainsString('Неверный ключ API', $record->error_message);
        $this->assertNull($record->supplier_order_id);
    }

    #[Test]
    public function http_failure_is_logged_as_failed(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_FAILED, $record->status);
        $this->assertSame(503, $record->http_status);
        $this->assertSame('Service Unavailable', $record->response_raw);
    }

    #[Test]
    public function items_without_erp_code_are_reported_and_nothing_is_sent(): void
    {
        Http::fake();

        $order = Order::factory()->create(['type' => OrderType::PREORDER, 'number' => 'ORD-2026-0100']);
        $product = Product::factory()->create(['code' => null, 'sku' => null]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1]);

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        Http::assertNothingSent();

        $record = SupplierPreorderRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(SupplierPreorderRequest::STATUS_FAILED, $record->status);
        $this->assertCount(1, $record->skipped_items);
        $this->assertSame(0, $record->items_count);
    }

    #[Test]
    public function successfully_sent_preorder_is_not_sent_twice(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 777],
                'transaction' => 'commit',
            ]),
        ]);

        $order = $this->preorder();
        $sender = app(\App\Services\Supplier\SupplierPreorderSender::class);

        (new SendPreorderToSupplierJob($order->id))->handle($sender);
        (new SendPreorderToSupplierJob($order->id))->handle($sender);

        $this->assertSame(1, SupplierPreorderRequest::where('order_id', $order->id)->count());

        // Ручная переотправка (force) разрешена — менеджер видит лог и решает сам
        (new SendPreorderToSupplierJob($order->id, null, true))->handle($sender);

        $records = SupplierPreorderRequest::where('order_id', $order->id)->orderBy('attempt')->get();
        $this->assertCount(2, $records);
        $this->assertSame([1, 2], $records->pluck('attempt')->all());
    }

    #[Test]
    public function job_does_nothing_when_integration_disabled(): void
    {
        config()->set('services.sex_opt.preorder.enabled', false);
        Http::fake();

        $order = $this->preorder();

        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        Http::assertNothingSent();
        $this->assertDatabaseCount('supplier_preorder_requests', 0);
    }

    #[Test]
    public function admin_journal_shows_supplier_responses(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'order' => ['id' => 4242],
                'transaction' => 'commit',
            ]),
        ]);

        $order = $this->preorder();
        (new SendPreorderToSupplierJob($order->id))->handle(app(\App\Services\Supplier\SupplierPreorderSender::class));

        $user = User::factory()->create();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'supplier-preorders.view', 'guard_name' => 'web'])
        );

        $this->actingAs($user)
            ->get('/admin/supplier-preorders')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Admin/Pages/SupplierPreorders/Index')
                ->where('requests.data.0.supplier_order_id', '4242')
                ->where('requests.data.0.status', 'success')
                ->where('stats.success', 1)
            );
    }

    #[Test]
    public function order_card_shows_supplier_panel_only_for_preorders(): void
    {
        $user = User::factory()->create();
        foreach (['orders.view', 'supplier-preorders.view'] as $name) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $preorder = $this->preorder();
        $this->actingAs($user)
            ->get("/admin/orders/{$preorder->id}")
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('supplierPreorder.stock', 'tmn')
                ->where('supplierPreorder.enabled', true)
            );

        $regular = $this->preorder(['type' => OrderType::ORDER, 'number' => 'ORD-2026-0043']);
        $this->actingAs($user)
            ->get("/admin/orders/{$regular->id}")
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('supplierPreorder', null));
    }

    #[Test]
    public function admin_can_resend_preorder_from_order_card(): void
    {
        Queue::fake();

        $order = $this->preorder();

        $user = User::factory()->create();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'supplier-preorders.send', 'guard_name' => 'web'])
        );

        $this->actingAs($user)
            ->post("/admin/orders/{$order->id}/send-to-supplier")
            ->assertRedirect();

        Queue::assertPushed(SendPreorderToSupplierJob::class, fn (SendPreorderToSupplierJob $job) => $job->orderId === $order->id
            && $job->force === true
            && $job->triggeredBy === $user->id);
    }
}
