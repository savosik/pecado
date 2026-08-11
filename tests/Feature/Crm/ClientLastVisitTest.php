<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Последний визит на сайт» в CRM: откуда берётся отметка и что видит менеджер.
 *
 * Ценность признака — в различении «партнёр не заходил» и «заходил вчера»,
 * поэтому проверяем оба полюса и отдельно то, что просмотр от имени партнёра
 * отметку не двигает: иначе менеджер сам бы стирал ответ на свой вопрос.
 */
class ClientLastVisitTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function client(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['personal_manager_id' => $this->card->id], $attrs));
    }

    #[Test]
    public function активность_партнёра_записывает_отметку_визита(): void
    {
        $client = $this->client(['last_seen_at' => null]);

        $this->actingAs($client)->get('/')->assertSuccessful();

        $this->assertNotNull($client->fresh()->last_seen_at);
    }

    #[Test]
    public function отметка_не_обновляется_чаще_раза_в_пятнадцать_минут(): void
    {
        $client = $this->client(['last_seen_at' => null]);

        $this->actingAs($client)->get('/');
        $first = $client->fresh()->last_seen_at;

        // Второй заход в то же окно: запись должна остаться прежней, иначе
        // каждый запрос страницы стал бы UPDATE по users.
        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAs($client)->get('/');

        $this->assertTrue($first->equalTo($client->fresh()->last_seen_at));

        Carbon::setTestNow();
    }

    #[Test]
    public function просмотр_от_имени_партнёра_не_двигает_его_визит(): void
    {
        $client = $this->client(['last_seen_at' => null]);

        // Ровно тот режим, в котором менеджер смотрит сайт глазами партнёра.
        session([Impersonation::SESSION_KEY => $this->manager->id]);

        $this->actingAs($client)->get('/');

        $this->assertNull($client->fresh()->last_seen_at);
    }

    #[Test]
    public function список_партнёров_отдаёт_визит_и_признак_никогда(): void
    {
        // Метка «сегодня» считается по календарным суткам, поэтому без фиксации
        // времени тест падал при каждом прогоне между полуночью и 02:00:
        // «два часа назад» приходилось уже на вчерашний день.
        $this->travelTo(Carbon::today()->setHour(12));

        $never = $this->client(['last_seen_at' => null, 'erp_name' => 'Партнёр без входа']);
        $recent = $this->client(['last_seen_at' => now()->subHours(2), 'erp_name' => 'Партнёр со входом']);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertInertia(function (AssertableInertia $page) use ($never, $recent) {
                $rows = collect($page->toArray()['props']['clients']['data'])->keyBy('id');

                $this->assertSame('never', $rows[$never->id]['last_visit']['state']);
                $this->assertSame('ни разу не заходил', $rows[$never->id]['last_visit']['label']);

                $this->assertSame('recent', $rows[$recent->id]['last_visit']['state']);
                $this->assertSame('сегодня', $rows[$recent->id]['last_visit']['label']);
            });
    }

    #[Test]
    public function давний_визит_помечается_отдельным_состоянием(): void
    {
        $client = $this->client(['last_seen_at' => now()->subDays(45)]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $client))
            ->assertInertia(function (AssertableInertia $page) {
                $visit = $page->toArray()['props']['client']['last_visit'];

                $this->assertSame('stale', $visit['state']);
                $this->assertSame(45, $visit['days']);
            });
    }
}
