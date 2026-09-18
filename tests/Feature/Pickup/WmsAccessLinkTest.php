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
        $this->assertTrue($link->user->hasRole('storekeeper'));
        $this->assertTrue($link->user->can('wms-pickups.issue'));
        $this->assertFalse($link->user->can('wms-access.view'), 'ссылка даёт права кладовщика, не начальника');

        $this->get('/wms/pickups')->assertOk();
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
    public function storekeeper_cannot_manage_links(): void
    {
        $keeper = User::factory()->create();
        $keeper->assignRole('storekeeper');

        $this->actingAs($keeper)->get('/wms/access-links')->assertForbidden();
        $this->actingAs($keeper)->postJson('/wms/access-links', ['name' => 'x'])->assertForbidden();
    }
}
