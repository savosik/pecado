<?php

namespace Tests\Feature\Pickup;

use App\Models\Pickup\PickupDeskDay;
use App\Models\Pickup\PickupDeskPause;
use App\Models\User;
use App\Services\Pickup\PickupPassService;
use App\Services\Warehouse\WarehouseSchedule;
use App\Services\Wms\AccessLinkService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-18: стойка выдачи по HTTP — «Отойти»/«Вернулся», таблица графика, статус у курьера и клиента, журнал ссылки. */
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
        WarehouseSchedule::forgetWeek();
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
            ->assertInertia(fn (Assert $page) => $page->component('Wms/Pages/Pickups/Schedule')
                ->has('days', 7)
                ->where('days.0.name', 'Понедельник')->where('days.0.works', true)->where('days.0.opens_at', '09:00')
                ->where('days.6.works', false)
                ->where('weekText', null));
    }

    #[Test]
    public function head_edits_the_day_row_and_everyone_sees_the_result(): void
    {
        $head = $this->staff('warehouse-head');

        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/2', [
            'works' => true, 'opens_at' => '09:00', 'closes_at' => '21:00',
            'breaks' => [['from' => '13:00', 'to' => '14:00', 'label' => 'Обед'], ['from' => '10:00', 'to' => '10:15', 'label' => 'почта']],
        ])->assertOk()
            ->assertJsonPath('days.1.breaks.0.from', '10:00') // отсортированы
            ->assertJsonPath('days.1.breaks.1.label', 'обед')
            ->assertJsonPath('days.1.closed.1.from', '13:00')
            ->assertJsonPath('weekText', 'пн без перерывов; вт 10:00–10:15, 13:00–14:00; ср–сб без перерывов');

        // Часы дня и выходной — тоже прямо в строке, и это сразу видит график склада.
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/6', ['works' => false, 'breaks' => []])->assertOk()
            ->assertJsonPath('days.5.works', false)->assertJsonPath('hoursText', 'пн–пт, 9:00–21:00');
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/1', ['works' => true, 'opens_at' => '10:00', 'closes_at' => '18:00', 'breaks' => []])->assertOk()
            ->assertJsonPath('days.0.opens_at', '10:00')->assertJsonPath('hoursText', 'пн, 10:00–18:00; вт–пт, 9:00–21:00');

        // Валидация: перерыв вне часов, конец раньше начала, закрытие раньше открытия.
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/3', ['works' => true, 'opens_at' => '09:00', 'closes_at' => '21:00', 'breaks' => [['from' => '08:00', 'to' => '09:30', 'label' => '']]])
            ->assertUnprocessable()->assertJsonPath('message', 'Перерыв выходит за часы выдачи');
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/3', ['works' => true, 'opens_at' => '09:00', 'closes_at' => '21:00', 'breaks' => [['from' => '14:00', 'to' => '13:00', 'label' => '']]])
            ->assertUnprocessable();
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/3', ['works' => true, 'opens_at' => '21:00', 'closes_at' => '09:00', 'breaks' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('closes_at');
        $this->actingAs($head)->putJson('/wms/pickups/schedule/days/8', ['works' => true, 'breaks' => []])->assertNotFound();

        $this->actingAs($this->staff('storekeeper'))->putJson('/wms/pickups/schedule/days/1', ['works' => true, 'breaks' => []])->assertForbidden();

        // Кабинет клиента читает тот же итог.
        $this->actingAs($this->client)->get('/cabinet/pickup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('desk.state', 'open')
            ->where('desk.next_break', '13:00–14:00 (обед)')
            ->where('schedule.week_text', 'пн, 10:00–18:00; вт–пт, 9:00–21:00'));
    }

    #[Test]
    public function storekeeper_steps_away_and_returns_and_it_lands_in_the_link_journal(): void
    {
        $keeper = $this->staff('storekeeper');
        $head = $this->staff('warehouse-head');

        $this->actingAs($keeper)->getJson('/wms/pickups/data')->assertOk()->assertJsonPath('desk.state', 'open');

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['minutes' => 20, 'reason' => 'Почта'])
            ->assertOk()
            ->assertJsonPath('desk.state', 'break')
            ->assertJsonPath('desk.text', 'Перерыв до 11:20 · почта')
            ->assertJsonPath('desk.pauses.0.user_name', 'Кладовщик Петров')
            ->assertJsonPath('message', 'Выдача закрыта до 11:20 — курьеры это видят');

        // Повторное «Отойти» переписывает срок, а не плодит записи.
        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['until_close' => true, 'reason' => 'больничный'])
            ->assertOk()->assertJsonPath('desk.text', 'Перерыв до 21:00 · больничный');
        $this->assertSame(1, PickupDeskPause::query()->active()->count());

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/resume')->assertOk()
            ->assertJsonPath('desk.state', 'open')->assertJsonPath('message', 'Выдача открыта');
        $this->assertSame(0, PickupDeskPause::query()->active()->count());

        $this->actingAs($keeper)->postJson('/wms/pickups/desk/pause', ['reason' => 'обед'])->assertUnprocessable(); // без срока
        $this->actingAs($this->client)->postJson('/wms/pickups/desk/pause', ['minutes' => 10, 'reason' => 'обед'])->assertRedirect();

        // Отлучки с этого телефона — в журнале его ссылки, рядом с выдачами.
        [$link] = app(AccessLinkService::class)->create('Телефон у стойки', $head);
        PickupDeskPause::query()->update(['user_id' => $link->user_id]);
        $this->actingAs($head)->getJson("/wms/access-links/{$link->id}/handovers")->assertOk()
            ->assertJsonPath('rows.0.kind', 'pause')
            ->assertJsonPath('rows.0.reason', 'больничный')
            ->assertJsonPath('rows.0.ended_at', '11:00')
            ->assertJsonPath('rows.1.reason', 'почта');
    }

    #[Test]
    public function courier_sees_status_phone_and_breaks_without_internal_details(): void
    {
        config(['warehouse.pickup_phone' => '+7 (999) 000-00-00']);
        PickupDeskDay::create(['iso_weekday' => 2, 'works' => true, 'breaks' => [['from' => '13:00', 'to' => '14:00', 'label' => 'обед']]]);

        $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass] = app(PickupPassService::class)->issueAll($this->client);
        $url = app(PickupPassService::class)->urlOf($pass);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pickup/Pass')
            ->where('desk.state', 'open')
            ->where('desk.next_break', '13:00–14:00 (обед)')
            ->where('desk.phone', '+7 (999) 000-00-00')
            ->where('desk.week_text', 'пн без перерывов; вт 13:00–14:00; ср–сб без перерывов')
            ->missing('desk.pauses'));

        PickupDeskPause::create(['reason' => 'почта', 'started_at' => now(), 'until_at' => now()->addMinutes(15)]);
        $this->getJson($url.'/desk')->assertOk()
            ->assertJsonPath('desk.state', 'break')->assertJsonPath('desk.text', 'Перерыв до 11:15 · почта')
            ->assertJsonMissingPath('desk.pauses');
        $this->getJson(str_replace('/p/', '/p/x', $url).'/desk')->assertNotFound();
    }
}
