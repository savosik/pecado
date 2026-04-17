<?php

namespace Tests\Feature;

use App\Jobs\BulkDeleteJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\ProductReturn;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    // --- Orders ---

    #[Test]
    public function orders_index_shows_only_active_by_default(): void
    {
        $active = Order::factory()->create();
        $deleted = Order::factory()->create();
        $deleted->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Orders/Index', false)
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $active->id)
            );
    }

    #[Test]
    public function orders_index_shows_only_trashed_when_filter_applied(): void
    {
        $active = Order::factory()->create();
        $deleted = Order::factory()->create();
        $deleted->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['trashed' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Orders/Index', false)
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $deleted->id)
            );
    }

    #[Test]
    public function order_force_delete_permanently_removes_trashed_record(): void
    {
        $order = Order::factory()->create();
        $order->delete();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.force-delete', $order->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    #[Test]
    public function order_force_delete_rejects_active_records(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.force-delete', $order->id))
            ->assertNotFound();
    }

    #[Test]
    public function bulk_force_delete_all_orders_dispatches_job(): void
    {
        Queue::fake();

        Order::factory()->create()->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.bulk-force-delete-all', 'orders'))
            ->assertRedirect();

        Queue::assertPushed(BulkDeleteJob::class, function ($job) {
            return $job->method === 'forceDelete' && str_contains($job->resourceSlug, 'orders');
        });
    }

    // --- Returns ---

    #[Test]
    public function returns_index_shows_trashed_when_filter_applied(): void
    {
        $return = ProductReturn::factory()->create();
        $return->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.returns.index', ['trashed' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Returns/Index', false)
                ->where('returns.data.0.id', $return->id)
            );
    }

    #[Test]
    public function return_force_delete_permanently_removes_trashed_record(): void
    {
        $return = ProductReturn::factory()->create();
        $return->delete();

        $this->assertSoftDeleted('returns', ['id' => $return->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.returns.force-delete', $return->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('returns', ['id' => $return->id]);
    }

    #[Test]
    public function bulk_force_delete_all_returns_dispatches_job(): void
    {
        Queue::fake();

        ProductReturn::factory()->create()->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.bulk-force-delete-all', 'returns'))
            ->assertRedirect();

        Queue::assertPushed(BulkDeleteJob::class, function ($job) {
            return $job->method === 'forceDelete' && str_contains($job->resourceSlug, 'returns');
        });
    }

    // --- Shipments ---

    #[Test]
    public function shipments_index_shows_trashed_when_filter_applied(): void
    {
        $shipment = Shipment::factory()->create();
        $shipment->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.shipments.index', ['trashed' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Shipments/Index', false)
                ->where('shipments.data.0.id', $shipment->id)
            );
    }

    #[Test]
    public function shipment_force_delete_permanently_removes_trashed_record(): void
    {
        $shipment = Shipment::factory()->create();
        $shipment->delete();

        $this->assertSoftDeleted('shipments', ['id' => $shipment->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.shipments.force-delete', $shipment->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
    }

    #[Test]
    public function bulk_force_delete_all_shipments_dispatches_job(): void
    {
        Queue::fake();

        Shipment::factory()->create()->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.bulk-force-delete-all', 'shipments'))
            ->assertRedirect();

        Queue::assertPushed(BulkDeleteJob::class, function ($job) {
            return $job->method === 'forceDelete' && str_contains($job->resourceSlug, 'shipments');
        });
    }

    // --- Companies ---

    #[Test]
    public function companies_index_shows_trashed_when_filter_applied(): void
    {
        $company = Company::factory()->create(['user_id' => User::factory()->create()->id]);
        $company->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.companies.index', ['trashed' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Companies/Index', false)
                ->where('companies.data.0.id', $company->id)
            );
    }

    #[Test]
    public function company_force_delete_permanently_removes_trashed_record(): void
    {
        $company = Company::factory()->create(['user_id' => User::factory()->create()->id]);
        $company->delete();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.companies.force-delete', $company->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    #[Test]
    public function bulk_force_delete_all_companies_dispatches_job(): void
    {
        Queue::fake();

        $company = Company::factory()->create(['user_id' => User::factory()->create()->id]);
        $company->delete();

        $this->actingAs($this->admin)
            ->delete(route('admin.bulk-force-delete-all', 'companies'))
            ->assertRedirect();

        Queue::assertPushed(BulkDeleteJob::class, function ($job) {
            return $job->method === 'forceDelete' && str_contains($job->resourceSlug, 'companies');
        });
    }
}
