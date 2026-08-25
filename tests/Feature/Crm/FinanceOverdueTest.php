<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Раздел «Просрочка»: отборы (корзина задержки, порог остатка), разрезы и итоги.
 *
 * Главное свойство раздела — одна и та же цифра на всех его поверхностях:
 * итог в шапке, сумма корзин и любой разрез обязаны сходиться, иначе экран
 * читается как ошибка расчёта.
 */
class FinanceOverdueTest extends TestCase
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

    private function makeClient(?PersonalManager $card = null): User
    {
        return User::factory()->create(['personal_manager_id' => ($card ?? $this->card)->id]);
    }

    /** Просроченная строка графика: сумма, срок и организация. */
    private function overdueLine(User $client, float $amount, int $daysAgo, array $attrs = []): SettlementEntry
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today()->subDays($daysAgo + 5),
            'erp_created_at' => Carbon::today()->subDays($daysAgo + 5),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
            'paid_amount' => 0,
        ]);

        return SettlementEntry::factory()->create($attrs + [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    /**
     * Запрос идёт в отдельческом скоупе: раздел смотрит РОП, а личный скоуп
     * оставил бы за бортом партнёров других менеджеров.
     */
    private function props(string $query = ''): array
    {
        $query = $query === '' ? '?scope=department' : $query.'&scope=department';

        return $this->actingAs($this->actor)
            ->get('/crm/finance/overdue'.$query)
            ->viewData('page')['props'];
    }

    #[Test]
    public function корзина_задержки_отбирает_строки_и_корзины_в_сумме_дают_итог(): void
    {
        $client = $this->makeClient();

        $this->overdueLine($client, 1000, 3);    // 1–7 дней
        $this->overdueLine($client, 2000, 20);   // 15–30 дней
        $this->overdueLine($client, 4000, 200);  // более 60 дней

        $all = $this->props();
        $this->assertEqualsWithDelta(7000.0, $all['totals']['amount'], 0.01);

        // Сумма корзин равна итогу: границы диапазонов не перекрываются и не оставляют дыр.
        $this->assertEqualsWithDelta(
            7000.0,
            array_sum(array_column($all['aging']['buckets'], 'amount')),
            0.01,
        );

        $narrow = $this->props('?overdue_buckets[]=15_30');
        $this->assertEqualsWithDelta(2000.0, $narrow['totals']['amount'], 0.01);
        $this->assertSame(1, $narrow['totals']['lines']);

        // Плитки при выбранной корзине показывают всю картину — иначе переключиться
        // на соседнюю корзину было бы нечем.
        $this->assertEqualsWithDelta(
            7000.0,
            array_sum(array_column($narrow['aging']['buckets'], 'amount')),
            0.01,
        );
    }

    #[Test]
    public function порог_остатка_отсекает_копеечные_хвосты(): void
    {
        $client = $this->makeClient();

        $this->overdueLine($client, 5000, 10);
        $this->overdueLine($client, 0.04, 10);

        $this->assertSame(2, $this->props()['totals']['lines']);

        $filtered = $this->props('?min_amount=1');
        $this->assertSame(1, $filtered['totals']['lines']);
        $this->assertEqualsWithDelta(5000.0, $filtered['totals']['amount'], 0.01);

        // Порог действует и на плитки: разные цифры на одном экране читались бы
        // как ошибка расчёта.
        $this->assertEqualsWithDelta(
            5000.0,
            array_sum(array_column($filtered['aging']['buckets'], 'amount')),
            0.01,
        );
    }

    #[Test]
    public function разрез_не_меняет_итог_и_подписывает_менеджера(): void
    {
        $second = PersonalManager::create(['name' => 'Курочкина']);
        $organization = Organization::factory()->create(['name' => 'ООО Пекадо']);

        $mine = $this->makeClient();
        $theirs = $this->makeClient($second);

        $this->overdueLine($mine, 3000, 10, ['organization_id' => $organization->id]);
        $this->overdueLine($theirs, 1000, 40, ['organization_id' => $organization->id]);

        // Разрезы те же, что в балансах: одна сетка ячеек, разная вложенность.
        foreach (['partner', 'manager', 'org', 'company', 'org_partner', 'company_org_manager'] as $view) {
            $props = $this->props('?group='.$view);

            $this->assertSame($view, $props['group'], $view);
            $this->assertEqualsWithDelta(
                4000.0,
                array_sum(array_column($props['groupRows'], 'overdue_debt')),
                0.01,
                $view,
            );
        }

        // У партнёра менеджер один — он и подписан.
        $byPartner = collect($this->props('?group=partner')['groupRows'])->firstWhere('overdue_debt', 3000.0);
        $this->assertSame('Сухов', $byPartner['manager_name']);
        $this->assertSame(1, $byPartner['overdue_lines']);

        // За нашей организацией стоят двое: имя одного из них было бы враньём.
        $byOrganization = $this->props('?group=org_partner')['groupRows'][0];
        $this->assertSame('ООО Пекадо', $byOrganization['title']);
        $this->assertSame('2 менеджера', $byOrganization['manager_name']);
        $this->assertSame(2, $byOrganization['overdue_lines']);
        // Самая давняя строка — 40 дней назад, а не средняя по группе.
        $this->assertSame(40, $byOrganization['days_overdue']);

        // Под нашей организацией — оба партнёра, каждый со своей просрочкой.
        $this->assertCount(2, $byOrganization['children']);
        $this->assertEqualsWithDelta(
            4000.0,
            array_sum(array_column($byOrganization['children'], 'overdue_debt')),
            0.01,
        );
    }

    /**
     * Рядом с просрочкой стоит общий долг: сто тысяч просрочки при долге в сто
     * двадцать тысяч и при долге в пять миллионов — разные новости.
     */
    #[Test]
    public function разрез_показывает_общий_долг_рядом_с_просрочкой(): void
    {
        $client = $this->makeClient();
        $company = Company::factory()->create(['user_id' => $client->id, 'name' => 'ООО Ромашка']);

        $this->overdueLine($client, 2000, 10, ['company_id' => $company->id]);

        // Долг партнёра шире просрочки: отгружено на 5 000, просрочено 2 000.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $client->id,
            'company_id' => $company->id,
            'amount' => -5000,
            'amount_rub' => -5000,
            'currency_code' => 'RUB',
            'date' => Carbon::today()->subDays(20)->toDateString(),
        ]);

        $props = $this->props('?group=partner');
        $row = $props['groupRows'][0];

        $this->assertEqualsWithDelta(2000.0, $row['overdue_debt'], 0.01);
        $this->assertEqualsWithDelta(-5000.0, $row['current_balance'], 0.01);
        $this->assertEqualsWithDelta(-5000.0, $props['debtTotal'], 0.01);
    }

    /**
     * Ветка без просрочки в разрез не попадает: раздел о долге, который уже
     * пора требовать, и партнёр с нулевой просрочкой в нём только шум.
     */
    #[Test]
    public function партнёр_без_просрочки_в_разрез_не_попадает(): void
    {
        $withOverdue = $this->makeClient();
        $this->overdueLine($withOverdue, 1000, 10);

        $clean = $this->makeClient();
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $clean->id,
            'amount' => -7000,
            'amount_rub' => -7000,
            'currency_code' => 'RUB',
            'date' => Carbon::today()->subDays(3)->toDateString(),
        ]);

        $rows = $this->props('?group=partner')['groupRows'];

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(1000.0, $rows[0]['overdue_debt'], 0.01);
    }

    #[Test]
    public function сортировка_по_сумме_ставит_крупный_долг_наверх(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 500, 100);   // давняя, но мелкая
        $this->overdueLine($client, 9000, 2);    // свежая и крупная

        $bySum = $this->props('?sort=unpaid&direction=desc');
        $this->assertSame('unpaid', $bySum['sort']['column']);
        $this->assertEqualsWithDelta(9000.0, $bySum['rows']['data'][0]['unpaid_rub'], 0.01);

        // По умолчанию — по сроку: сверху самая давняя.
        $byDate = $this->props();
        $this->assertEqualsWithDelta(500.0, $byDate['rows']['data'][0]['unpaid_rub'], 0.01);
    }

    /**
     * Вес («рублёдни») различает то, чего не различают сумма и срок порознь:
     * мелкий долг, висящий год, разбирают раньше крупного недельного.
     */
    #[Test]
    public function вес_просрочки_считается_суммой_на_дни(): void
    {
        $client = $this->makeClient();

        $old = $this->makeClient();      // 50 000 ₽ × 300 дней = 15 000 000
        $fresh = $this->makeClient();    // 400 000 ₽ × 7 дней  =  2 800 000

        $this->overdueLine($old, 50000, 300);
        $this->overdueLine($fresh, 400000, 7);
        $this->overdueLine($client, 1000, 10);

        $props = $this->props('?group=partner');
        $rows = collect($props['groupRows'])->keyBy('title');

        $oldRow = $rows->first(fn (array $row): bool => abs($row['overdue_debt'] - 50000) < 0.01);
        $freshRow = $rows->first(fn (array $row): bool => abs($row['overdue_debt'] - 400000) < 0.01);

        $this->assertEqualsWithDelta(15_000_000.0, $oldRow['overdue_weight'], 1.0);
        $this->assertEqualsWithDelta(2_800_000.0, $freshRow['overdue_weight'], 1.0);

        // Средневзвешенный возраст — рублёдни на рубль долга.
        $this->assertSame(300, $oldRow['weighted_age']);
        $this->assertSame(7, $freshRow['weighted_age']);

        // Мелкий, но давний долг тяжелее крупного свежего — ради этого метрика
        // и вводилась.
        $this->assertGreaterThan($freshRow['overdue_weight'], $oldRow['overdue_weight']);

        // Итог по отбору: сумма весов строк и средний возраст по всей просрочке.
        $this->assertEqualsWithDelta(17_810_000.0, $props['totals']['weight'], 10.0);
        $this->assertSame(39, $props['totals']['weighted_age']);
    }

    /**
     * Приоритет — абсолютная метка, а не место в текущем отборе.
     *
     * Относительная шкала ломалась на перекосе: когда на одного партнёра
     * приходится три четверти веса, все прочие — включая годовой долг —
     * оказывались «низкими», и метка переставала быть информацией.
     */
    #[Test]
    public function приоритет_считается_по_сумме_и_возрасту_а_не_по_месту_в_списке(): void
    {
        $big = $this->makeClient();
        $stale = $this->makeClient();
        $pennies = $this->makeClient();

        $this->overdueLine($big, 900000, 60);    // крупный и не первый месяц
        $this->overdueLine($stale, 50000, 300);  // мелкий, но висит год
        $this->overdueLine($pennies, 400, 400);  // мелочь: возраст не поднимает

        $rows = collect($this->props('?group=partner')['groupRows'])->keyBy(
            fn (array $row): string => (string) round($row['overdue_debt']),
        );

        $this->assertSame('critical', $rows['900000']['severity']['key']);
        $this->assertSame('high', $rows['50000']['severity']['key']);
        // Сумма ограничивает уровень сверху: 400 ₽ — задача на взаимозачёт.
        $this->assertSame('low', $rows['400']['severity']['key']);

        // Тот же уровень и у отдельной строки списка, по тем же порогам.
        $line = collect($this->props()['rows']['data'])
            ->first(fn (array $row): bool => abs($row['unpaid_rub'] - 50000) < 0.01);
        $this->assertSame('high', $line['severity']['key']);

        // Соседство уровень не меняет: в корзине «более 60 дней» крупного нет,
        // и мелкий стал самым тяжёлым в отборе — но остался «высоким», а не
        // «критичным». Относительная шкала здесь перекрасила бы его.
        $narrow = collect($this->props('?overdue_buckets[]=60_plus&group=partner')['groupRows'])
            ->first(fn (array $row): bool => abs($row['overdue_debt'] - 50000) < 0.01);
        $this->assertSame('high', $narrow['severity']['key']);
    }

    /**
     * Последний платёж отличает того, кто отстаёт, от того, кто замолчал.
     *
     * Считаются только приходы: отгрузка или корректировка в регистре — не
     * признак того, что клиент платит.
     */
    #[Test]
    public function последний_платёж_виден_в_разрезе_и_в_строках(): void
    {
        $paying = $this->makeClient();
        $silent = $this->makeClient();

        $this->overdueLine($paying, 5000, 20);
        $this->overdueLine($silent, 5000, 20);

        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'user_id' => $paying->id,
            'amount' => 3000,
            'amount_rub' => 3000,
            'currency_code' => 'RUB',
            'date' => Carbon::today()->subDays(2)->toDateString(),
        ]);

        // У молчащего движение есть, но это отгрузка — платежом она не считается.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $silent->id,
            'amount' => -5000,
            'amount_rub' => -5000,
            'currency_code' => 'RUB',
            'date' => Carbon::today()->subDay()->toDateString(),
        ]);

        $rows = collect($this->props('?group=partner')['groupRows'])->keyBy('entity_id');

        $this->assertSame(Carbon::today()->subDays(2)->format('d.m.Y'), $rows[$paying->id]['last_payment_date']);
        $this->assertSame(2, $rows[$paying->id]['days_since_payment']);

        $this->assertNull($rows[$silent->id]['last_payment_date']);
        $this->assertNull($rows[$silent->id]['days_since_payment']);

        // То же в построчном списке — там дата берётся по партнёру строки.
        $lines = collect($this->props()['rows']['data']);

        $this->assertSame(
            2,
            $lines->first(fn (array $row): bool => $row['client']['id'] === $paying->id)['days_since_payment'],
        );
        $this->assertNull(
            $lines->first(fn (array $row): bool => $row['client']['id'] === $silent->id)['days_since_payment'],
        );
    }

    /** Сортировка по весу ставит наверх не самое крупное и не самое давнее. */
    #[Test]
    public function сортировка_по_весу_поднимает_дорогое_ожидание(): void
    {
        $client = $this->makeClient();

        $this->overdueLine($client, 400000, 7);    //  2 800 000
        $this->overdueLine($client, 50000, 300);   // 15 000 000
        $this->overdueLine($client, 1000, 400);    //    400 000 — самое давнее

        $rows = $this->props('?sort=weight&direction=desc')['rows']['data'];

        $this->assertEqualsWithDelta(50000.0, $rows[0]['unpaid_rub'], 0.01);
        $this->assertEqualsWithDelta(400000.0, $rows[1]['unpaid_rub'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $rows[2]['unpaid_rub'], 0.01);
    }

    #[Test]
    public function план_по_заказу_в_просрочку_не_попадает(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 1000, 10);
        $this->overdueLine($client, 5000, 10, ['document_kind' => 'order']);

        $props = $this->props();

        $this->assertSame(1, $props['totals']['lines']);
        $this->assertEqualsWithDelta(1000.0, $props['totals']['amount'], 0.01);
    }

    #[Test]
    public function мусор_в_адресе_не_роняет_отчёт(): void
    {
        $client = $this->makeClient();
        $this->overdueLine($client, 1000, 10);

        $props = $this->props('?group=выдумка&overdue_buckets[]=вчера&min_amount=-5&sort=xxx');

        $this->assertSame('', $props['group']);
        $this->assertSame([], $props['filters']['overdue_buckets']);
        $this->assertSame('due_date', $props['sort']['column']);
        $this->assertEqualsWithDelta(1000.0, $props['totals']['amount'], 0.01);
    }
}
