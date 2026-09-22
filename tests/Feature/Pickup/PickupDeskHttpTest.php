<?php

namespace Tests\Feature\Pickup;

use App\Models\Pickup\PickupDeskPause;
use App\Models\Pickup\PickupDeskStaff;
use App\Models\User;
use App\Services\Pickup\PickupPassService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-18: стойка выдачи по HTTP — «Отойти»/«Вернулся», график начальника склада, статус у курьера и клиента. */
class PickupDeskHttpTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'pickup.enabled' => true, 'pickup.wms_enabled' => true, 'pickup.handover_since' => null,
            'warehouse.week' => array_fill_keys([1, 2, 3, 4, 5, 6], ['09:00', '21:00']),
            'warehouse.closed_dates' => [], 'warehouse.open_dates' => [], 'warehouse.special_hours' => [],
            'production_calendar.holidays' => [],
        ]);
        // Вторник, 11:00 по складу — стойка открыта.
        Carbon::setTestNow(Carbon::parse('2030-01-08 11:00', 'Europe/Moscow'));
        $this->client = $this->pickupClient();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['name' => $role === 'storekeeper' ? 'Кладовщик Петров' : 'Начальник склада']);
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function schedule_page_is_for_warehouse_head_only(): void
    {
        $this->actingAs($this->staff('storekeeper'))->get('/wms/pickups/schedule')->assertForbidden();

        $this->actingAs($this->staff('warehouse-head'))->get('/wms/pickups/schedule')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Wms/Pages/Pickups/Schedule')->has('preview', 7)->has('staff', 0));
    }

    #[Test]
    public function head_manages_staff_and_breaks_and_preview_shows_closed_windows(): void
    {
        $head = $this->staff('warehouse-head');

        $created = $this->actingAs($head)->postJson('/wms/pickups/schedule/staff', ['name' => 'Иванов', 'weekdays' => [1, 2, 3, 4, 5, 6]])
            ->assertOk()->assertJsonCount(1, 'staff');
        $staffId = $created->json('staff.0.id');

        $this->actingAs($head)->postJson("/wms/pickups/schedule/staff/{$staffId}/breaks", [
            'weekdays' => [1, 2, 3, 4, 5, 6], 'starts_at' => '13:00', 'ends_at' => '14:00', 'label' => 'Обед',
        ])->assertOk()
            ->assertJsonPath('staff.0.breaks.0.label', 'обед')
            ->assertJsonPath('preview.1.closed.0.from', '13:00')
            ->assertJsonPath('weekText', 'пн–сб 13:00–14:00');

        $this->actingAs($head)->postJson("/wms/pickups/schedule/staff/{$staffId}/breaks", [
            'weekdays' => [1], 'starts_at' => '14:00', 'ends_at' => '13:00', 'label' => 'ошибка',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        // Второй сотрудник с обедом в другое время — окно исчезает.
        $second = $this->actingAs($head)->postJson('/wms/pickups/schedule/staff', ['name' => 'Петров', 'weekdays' => [1, 2, 3, 4, 5, 6]])->json('staff.1.id');
        $this->actingAs($head)->postJson("/wms/pickups/schedule/staff/{$second}/breaks", [
            'weekdays' => [1, 2, 3, 4, 5, 6], 'starts_at' => '14:00', 'ends_at' => '15:00', 'label' => 'обед',
        ])->assertOk()->assertJsonPath('preview.1.closed', [])->assertJsonPath('weekText', null);

        $breakId = PickupDeskStaff::find($staffId)->breaks()->value('id');
        $this->actingAs($head)->deleteJson("/wms/pickups/schedule/breaks/{$breakId}")->assertOk();
        $this->actingAs($head)->deleteJson("/wms/pickups/schedule/staff/{$second}")->assertOk()->assertJsonCount(1, 'staff');
        $this->assertDatabaseCount('pickup_desk_breaks', 0);

        $this->actingAs($this->staff('storekeeper'))->postJson('/wms/pickups/schedule/staff', ['name' => 'Чужой', 'weekdays' => [1]])->assertForbidden();
    }

    #[Test]
    public function storekeeper_steps_away_and_returns(): void
    {
        $keeper = $this->staff('storekeeper');
        $staff = PickupDeskStaff::create(['name' => 'Петров', 'user_id' => $keeper->id, 'weekdays' => [1, 2, 3, 4, 5, 6]]);

        $this->actingAs($keeper)->getJson('/wms/pickups/data')->assertOk()
            ->assertJsonPath('desk.state', 'open')->assertJsonPath('desk.roster.0.user_id', $keeper->id);

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['staff_id' => $staff->id, 'minutes' => 20, 'reason' => 'Почта'])
            ->assertOk()
            ->assertJsonPath('desk.state', 'break')
            ->assertJsonPath('desk.text', 'Перерыв до 11:20 · почта')
            ->assertJsonPath('message', 'Выдача закрыта до 11:20 — курьеры это видят');

        // Повторное «Отойти» переписывает срок, а не плодит записи.
        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['staff_id' => $staff->id, 'until_close' => true, 'reason' => 'больничный'])
            ->assertOk()->assertJsonPath('desk.text', 'Перерыв до 21:00 · больничный');
        $this->assertSame(1, PickupDeskPause::query()->active()->count());

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/resume')->assertOk()
            ->assertJsonPath('desk.state', 'open')->assertJsonPath('message', 'Выдача открыта');
        $this->assertSame(0, PickupDeskPause::query()->active()->count());

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['reason' => 'обед'])->assertUnprocessable(); // без срока
        $this->actingAs($this->client)->postJson('/wms/pickups/desk/pause', ['minutes' => 10, 'reason' => 'обед'])->assertRedirect();
    }

    #[Test]
    public function pause_of_one_of_two_keeps_issuing(): void
    {
        $keeper = $this->staff('storekeeper');
        $ivanov = PickupDeskStaff::create(['name' => 'Иванов', 'weekdays' => [1, 2, 3, 4, 5, 6]]);
        PickupDeskStaff::create(['name' => 'Петров', 'weekdays' => [1, 2, 3, 4, 5, 6]]);

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['staff_id' => $ivanov->id, 'minutes' => 30, 'reason' => 'обед'])
            ->assertOk()
            ->assertJsonPath('desk.state', 'open')
            ->assertJsonPath('desk.pauses.0.staff_name', 'Иванов')
            ->assertJsonPath('message', 'Отмечено. На стойке остаётся коллега, выдача продолжается');
    }

    #[Test]
    public function courier_and_client_see_desk_status_and_phone(): void
    {
        config(['warehouse.pickup_phone' => '+7 (999) 000-00-00']);
        $staff = PickupDeskStaff::create(['name' => 'Иванов', 'weekdays' => [1, 2, 3, 4, 5, 6]]);
        $staff->breaks()->create(['weekdays' => [2], 'starts_at' => '13:00', 'ends_at' => '14:00', 'label' => 'обед']);

        $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass] = app(PickupPassService::class)->issueAll($this->client);
        $url = app(PickupPassService::class)->urlOf($pass);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pickup/Pass')
            ->where('desk.state', 'open')
            ->where('desk.next_break', '13:00–14:00 (обед)')
            ->where('desk.phone', '+7 (999) 000-00-00')
            ->missing('desk.roster')->missing('desk.pauses'));

        PickupDeskPause::create(['staff_id' => $staff->id, 'reason' => 'почта', 'started_at' => now(), 'until_at' => now()->addMinutes(15)]);
        $this->getJson($url.'/desk')->assertOk()
            ->assertJsonPath('desk.state', 'break')->assertJsonPath('desk.text', 'Перерыв до 11:15 · почта');
        $this->getJson(str_replace('/p/', '/p/x', $url).'/desk')->assertNotFound();

        $this->actingAs($this->client)->get('/cabinet/pickup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('User/Cabinet/Pickup/Index')
            ->where('desk.state', 'break')
            ->where('desk.week_text', 'пн без перерывов; вт 13:00–14:00; ср–сб без перерывов'));
    }
}
