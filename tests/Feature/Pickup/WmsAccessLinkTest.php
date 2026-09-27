<?php

namespace Tests\Feature\Pickup;

use App\Models\User;
use App\Models\WmsAccessLink;
use App\Services\Wms\AccessLinkService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-17: вход кладовщика по ссылке без пароля; перевыпуск отключает старые телефоны. */
class WmsAccessLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    /** Эмуляция другого телефона: в тестах пользователь и сессия переживают запросы, поэтому выходим явно. */
    private function otherPhone(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['pickup.wms_enabled' => true]);
        $this->head = User::factory()->create();
        $this->head->assignRole('warehouse-head');
    }

    #[Test]
    public function head_issues_link_and_phone_enters_warehouse_without_password(): void
    {
        $this->actingAs($this->head)->postJson('/wms/access-links', ['name' => 'Стойка выдачи'])
            ->assertOk()->assertJsonPath('links.0.name', 'Стойка выдачи')->assertJsonPath('links.0.is_active', true);

        $link = WmsAccessLink::firstOrFail();
        $url = app(AccessLinkService::class)->url($link);
        $this->assertStringContainsString('/wms/join/', $url);

        // Телефон кладовщика: чистая сессия, открыл ссылку — и на экране выдачи.
        $this->otherPhone();
        $this->get($url)->assertRedirect(route('wms.pickups.index'));
        $this->assertAuthenticatedAs($link->user);
        $this->assertTrue($link->user->hasRole('pickup-operator'));
        $this->assertTrue($link->user->can('wms-pickups.issue'));
        $this->assertFalse($link->user->can('wms-access.view'), 'ссылка даёт только выдачу, не права начальника');
        $this->assertFalse($link->user->can('wms-goods-issues.view'), 'других разделов склада у учётки ссылки нет');

        // «Киоск»: экран выдачи без меню, остальные адреса склада уводят обратно.
        $this->get('/wms/pickups')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('kiosk', true));
        $this->get('/wms')->assertRedirect(route('wms.pickups.index'));
        $this->get('/wms/goods-issues')->assertRedirect(route('wms.pickups.index'));
        $this->assertSame(1, $link->fresh()->uses_count);
    }

    #[Test]
    public function regenerate_invalidates_old_link_and_logs_out_old_phones(): void
    {
        [$link, $oldToken] = app(AccessLinkService::class)->create('Смена А', $this->head);

        // Телефон вошёл по старой ссылке.
        $this->get(route('wms.join', ['token' => $oldToken]))->assertRedirect();
        $this->get('/wms/pickups')->assertOk();

        // Начальник перевыпустил ссылку (с другого устройства).
        $this->otherPhone();
        $this->actingAs($this->head)->postJson("/wms/access-links/{$link->id}/regenerate")->assertOk();

        // Старая ссылка больше не входит.
        $this->otherPhone();
        $this->get(route('wms.join', ['token' => $oldToken]))->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function old_phone_session_is_closed_after_regenerate(): void
    {
        [$link, $token] = app(AccessLinkService::class)->create('Смена Б', $this->head);
        $this->get(route('wms.join', ['token' => $token]))->assertRedirect();

        app(AccessLinkService::class)->regenerate($link);

        $this->get('/wms/pickups')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function revoked_link_and_random_token_do_not_enter(): void
    {
        [$link, $token] = app(AccessLinkService::class)->create('Смена В', $this->head);
        $this->actingAs($this->head)->postJson("/wms/access-links/{$link->id}/revoke")->assertOk()->assertJsonPath('links.0.is_active', false);

        $this->otherPhone();
        $this->get(route('wms.join', ['token' => $token]))->assertRedirect('/login');
        $this->get('/wms/join/'.str_repeat('x', 43))->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Test]
    public function link_issued_before_kiosk_role_is_upgraded_on_next_login(): void
    {
        [$link, $token] = app(AccessLinkService::class)->create('Старая', $this->head);
        $link->user->syncRoles(['storekeeper']); // так выпускались ссылки до появления роли

        $this->otherPhone();
        $this->get(route('wms.join', ['token' => $token]))->assertRedirect(route('wms.pickups.index'));

        $this->assertTrue($link->user->fresh()->hasRole('pickup-operator'));
        $this->assertFalse($link->user->fresh()->hasRole('storekeeper'));
    }

    #[Test]
    public function ordinary_storekeeper_keeps_full_panel(): void
    {
        $keeper = User::factory()->create();
        $keeper->assignRole('storekeeper');

        $this->actingAs($keeper)->get('/wms/pickups')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('kiosk', false));
        $this->actingAs($keeper)->get('/wms/goods-issues')->assertOk();
    }

    #[Test]
    public function head_sees_load_statistics_and_handover_journal_per_link(): void
    {
        [$link, $token] = app(AccessLinkService::class)->create('Стойка А', $this->head);
        $client = \App\Models\User::factory()->create();
        \App\Models\Company::factory()->create(['user_id' => $client->id]);
        $order = \App\Models\Order::factory()->create(['user_id' => $client->id, 'company_id' => $client->companies()->value('id'), 'type' => \App\Enums\OrderType::ORDER, 'status' => \App\Enums\OrderStatus::READY_FOR_SHIPMENT, 'delivery_method' => \App\Enums\DeliveryMethod::PICKUP, 'reserve' => false, 'erp_number' => '29УТ-050001']);
        $issue = \App\Models\GoodsIssue::factory()->create(['status' => \App\Models\GoodsIssue::STATUS_SHIPPED, 'status_changed_at' => now(), 'packages_count' => 2]);
        \App\Models\GoodsIssueItem::factory()->create(['goods_issue_id' => $issue->id, 'order_uuid' => $order->uuid, 'order_id' => null]);

        // Кладовщик по ссылке выдал комплект.
        app(\App\Services\Pickup\HandoverService::class)->issue($issue, $link->user, 'code', null, ['recipient_name' => 'Олег']);

        $this->actingAs($this->head)->get('/wms/access-links')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('links.0.stats.today', 1)->where('links.0.stats.total', 1)->where('links.0.stats.cancelled', 0));

        $this->actingAs($this->head)->getJson("/wms/access-links/{$link->id}/handovers?days=7")->assertOk()
            ->assertJsonPath('rows.0.orders.0', '29УТ-050001')
            ->assertJsonPath('rows.0.recipient_name', 'Олег')
            ->assertJsonPath('rows.0.packages_count', 2);
    }

    #[Test]
    public function storekeeper_cannot_manage_links(): void
    {
        $keeper = User::factory()->create();
        $keeper->assignRole('storekeeper');

        $this->actingAs($keeper)->get('/wms/access-links')->assertForbidden();
        $this->actingAs($keeper)->postJson('/wms/access-links', ['name' => 'x'])->assertForbidden();
    }
}
