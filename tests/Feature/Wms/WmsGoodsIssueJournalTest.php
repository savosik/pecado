<?php

namespace Tests\Feature\Wms;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssuePackage;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsGoodsIssueJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function guest_cannot_open_the_journal(): void
    {
        $this->get('/wms/goods-issues')->assertRedirect('/login');
    }

    #[Test]
    public function client_without_roles_cannot_open_the_journal(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms/goods-issues')
            ->assertRedirect('/');
    }

    #[Test]
    public function both_warehouse_roles_can_open_the_journal(): void
    {
        // Расходный ордер — основной рабочий документ и кладовщика, и начальника склада.
        foreach (['warehouse-head', 'storekeeper'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/wms/goods-issues')
                ->assertOk();
        }
    }

    #[Test]
    public function journal_lists_orders_with_labels(): void
    {
        GoodsIssue::factory()->create([
            'number' => 'УТ-00009419',
            'status' => GoodsIssue::STATUS_TO_PICK,
            'recipient_name' => 'Интернет Решения ООО, г.Москва',
        ]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/GoodsIssues/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'УТ-00009419')
                ->where('orders.data.0.status_label', 'К отбору')
                ->where('orders.data.0.recipient', 'Интернет Решения ООО, г.Москва')
            );
    }

    #[Test]
    public function status_filter_accepts_multiple_values(): void
    {
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_TO_PICK]);
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_TO_SHIP]);
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_SHIPPED]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?statuses[]=to_pick&statuses[]=to_ship')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 2));
    }

    #[Test]
    public function status_counters_ignore_the_status_filter(): void
    {
        // Иначе выбранный статус показывал бы своё число, а остальные плитки — нули,
        // и переключаться между статусами стало бы невозможно.
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_TO_PICK]);
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_SHIPPED]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?statuses[]=to_pick')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('stats.total', 2)
            );
    }

    #[Test]
    public function search_finds_order_by_related_order_number(): void
    {
        $matching = GoodsIssue::factory()->create(['number' => 'УТ-1']);
        GoodsIssueItem::factory()->create([
            'goods_issue_id' => $matching->id,
            'order_number' => '30УТ-000213',
        ]);

        GoodsIssue::factory()->create(['number' => 'УТ-2']);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?search=30%D0%A3%D0%A2-000213')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'УТ-1')
            );
    }

    #[Test]
    public function stale_filter_returns_only_orders_stuck_in_status(): void
    {
        GoodsIssue::factory()->stale()->create(['number' => 'УТ-stale']);
        GoodsIssue::factory()->create([
            'number' => 'УТ-fresh',
            'status' => GoodsIssue::STATUS_TO_PICK,
            'status_changed_at' => now(),
        ]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?stale=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'УТ-stale')
                ->where('orders.data.0.is_stale', true)
            );
    }

    #[Test]
    public function shipped_order_is_never_stale(): void
    {
        // Документ закрыт — время в статусе смысла не имеет.
        GoodsIssue::factory()->create([
            'status' => GoodsIssue::STATUS_SHIPPED,
            'status_changed_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?stale=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));
    }

    #[Test]
    public function warehouse_filter_narrows_the_list(): void
    {
        $warehouse = Warehouse::factory()->create();
        GoodsIssue::factory()->create(['warehouse_id' => $warehouse->id]);
        GoodsIssue::factory()->create(['warehouse_id' => null]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?warehouse_ids[]='.$warehouse->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));
    }

    #[Test]
    public function unresolved_filter_finds_orders_with_items_outside_catalog(): void
    {
        GoodsIssue::factory()->create(['number' => 'УТ-ok', 'unresolved_items_count' => 0]);
        GoodsIssue::factory()->create(['number' => 'УТ-broken', 'unresolved_items_count' => 3]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues?unresolved=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.number', 'УТ-broken')
            );
    }

    #[Test]
    public function card_groups_items_by_related_order(): void
    {
        $goodsIssue = GoodsIssue::factory()->create();

        GoodsIssueItem::factory()->create([
            'goods_issue_id' => $goodsIssue->id,
            'line_number' => 1,
            'order_uuid' => 'order-a',
            'order_number' => '30УТ-000213',
        ]);
        GoodsIssueItem::factory()->create([
            'goods_issue_id' => $goodsIssue->id,
            'line_number' => 2,
            'order_uuid' => 'order-a',
            'order_number' => '30УТ-000213',
        ]);
        GoodsIssueItem::factory()->create([
            'goods_issue_id' => $goodsIssue->id,
            'line_number' => 3,
            'order_uuid' => 'order-b',
            'order_number' => '30УТ-000999',
        ]);

        GoodsIssuePackage::factory()->create([
            'goods_issue_id' => $goodsIssue->id,
            'number' => 1,
            'positions_count' => 2,
        ]);

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues/'.$goodsIssue->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/GoodsIssues/Show')
                ->has('order.groups', 2)
                ->has('order.groups.0.items', 2)
                ->has('order.groups.1.items', 1)
                ->has('order.packages', 1)
            );
    }

    #[Test]
    public function soft_deleted_order_is_not_reachable(): void
    {
        $goodsIssue = GoodsIssue::factory()->create();
        $goodsIssue->delete();

        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues/'.$goodsIssue->id)
            ->assertNotFound();
    }

    #[Test]
    public function export_returns_xlsx_for_current_selection(): void
    {
        GoodsIssue::factory()->create(['status' => GoodsIssue::STATUS_TO_PICK]);

        $response = $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues/export?statuses[]=to_pick');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    #[Test]
    public function export_route_is_not_swallowed_by_the_show_route(): void
    {
        // /goods-issues/export объявлен выше /goods-issues/{goodsIssue} — иначе
        // «export» попал бы в биндинг модели и отдавал 404.
        $this->actingAs($this->userWithRole('storekeeper'))
            ->get('/wms/goods-issues/export')
            ->assertOk();
    }
}
