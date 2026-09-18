<?php

namespace Tests\Feature\Pickup;

use App\Models\GoodsIssue;
use App\Models\Pickup\PickupHandover;
use App\Models\Pickup\PickupPass;
use App\Models\User;
use App\Services\Pickup\PickupPassService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-07, pick-09, pick-10, pick-11: экран склада, раздел кабинета и страница курьера по HTTP. */
class PickupHttpTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['pickup.enabled' => true, 'pickup.wms_enabled' => true, 'pickup.handover_since' => null]);
        $this->client = $this->pickupClient();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['name' => $role === 'storekeeper' ? 'Кладовщик Петров' : 'Начальник склада']);
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function wms_screen_is_hidden_behind_switch_and_permission(): void
    {
        $this->actingAs($this->client)->get('/wms/pickups')->assertRedirect(); // не сотрудник склада — в панель не пускает

        config(['pickup.wms_enabled' => false]);
        $keeper = $this->staff('storekeeper');
        $this->actingAs($keeper)->get('/wms/pickups')->assertRedirect('/wms');
        $this->actingAs($keeper)->getJson('/wms/pickups/data')->assertNotFound();
        $this->actingAs($keeper)->get('/wms')->assertOk()->assertInertia(fn (Assert $page) => $page->where('pickups', null));
    }

    #[Test]
    public function wms_screen_lists_awaiting_picking_and_hides_delivery_orders(): void
    {
        $ready = $this->goodsIssueFor($this->pickupOrder($this->client));
        $picking = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_PICK);
        $this->goodsIssueFor($this->pickupOrder($this->client, ['delivery_method' => \App\Enums\DeliveryMethod::DELIVERY]));
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_SHIPPED, ['status_changed_at' => now()->subDays(10)]);

        $this->actingAs($this->staff('storekeeper'))->get('/wms/pickups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Wms/Pages/Pickups/Index')
                ->has('awaiting', 1)->where('awaiting.0.id', $ready->id)
                ->has('picking', 1)->where('picking.0.id', $picking->id)
                ->has('stale', 1)
                ->has('schedule.week_text'));
    }

    #[Test]
    public function storekeeper_issues_by_pass_and_only_what_is_in_the_pass(): void
    {
        $inPass = $this->goodsIssueFor($this->pickupOrder($this->client));
        $other = $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass, $token] = app(PickupPassService::class)->issueSelected($this->client, [$inPass->id], ['courier_name' => 'Олег']);
        $keeper = $this->staff('storekeeper');

        $this->actingAs($keeper)->postJson('/wms/pickups/resolve', ['value' => 'https://pecado.ru/p/'.$token])
            ->assertOk()->assertJsonPath('kind', 'pass')->assertJsonPath('pass.to_issue', 1)->assertJsonPath('pass.items.0.goods_issue_id', $inPass->id);

        $this->actingAs($keeper)->postJson("/wms/pickups/{$other->id}/issue", ['pass_id' => $pass->id])
            ->assertStatus(422)->assertJsonPath('reason', 'not_in_pass');

        $this->actingAs($keeper)->postJson("/wms/pickups/passes/{$pass->id}/issue-all", ['via' => 'qr', 'verified' => [$inPass->id]])
            ->assertOk()->assertJsonPath('issued', 1);

        $handover = PickupHandover::firstOrFail();
        $this->assertSame([$inPass->id, $keeper->id, 'qr', 'Олег', true], [$handover->goods_issue_id, $handover->issued_by, $handover->method, $handover->recipient_name, $handover->box_verified]);
        $this->assertSame(PickupPass::STATUS_USED, $pass->fresh()->status);
        $this->assertNull($other->fresh()->activeHandover, 'чужой для пропуска комплект остался ждать другого курьера');
    }

    #[Test]
    public function manual_handover_double_tap_and_cancel_rights(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client));
        $keeper = $this->staff('storekeeper');

        $this->actingAs($keeper)->postJson("/wms/pickups/{$issue->id}/issue", ['recipient_name' => 'Курьер', 'via' => 'manual'])->assertOk();
        $this->actingAs($keeper)->postJson("/wms/pickups/{$issue->id}/issue", [])
            ->assertStatus(422)->assertJsonPath('reason', 'already_issued');

        $handover = PickupHandover::firstOrFail();
        $this->actingAs($keeper)->postJson("/wms/pickups/handovers/{$handover->id}/cancel", ['reason' => 'ошибка'])->assertForbidden();
        $this->actingAs($this->staff('warehouse-head'))->postJson("/wms/pickups/handovers/{$handover->id}/cancel", ['reason' => 'Отметили не тот ордер'])->assertOk();
        $this->assertNotNull($handover->fresh()->cancelled_at);
    }

    #[Test]
    public function search_finds_by_order_number_and_unknown_scan_is_a_miss(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-015001']));
        $keeper = $this->staff('storekeeper');

        $this->actingAs($keeper)->getJson('/wms/pickups/search?q=015001')->assertOk()->assertJsonPath('rows.0.id', $issue->id);
        $this->actingAs($keeper)->postJson('/wms/pickups/resolve', ['value' => '4607001234567'])->assertNotFound()->assertJsonPath('kind', 'miss');
    }

    #[Test]
    public function cabinet_section_shows_ready_sets_and_issues_passes(): void
    {
        $ready = $this->goodsIssueFor($this->pickupOrder($this->client));
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_CHECK);
        $foreign = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()));

        $this->actingAs($this->client)->get('/cabinet/pickup')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('User/Cabinet/Pickup/Index')
                ->has('ready', 1)->where('ready.0.id', $ready->id)->has('picking', 1)->has('passes', 0));

        $this->actingAs($this->client)->postJson('/cabinet/pickup/passes', ['scope' => 'selected', 'goods_issue_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonPath('reason', 'not_available');

        $response = $this->actingAs($this->client)->postJson('/cabinet/pickup/passes', ['scope' => 'all', 'courier_name' => 'Олег'])->assertOk();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('pass.qr'));
        $this->assertStringContainsString('/p/', $response->json('pass.url'));

        $pass = PickupPass::firstOrFail();
        $this->actingAs($this->pickupClient())->postJson("/cabinet/pickup/passes/{$pass->id}/revoke")->assertForbidden();
        $this->actingAs($this->client)->postJson("/cabinet/pickup/passes/{$pass->id}/revoke")->assertOk();
    }

    #[Test]
    public function cabinet_section_is_404_when_switch_is_off(): void
    {
        config(['pickup.enabled' => false]);

        $this->actingAs($this->client)->get('/cabinet/pickup')->assertNotFound();
        $this->get('/p/'.str_repeat('a', 43))->assertNotFound();
    }

    #[Test]
    public function courier_page_is_public_and_reveals_no_client_data(): void
    {
        $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-016002', 'total_amount' => 98765.43]));
        [$pass, $token] = app(PickupPassService::class)->issueAll($this->client);

        $response = $this->get('/p/'.$token)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('Pickup/Pass')
                ->where('pass.state', 'ready')->where('pass.ready_sets', 1)->where('pass.orders.0.number', '29УТ-016002')
                ->where('schedule.coords', fn ($c) => count($c) === 2 && $c[0] > 55 && $c[1] > 37));

        $body = $response->getContent();
        $this->assertStringNotContainsString($this->client->name, $body);
        $this->assertStringNotContainsString('98765', $body);
        $this->assertStringNotContainsString($pass->token_hash, $body);
    }

    #[Test]
    public function revoked_expired_and_random_tokens_answer_identically(): void
    {
        $this->goodsIssueFor($this->pickupOrder($this->client));
        [$revoked, $revokedToken] = app(PickupPassService::class)->issueAll($this->client);
        app(PickupPassService::class)->revoke($revoked);
        [$expired, $expiredToken] = app(PickupPassService::class)->issueAll($this->client);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        $props = [];
        foreach ([$revokedToken, $expiredToken, str_repeat('Z', 43)] as $token) {
            $response = $this->get('/p/'.$token)->assertNotFound();
            $props[] = json_encode($response->viewData('page')['props']['pass'] ?? null);
        }

        $this->assertSame(['null'], array_values(array_unique($props)));
    }
}
