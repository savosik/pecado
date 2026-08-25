<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Currency;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Финансовый раздел CRM: изоляция скоупа, сведение валют, бакеты просрочки.
 *
 * Все деньги считаются регистром взаиморасчётов (fin-11): фикстуры — плановые
 * строки `settlement_entries`, привязанные к реализации по `document_uuid`.
 */
class FinanceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-department.view', 'crm-clients-all.view', 'crm-tasks.create'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);
    }

    /**
     * @return array{0: User, 1: PersonalManager}
     */
    private function makeManagerActor(bool $head = false): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('crm-finance.view');

        if ($head) {
            $actor->givePermissionTo(['crm-department.view', 'crm-clients-all.view']);
        }

        $card = PersonalManager::create(['name' => $head ? 'РОП' : 'Менеджер', 'user_id' => $actor->id]);

        return [$actor->fresh(), $card];
    }

    private function makeClient(PersonalManager $card): User
    {
        return User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function makeShipment(User $client, array $attrs = []): Shipment
    {
        return Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => Carbon::today(),
            'erp_created_at' => Carbon::today(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
            'paid_amount' => 0,
        ], $attrs));
    }

    /**
     * Плановая строка регистра по реализации — то, чем 1С описывает ожидаемый
     * платёж: сумма, срок и уже закрытая часть.
     */
    private function makePlan(User $client, Shipment $shipment, float $amount, string $dueDate, float $settled = 0.0): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'document_number' => $shipment->number,
            'date' => $dueDate,
            'amount' => $amount,
            'settled_amount' => $settled,
            'currency_code' => $shipment->currency_code,
        ]);
    }

    /**
     * Фактическое движение — то, из чего складывается сальдо контрагента.
     */
    private function makeFact(User $client, float $amount, array $attrs = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attrs + [
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $client->id,
            'amount' => $amount,
            'amount_rub' => $amount,
            'currency_code' => 'RUB',
        ]);
    }

    #[Test]
    public function it_denies_access_without_permission(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::firstOrCreate(['name' => 'crm-clients.view', 'guard_name' => 'web']));

        $this->actingAs($actor->fresh())->get('/crm/finance')->assertForbidden();
    }

    #[Test]
    public function manager_sees_only_own_clients_money(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $mine = $this->makeClient($card);

        $otherCard = PersonalManager::create(['name' => 'Чужой']);
        $foreign = $this->makeClient($otherCard);

        $this->makePlan($mine, $this->makeShipment($mine), 1000, Carbon::today()->addDays(5)->toDateString());

        $this->makePlan($foreign, $this->makeShipment($foreign), 7777, Carbon::today()->addDays(5)->toDateString());

        $response = $this->actingAs($actor)->get('/crm/finance');

        $response->assertOk();
        $summary = $response->viewData('page')['props']['summary'];

        $this->assertSame(1000.0, $summary['expected_period']);
    }

    /**
     * Раздел открывается сфокусированным на своих даже у того, кто видит отдел:
     * отсутствие `scope` означает «мои», а не «все». Расфокус — явное действие.
     */
    #[Test]
    public function department_scope_is_opt_in_and_manager_filter_needs_it(): void
    {
        [$head, $headCard] = $this->makeManagerActor(head: true);
        $ownClient = $this->makeClient($headCard);

        $otherCard = PersonalManager::create(['name' => 'Второй']);
        $otherClient = $this->makeClient($otherCard);

        foreach ([[$ownClient, 500], [$otherClient, 300]] as [$client, $amount]) {
            $this->makePlan($client, $this->makeShipment($client), $amount, Carbon::today()->addDays(3)->toDateString());
        }

        // По умолчанию — только свои, хотя права на отдел есть.
        $default = $this->actingAs($head)->get('/crm/finance');
        $this->assertSame(500.0, $default->viewData('page')['props']['summary']['expected_period']);

        // Расфокус показывает отдел целиком.
        $all = $this->actingAs($head)->get('/crm/finance?scope=department');
        $this->assertSame(800.0, $all->viewData('page')['props']['summary']['expected_period']);

        $filtered = $this->actingAs($head)->get('/crm/finance?scope=department&manager_ids[]='.$otherCard->id);
        $this->assertSame(300.0, $filtered->viewData('page')['props']['summary']['expected_period']);

        // Рядовой менеджер тем же параметром чужие деньги не достанет.
        [$manager, $managerCard] = $this->makeManagerActor();
        $managerClient = $this->makeClient($managerCard);

        $this->makePlan($managerClient, $this->makeShipment($managerClient), 100, Carbon::today()->addDays(3)->toDateString());

        $attempt = $this->actingAs($manager)->get('/crm/finance?manager_ids[]='.$otherCard->id);

        $this->assertSame(100.0, $attempt->viewData('page')['props']['summary']['expected_period']);
        $this->assertSame([], $attempt->viewData('page')['props']['filters']['manager_ids']);
    }

    #[Test]
    public function foreign_currency_is_converted_to_rubles(): void
    {
        Currency::factory()->create(['code' => 'BYN', 'is_base' => false, 'exchange_rate' => 30]);

        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client, ['currency_code' => 'BYN', 'total_amount' => 100]), 100, Carbon::today()->addDays(2)->toDateString());

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(3000.0, $props['summary']['expected_period']);
        $this->assertSame(3000.0, $props['upcomingRows'][0]['unpaid_rub']);
        $this->assertSame(100.0, $props['upcomingRows'][0]['unpaid_amount']);
    }

    /**
     * Категории «долг без графика» у регистра нет: 1С присылает плановые строки
     * по каждому документу, а реализация без них — не долг без срока, а документ,
     * о котором учётная система молчит. Блок остаётся пустым, а не выдумывает сумму.
     */
    #[Test]
    public function shipments_without_plan_do_not_invent_debt(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makeShipment($client, ['total_amount' => 2500, 'paid_amount' => 500]);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(0.0, $props['summary']['expected_period']);
        $this->assertSame(0.0, $props['summary']['no_schedule_amount']);
        $this->assertSame(0, $props['noScheduleCount']);
    }

    #[Test]
    public function overdue_rows_fall_into_aging_buckets(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client), 900, Carbon::today()->subDays(45)->toDateString());

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];
        $buckets = collect($props['aging']['buckets'])->keyBy('key');

        $this->assertSame(900.0, $buckets['31_60']['amount']);
        $this->assertSame(0.0, $buckets['1_7']['amount']);
        $this->assertSame(900.0, $props['summary']['overdue_amount']);
        $this->assertSame(1, $props['summary']['overdue_clients']);
    }

    #[Test]
    public function closed_schedule_lines_are_not_expected(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client, ['paid_amount' => 1000]), 1000, Carbon::today()->addDays(4)->toDateString(), 1000.0);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(0.0, $props['summary']['expected_period']);
        $this->assertSame([], $props['upcomingRows']);
    }

    /**
     * У клиента бывает несколько контрагентов — 1С ведёт расчёты по ним, а не по
     * партнёру. Строка клиента должна быть суммой его контрагентов; раньше сюда
     * подставлялась просрочка всего партнёра, из-за чего одно и то же число
     * дублировалось в каждой строке его ИНН.
     */
    #[Test]
    public function balances_group_contractors_under_client(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        foreach ([['7701234567', -1500, 600], ['7739999999', -500, 150]] as [$taxId, $balance, $overdue]) {
            $company = Company::factory()->create(['user_id' => $client->id, 'tax_id' => $taxId]);

            // Сальдо — сумма фактических движений контрагента.
            $this->makeFact($client, $balance, ['company_id' => $company->id]);

            // Просрочка — непогашенная плановая строка с прошедшим сроком.
            $this->makePlan($client, $this->makeShipment($client), $overdue, Carbon::today()->subDays(10)->toDateString());
            SettlementEntry::query()->latest('id')->first()->update(['company_id' => $company->id]);
        }

        $props = $this->actingAs($actor)->get('/crm/finance/balances')->viewData('page')['props'];

        $this->assertCount(1, $props['balances'], 'Два контрагента одного клиента — одна строка верхнего уровня.');

        $row = $props['balances'][0];

        $this->assertCount(2, $row['contractors']);
        $this->assertSame(-2000.0, $row['current_balance']);
        $this->assertSame(750.0, $row['overdue_debt']);
        $this->assertSame(['7701234567', '7739999999'], array_column($row['contractors'], 'tax_id'));

        // Сверка живёт в шапке одной строкой, а не в колонке у каждого контрагента.
        $this->assertArrayNotHasKey('overdue_by_schedule', $row);
        $this->assertArrayHasKey('overdue_amount', $props['summary']);
    }

    /**
     * Строка, закрытая авансом по заказу, из просрочки уходит.
     *
     * До зачёта раздел показывал такие строки как долг: 1С разносит почти половину
     * денег на заказы, а `shipments.paid_amount` от этого не растёт.
     */
    #[Test]
    public function schedule_line_covered_by_order_prepayment_is_not_overdue(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client), 1000, Carbon::today()->subDays(30)->toDateString(), 1000.0);

        $props = $this->actingAs($actor)->get('/crm/finance')->viewData('page')['props'];

        $this->assertSame(0.0, $props['summary']['overdue_amount']);
        $this->assertSame(0, $props['summary']['overdue_count']);
        $this->assertSame([], $props['overdueRows']);
    }

    #[Test]
    public function overdue_page_ignores_period_filter(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client), 750, Carbon::today()->subDays(120)->toDateString());

        // Период фильтров смотрит вперёд, а просрочка всё равно должна быть видна.
        $props = $this->actingAs($actor)
            ->get('/crm/finance/overdue?date_from='.Carbon::today()->toDateString())
            ->viewData('page')['props'];

        $this->assertSame(1, $props['rows']['total']);
        $this->assertSame(750.0, $props['rows']['data'][0]['unpaid_rub']);
        $this->assertTrue($props['rows']['data'][0]['is_overdue']);
    }

    /**
     * Агрегаты обязаны собирать свой список колонок.
     *
     * `selectRaw` только добавляет к тем, что выбрал plannedQuery, — и MySQL с
     * only_full_group_by отвергает такой запрос (ошибка 1055), а SQLite молча
     * выполняет. Из-за этого раздел падал на проде при зелёных тестах; проверяем
     * форму SQL, раз поведение движков расходится.
     */
    #[Test]
    public function aggregate_queries_do_not_carry_row_columns(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client), 500, Carbon::today()->addDays(3)->toDateString());

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($actor)->get('/crm/finance')->assertOk();

        $grouped = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'group by'),
        ));

        $this->assertNotEmpty($grouped, 'Ожидались запросы с группировкой — иначе проверка бессмысленна.');

        foreach ($grouped as $sql) {
            $select = substr($sql, 0, (int) strpos($sql, ' from '));

            foreach (['shipment_number', 'client_name', 'manager_name'] as $rowColumn) {
                $this->assertStringNotContainsString(
                    $rowColumn,
                    $select,
                    "Агрегирующий запрос тянет построчную колонку «{$rowColumn}» — MySQL отвергнет его по only_full_group_by: {$sql}",
                );
            }
        }
    }

    #[Test]
    public function export_returns_xlsx_within_scope(): void
    {
        [$actor, $card] = $this->makeManagerActor();
        $client = $this->makeClient($card);

        $this->makePlan($client, $this->makeShipment($client), 1200, Carbon::today()->addDays(6)->toDateString());

        $response = $this->actingAs($actor)->get('/crm/finance/export');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertNotEmpty($response->streamedContent());
    }
}
