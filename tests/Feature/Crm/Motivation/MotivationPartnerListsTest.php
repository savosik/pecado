<?php

namespace Tests\Feature\Crm\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\PartnerListService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * «Моя база» и «Кто выпал из ритма» (карточка mot-27).
 *
 * Проверяются величины, которых нет у сигналов возможностей: обычная закупка,
 * лучший месяц, потенциал и его цена по ставке П1, а также разделение базы
 * на покупавших и не покупавших ни разу.
 */
class MotivationPartnerListsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $profile;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        // Учётка сотрудника — не клиент: иначе она попадёт в Пул как партнёр без менеджера.
        $this->head = User::factory()->create(['user_kind' => \App\Enums\UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);
    }

    private function partner(string $name): User
    {
        return User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => $name]);
    }

    private function ship(User $partner, float $amount, CarbonImmutable $date): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }

    #[Test]
    #[TestDox('База делится на покупавших и не покупавших ни разу; сводка считает всех')]
    public function base_separates_buyers_from_never_bought(): void
    {
        $buyer = $this->partner('Покупатель');
        $this->partner('Молчун');
        $this->ship($buyer, 100_000, $this->month->addDays(2));

        $list = app(PartnerListService::class)->base($this->profile->id, $this->month);

        $this->assertSame(['total' => 2, 'active' => 1, 'silent' => 0, 'never_bought' => 1, 'in_novelty' => 0], $list['summary']);
        $this->assertCount(1, $list['rows']['data'], 'Рабочий список по умолчанию — только покупавшие');
        $this->assertSame('Покупатель', $list['rows']['data'][0]['name']);

        $never = app(PartnerListService::class)->base($this->profile->id, $this->month, ['filter' => 'never']);
        $this->assertSame('Молчун', $never['rows']['data'][0]['name']);
    }

    #[Test]
    #[TestDox('Обычная закупка — медиана за полгода с нулями за пустые месяцы')]
    public function usual_monthly_is_a_median_with_zero_months(): void
    {
        $partner = $this->partner('Раз в квартал');
        // Две отгрузки за шесть месяцев: медиана из [0,0,0,0,300k,300k] — ноль.
        $this->ship($partner, 300_000, $this->month->subMonths(2)->addDays(3));
        $this->ship($partner, 300_000, $this->month->subMonths(5)->addDays(3));

        $row = app(PartnerListService::class)->base($this->profile->id, $this->month)['rows']['data'][0];

        $this->assertSame(0.0, $row['usual_monthly'], 'Партнёр, покупающий раз в квартал, «обычно» не берёт ничего');
        $this->assertSame(300_000.0, $row['best_month']['amount']);
        $this->assertSame($this->month->subMonths(2)->format('Y-m'), $row['best_month']['period']);
    }

    #[Test]
    #[TestDox('Потенциал — лучший месяц минус текущая закупка, «даст вам» — по ставке П1')]
    public function potential_and_gain_follow_best_month_and_rate(): void
    {
        $partner = $this->partner('Растущий');
        $this->ship($partner, 1_000_000, $this->month->subMonths(3)->addDays(3));
        $this->ship($partner, 400_000, $this->month->addDays(2));

        $row = app(PartnerListService::class)->base($this->profile->id, $this->month)['rows']['data'][0];

        $this->assertSame(400_000.0, $row['current_month']);
        $this->assertSame(600_000.0, $row['potential']);
        $this->assertEqualsWithDelta(600_000 * 0.019, $row['your_gain'], 0.01, 'Ставка П1 из умолчаний Приложения № 1');
    }

    #[Test]
    #[TestDox('Партнёр в периоде новизны помечен и считается в сводке')]
    public function novelty_is_flagged(): void
    {
        $partner = $this->partner('Новый');
        $this->ship($partner, 50_000, $this->month->addDays(1));
        MotivationPartnerNovelty::factory()
            ->withinNovelty($this->month->toDateString(), $this->month->addMonths(5)->endOfMonth()->toDateString())
            ->create(['user_id' => $partner->id]);

        $list = app(PartnerListService::class)->base($this->profile->id, $this->month);

        $this->assertSame(1, $list['summary']['in_novelty']);
        $this->assertTrue($list['rows']['data'][0]['in_novelty']);
    }

    #[Test]
    #[TestDox('Ритм: просевший партнёр в списке с ценой недобора, ровный — нет')]
    public function rhythm_lists_the_dropped_partner_with_its_cost(): void
    {
        $steady = $this->partner('Ровный');
        $dropped = $this->partner('Просел');

        for ($i = 1; $i <= 6; $i++) {
            $this->ship($steady, 200_000, $this->month->subMonths($i)->addDays(3));
            $this->ship($dropped, 200_000, $this->month->subMonths($i)->addDays(3));
        }
        $this->ship($steady, 200_000, $this->month->addDays(1));
        $this->ship($dropped, 50_000, $this->month->addDays(1));

        $list = app(PartnerListService::class)->rhythm($this->profile->id, $this->month, ['silent' => 0]);

        $names = array_column($list['rows']['data'], 'name');
        $this->assertContains('Просел', $names);
        $this->assertNotContains('Ровный', $names);

        $row = $list['rows']['data'][0];
        $this->assertSame(150_000.0, $row['shortfall']);
        $this->assertEqualsWithDelta(150_000 * 0.019, $row['cost'], 0.01);
        $this->assertTrue($row['flags']['drop']);
        $this->assertFalse($row['flags']['stopped']);
    }

    #[Test]
    #[TestDox('Снятые фильтры ритма показывают всю покупавшую базу')]
    public function rhythm_without_filters_shows_everyone_who_bought(): void
    {
        $a = $this->partner('А');
        $b = $this->partner('Б');
        $this->partner('Никогда');
        $this->ship($a, 100_000, $this->month->addDays(1));
        $this->ship($b, 100_000, $this->month->subMonth()->addDays(1));

        $list = app(PartnerListService::class)->rhythm($this->profile->id, $this->month, ['drop' => 0, 'stopped' => 0, 'silent' => 0]);

        $this->assertSame(2, $list['rows']['total']);
    }

    #[Test]
    #[TestDox('Сортировка и поиск работают на сервере, страница — по 50')]
    public function sorting_search_and_paging_are_server_side(): void
    {
        foreach (range(1, 55) as $i) {
            $this->ship($this->partner(sprintf('Партнёр %02d', $i)), $i * 1_000, $this->month->addDays(1));
        }

        $service = app(PartnerListService::class);

        $first = $service->base($this->profile->id, $this->month, ['sort' => 'current_month', 'direction' => 'desc']);
        $this->assertSame(50, count($first['rows']['data']));
        $this->assertSame(2, $first['rows']['last_page']);
        $this->assertSame('Партнёр 55', $first['rows']['data'][0]['name']);

        $second = $service->base($this->profile->id, $this->month, ['sort' => 'current_month', 'direction' => 'desc', 'page' => 2]);
        $this->assertCount(5, $second['rows']['data']);

        $found = $service->base($this->profile->id, $this->month, ['search' => 'партнёр 07']);
        $this->assertSame(1, $found['rows']['total']);
    }

    #[Test]
    #[TestDox('«Кого разбудить»: без покупок — основная вкладка, молчащие дольше трёх месяцев — отдельная')]
    public function wake_separates_never_bought_from_silent(): void
    {
        $this->partner('Никогда');
        $silent = $this->partner('Затих');
        $fresh = $this->partner('Недавний');
        $this->ship($silent, 80_000, $this->month->subMonths(5)->addDays(2));
        $this->ship($fresh, 80_000, $this->month->subMonth()->addDays(2));

        $service = app(PartnerListService::class);

        $never = $service->wake($this->profile->id, $this->month);
        $this->assertSame(['never' => 1, 'silent' => 1], $never['summary']);
        $this->assertSame('Никогда', $never['rows']['data'][0]['name']);

        $quiet = $service->wake($this->profile->id, $this->month, ['tab' => 'silent']);
        $this->assertSame('Затих', $quiet['rows']['data'][0]['name']);
        $this->assertArrayHasKey('top_products', $quiet['rows']['data'][0]);
        $this->assertGreaterThanOrEqual(4, $quiet['rows']['data'][0]['silent_months']);
        $this->assertNotContains('Недавний', array_column($quiet['rows']['data'], 'name'), 'Месяц тишины — ещё не «молчит»');
    }

    #[Test]
    #[TestDox('«Мои новые клиенты»: сумма вознаграждений равна П2, до зачёта — по порогу квартала')]
    public function newcomers_rewards_add_up_to_p2(): void
    {
        $a = $this->partner('Новый А');
        $b = $this->partner('Новый Б');
        $this->partner('Старый');

        foreach ([$a, $b] as $partner) {
            MotivationPartnerNovelty::factory()
                ->withinNovelty($this->month->subMonths(2)->toDateString(), $this->month->addMonths(3)->endOfMonth()->toDateString())
                ->create(['user_id' => $partner->id]);
        }
        $this->ship($a, 60_000, $this->month->addDays(1));
        $this->ship($b, 30_000, $this->month->addDays(1));

        $list = app(PartnerListService::class)->newcomers($this->profile->id, $this->month);

        $this->assertSame(2, $list['summary']['total']);
        $this->assertEqualsWithDelta(90_000 * 0.03, $list['summary']['reward_total'], 0.01, 'П2 = отгрузки новым × 3 %');
        $this->assertSame('Новый А', $list['rows'][0]['name'], 'Сверху — кто приносит больше');
        $this->assertSame(4, $list['rows'][0]['months_left'], 'Период новизны заканчивается через три месяца после текущего');
        $this->assertSame(6, $list['rows'][0]['months_total']);
        $this->assertFalse($list['rows'][0]['qualified']);
        $this->assertGreaterThan(0, $list['rows'][0]['to_qualification']);
    }

    #[Test]
    #[TestDox('Пул: по умолчанию только с историей, «даст вам» есть лишь у покупавших, кран открыт без данных')]
    public function pool_defaults_to_partners_with_history(): void
    {
        $cold = User::factory()->create(['personal_manager_id' => null, 'name' => 'Холодный']);
        $warm = User::factory()->create(['personal_manager_id' => null, 'name' => 'Покупал', 'city' => 'Тула']);
        User::factory()->create(['personal_manager_id' => null, 'name' => 'Ещё холодный']);
        $this->ship($warm, 90_000, $this->month->subMonths(2)->addDays(3));

        $service = app(\App\Services\Motivation\PoolListService::class);

        $list = $service->list($this->profile->id, $this->month);
        $this->assertSame(3, $list['summary']['total']);
        $this->assertSame(1, $list['summary']['with_history']);
        $this->assertTrue($list['history_only']);
        $this->assertCount(1, $list['rows']['data']);
        $this->assertSame('Покупал', $list['rows']['data'][0]['name']);
        $this->assertNull($list['rows']['data'][0]['estimate'], 'Одна покупка за полгода — обычная закупка ноль, оценки нет');
        $this->assertFalse($list['tap']['blocked']);
        $this->assertNotNull($list['tap']['note'], 'Без расчётов по новой схеме кран честно помечен как непроверяемый');

        $all = $service->list($this->profile->id, $this->month, ['history' => 0]);
        $this->assertSame(3, $all['rows']['total']);
        $this->assertSame('Покупал', $all['rows']['data'][0]['name'], 'С историей — первыми при любой сортировке');
        $this->assertNotNull($cold);
    }

    #[Test]
    #[TestDox('Страницы открываются руководителю и закрыты менеджеру без права')]
    public function pages_respect_the_motivation_permission(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/base')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Base')
                ->where('manager.id', $this->profile->id)
                ->has('list.summary')
                ->has('list.rows.data'));

        $this->actingAs($this->head)
            ->get('/crm/motivation/wake?tab=silent')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Wake')
                ->where('list.filter', 'silent'));

        $this->actingAs($this->head)
            ->get('/crm/motivation/new-partners')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/NewPartners')
                ->has('list.summary.reward_total'));

        $this->actingAs($this->head)
            ->get('/crm/motivation/pool')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Pool')
                ->has('list.tap.blocked'));

        $this->actingAs($this->head)
            ->get('/crm/motivation/rhythm?drop=1&stopped=0&silent=0')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Rhythm')
                ->where('list.filter.drop', true)
                ->where('list.filter.stopped', false));

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');

        $this->actingAs($manager)->get('/crm/motivation/base')->assertForbidden();
        $this->actingAs($manager)->get('/crm/motivation/rhythm/data')->assertForbidden();
    }
}
