<?php

namespace Tests\Feature\Crm;

use App\Models\Currency;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\Finance\CollectionForecastService;
use App\Services\Crm\Finance\FinanceFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Прогноз поступлений: «сколько денег будет к такому-то числу».
 *
 * Числа раздела переносят в бюджет, поэтому тест следит не за вёрсткой, а за
 * тем, что модель не врёт: обещание графика не выдаётся за деньги, дисциплина
 * партнёра меняет ожидание, а за концом графика прогноз опирается на ритм.
 */
class FinanceForecastTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-department.view', 'crm-clients-all.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo(['crm-finance.view', 'crm-department.view', 'crm-clients-all.view']);
        $this->card = PersonalManager::create(['name' => 'Сухов', 'user_id' => $this->actor->id]);
        $this->actor = $this->actor->fresh();
    }

    private function service(): CollectionForecastService
    {
        return app(CollectionForecastService::class);
    }

    private function clients(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->whereNotNull('users.personal_manager_id')->select('users.id');
    }

    private function filters(): FinanceFilters
    {
        return FinanceFilters::fromRequest(Request::create('/', 'GET'));
    }

    private function client(): User
    {
        return User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    /** Плановая строка: обещание заплатить сумму к дате. */
    private function plan(User $client, float $amount, int $daysFromToday): SettlementEntry
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today()->subDays(5),
            'erp_created_at' => Carbon::today()->subDays(5),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
            'paid_amount' => 0,
        ]);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => Carbon::today()->addDays($daysFromToday)->toDateString(),
            'amount' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    private function payment(User $client, float $amount, int $daysAgo): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'user_id' => $client->id,
            'amount' => $amount,
            'amount_rub' => $amount,
            'currency_code' => 'RUB',
            'date' => Carbon::today()->subDays($daysAgo)->toDateString(),
        ]);
    }

    #[Test]
    public function дисциплина_читается_из_фактов_а_не_назначается(): void
    {
        $reliable = $this->client();
        $this->payment($reliable, 5000, 3);
        $this->plan($reliable, 10000, 5);

        $slipping = $this->client();
        $this->payment($slipping, 5000, 3);
        $this->plan($slipping, 10000, -10);   // платит, но уже просрочил

        $fading = $this->client();
        $this->payment($fading, 5000, 50);
        $this->plan($fading, 10000, 5);

        $silent = $this->client();
        $this->plan($silent, 10000, 5);       // не платил ни разу

        $discipline = $this->service()->discipline($this->clients());

        $this->assertSame('reliable', $discipline[$reliable->id]['key']);
        $this->assertSame('slipping', $discipline[$slipping->id]['key']);
        $this->assertSame('fading', $discipline[$fading->id]['key']);
        $this->assertSame('silent', $discipline[$silent->id]['key']);
    }

    /**
     * Обещание графика — не деньги: ожидание всегда ниже, и разрыв тем шире,
     * чем хуже платит партнёр.
     */
    #[Test]
    public function ожидание_ниже_обещанного_и_зависит_от_дисциплины(): void
    {
        $reliable = $this->client();
        $this->payment($reliable, 5000, 2);
        $this->plan($reliable, 100000, 5);

        $silent = $this->client();
        $this->plan($silent, 100000, 5);

        $target = CarbonImmutable::today()->addDays(10);
        $rows = collect($this->service()->forecastByPartner($this->clients(), $this->filters(), $target))
            ->keyBy('entity_id');

        $this->assertEqualsWithDelta(100000.0, $rows[$reliable->id]['promised'], 0.01);
        $this->assertEqualsWithDelta(95000.0, $rows[$reliable->id]['expected'], 0.01);

        $this->assertEqualsWithDelta(100000.0, $rows[$silent->id]['promised'], 0.01);
        $this->assertEqualsWithDelta(15000.0, $rows[$silent->id]['expected'], 0.01);

        // Обещано поровну, ожидание отличается в разы — ради этого раздел и есть.
        $this->assertGreaterThan($rows[$silent->id]['expected'] * 5, $rows[$reliable->id]['expected']);
    }

    /**
     * Разбивка «срок впереди / срок нарушен» объясняет вероятность.
     *
     * Без неё число в колонке выглядит взятым с потолка: подсказка строки
     * собирается именно из этих полей, и они обязаны сходиться с итогом.
     */
    #[Test]
    public function разбивка_ожидания_сходится_с_итогом(): void
    {
        $client = $this->client();
        $this->payment($client, 5000, 2);
        $this->plan($client, 200000, 5);    // срок впереди
        $this->plan($client, 100000, -40);  // срок нарушен

        $row = collect($this->service()->forecastByPartner(
            $this->clients(),
            $this->filters(),
            CarbonImmutable::today()->addDays(10),
        ))->firstWhere('entity_id', $client->id);

        $this->assertEqualsWithDelta(200000.0, $row['upcoming_promised'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $row['overdue'], 0.01);

        // Части складываются в целое — иначе подсказка противоречила бы колонке.
        $this->assertEqualsWithDelta(
            $row['expected'],
            $row['upcoming_expected'] + $row['overdue_expected'],
            0.01,
        );

        // Просроченная часть взвешена строже будущей при равной дисциплине.
        $this->assertGreaterThan(
            $row['overdue_expected'] / $row['overdue'],
            $row['upcoming_expected'] / $row['upcoming_promised'],
        );
    }

    /** Просроченное обещание дешевеет с возрастом, а не пропадает. */
    #[Test]
    public function просроченная_строка_взвешивается_ниже_свежей(): void
    {
        $fresh = $this->client();
        $this->payment($fresh, 5000, 2);
        $this->plan($fresh, 100000, 3);

        $late = $this->client();
        $this->payment($late, 5000, 2);
        $this->plan($late, 100000, -20);      // просрочка две недели с лишним

        $ancient = $this->client();
        $this->payment($ancient, 5000, 2);
        $this->plan($ancient, 100000, -200);  // висит больше полугода

        $rows = collect($this->service()->forecastByPartner(
            $this->clients(),
            $this->filters(),
            CarbonImmutable::today()->addDays(10),
        ))->keyBy('entity_id');

        // Свежая строка — базовая вероятность класса, просроченные — с затуханием.
        $this->assertGreaterThan($rows[$late->id]['expected'], $rows[$fresh->id]['expected']);
        $this->assertGreaterThan($rows[$ancient->id]['expected'], $rows[$late->id]['expected']);
        $this->assertGreaterThan(0.0, $rows[$ancient->id]['expected']);
    }

    /** Строки со сроком позже выбранной даты в ответ на неё не попадают. */
    #[Test]
    public function прогноз_не_берёт_то_что_наступит_после_выбранной_даты(): void
    {
        $client = $this->client();
        $this->payment($client, 5000, 2);
        $this->plan($client, 50000, 3);
        $this->plan($client, 70000, 40);

        $near = $this->service()->forecast($this->clients(), $this->filters(), CarbonImmutable::today()->addDays(10));
        $far = $this->service()->forecast($this->clients(), $this->filters(), CarbonImmutable::today()->addDays(45));

        $this->assertEqualsWithDelta(50000.0, $near['promised'], 0.01);
        $this->assertEqualsWithDelta(120000.0, $far['promised'], 0.01);
    }

    /**
     * За концом графика прогноз держится на ритме: 1С не присылает плановых
     * строк дальше месяца, и без этой части квартал был бы занижен.
     */
    #[Test]
    public function за_горизонтом_графика_прогноз_опирается_на_ритм(): void
    {
        $client = $this->client();
        $this->plan($client, 100000, 10);

        // История платежей: три полных месяца примерно по 300 тысяч.
        foreach ([40, 70, 100] as $daysAgo) {
            $this->payment($client, 300000, $daysAgo);
        }

        $atHorizon = $this->service()->forecast($this->clients(), $this->filters(), CarbonImmutable::today()->addDays(10));
        $farAhead = $this->service()->forecast($this->clients(), $this->filters(), CarbonImmutable::today()->addDays(120));

        // Обещанное графиком за горизонтом не растёт — новых строк нет…
        $this->assertEqualsWithDelta($atHorizon['promised'], $farAhead['promised'], 0.01);
        // …а прогноз растёт, потому что отгрузки не прекращаются.
        $this->assertGreaterThan($atHorizon['total'] * 2, $farAhead['total']);
        $this->assertGreaterThan(0.0, $farAhead['rhythm_part']);
    }

    /** Коридор сценариев обнимает ожидание, а не заменяет его. */
    #[Test]
    public function коридор_сценариев_шире_ожидания_с_обеих_сторон(): void
    {
        $client = $this->client();
        $this->payment($client, 200000, 5);
        $this->plan($client, 400000, 7);

        $forecast = $this->service()->forecast($this->clients(), $this->filters(), CarbonImmutable::today()->addDays(14));

        $this->assertLessThanOrEqual($forecast['total'], $forecast['low']);
        $this->assertGreaterThanOrEqual($forecast['total'], $forecast['high']);

        // Экран обязан показать, на скольких месяцах посчитаны границы:
        // «коридор» без числа месяцев читался бы как точность, которой нет.
        $this->assertArrayHasKey('months', $forecast['calibration']);
        $this->assertIsInt($forecast['calibration']['months']);
    }

    #[Test]
    public function страница_открывается_на_конец_месяца_и_принимает_дату(): void
    {
        $client = $this->client();
        $this->payment($client, 5000, 2);
        $this->plan($client, 50000, 3);

        $default = $this->actingAs($this->actor)->get('/crm/finance/plan?scope=department')
            ->viewData('page')['props'];

        $this->assertSame(
            CarbonImmutable::today()->endOfMonth()->toDateString(),
            $default['forecast']['target'],
        );
        $this->assertNotEmpty($default['forecast']['curve']);
        $this->assertNull($default['rows'], 'Построчный список — вторичный слой, по умолчанию его нет.');

        $target = CarbonImmutable::today()->addDays(45)->toDateString();
        $custom = $this->actingAs($this->actor)->get('/crm/finance/plan?scope=department&target='.$target)
            ->viewData('page')['props'];

        $this->assertSame($target, $custom['forecast']['target']);

        // Прошедшая дата и мусор возвращают к умолчанию, а не роняют отчёт.
        foreach (['2020-01-01', 'вчера'] as $bad) {
            $props = $this->actingAs($this->actor)->get('/crm/finance/plan?scope=department&target='.$bad)
                ->viewData('page')['props'];

            $this->assertSame(
                CarbonImmutable::today()->endOfMonth()->toDateString(),
                $props['forecast']['target'],
                $bad,
            );
        }
    }

    #[Test]
    public function строки_графика_показываются_по_явному_запросу(): void
    {
        $client = $this->client();
        $this->plan($client, 50000, 3);

        $props = $this->actingAs($this->actor)->get('/crm/finance/plan?scope=department&group=none')
            ->viewData('page')['props'];

        $this->assertTrue($props['showLines']);
        $this->assertCount(1, $props['rows']['data']);
    }
}
