<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\LedgerPaymentForecastService;
use App\Services\Crm\Finance\PaymentForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Счётное ядро CRM на регистре взаиморасчётов (v16.0.0, карточка fin-07).
 *
 * Две вещи, ради которых тест существует:
 *
 * 1. Флаг переключает реализацию **в одной точке** и по умолчанию выключен —
 *    иначе включение регистра на проде показало бы всем нулевой долг.
 * 2. Ledger-реализация отдаёт ту же форму данных, что и старая: экраны, выгрузка
 *    и календарь читают один и тот же контракт, и подмена источника не должна
 *    менять ни набор ключей, ни смысл чисел.
 */
class FinanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const DOCUMENT_UUID = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

    private User $client;

    private Company $company;

    private Organization $organization;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->organization = Organization::factory()->create();
        $this->shipment = Shipment::factory()->create([
            'uuid' => self::DOCUMENT_UUID,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'number' => '29УТ-006915',
            'total_amount' => 120000,
            'currency_code' => 'RUB',
        ]);
    }

    private function forecast(): LedgerPaymentForecastService
    {
        return app(LedgerPaymentForecastService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'document_uuid' => self::DOCUMENT_UUID,
            'document_kind' => 'shipment',
        ]);
    }

    private function plan(float $amount, float $settled, string $date): SettlementEntry
    {
        return $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => $amount,
            'settled_amount' => $settled,
            'date' => $date,
        ]);
    }

    private function clients(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->whereKey($this->client->id)->select('users.id');
    }

    private function filters(): FinanceFilters
    {
        return new FinanceFilters(
            dateFrom: CarbonImmutable::today()->subDays(30),
            dateTo: CarbonImmutable::today()->addDays(30),
        );
    }

    /**
     * Флаг по умолчанию выключен: включение регистра — осознанное решение после
     * зелёной сверки, а не побочный эффект деплоя.
     */
    /**
     * Счётное ядро одно: выбирать больше не из чего (fin-11).
     */
    #[Test]
    public function счётное_ядро_всегда_регистр(): void
    {
        $this->assertInstanceOf(LedgerPaymentForecastService::class, app(PaymentForecast::class));
    }

    #[Test]
    public function непогашенная_плановая_строка_попадает_в_план(): void
    {
        $this->plan(120000, 100000, CarbonImmutable::today()->addDays(5)->toDateString());

        $rows = $this->forecast()->plannedQuery($this->clients(), $this->filters())->get();

        $this->assertCount(1, $rows);

        $row = $this->forecast()->row($rows->first());

        $this->assertEqualsWithDelta(20000.0, $row['unpaid_amount'], 0.01);
        $this->assertSame('29УТ-006915', $row['shipment']['number']);
        $this->assertFalse($row['is_overdue']);
    }

    /**
     * Предоплата по заказу — 38 % движений регистра. Старая модель их не знала,
     * и спрятать их значило бы занизить ожидаемые деньги ровно на ту сумму,
     * ради которой эпик затевался.
     */
    #[Test]
    public function предоплата_по_заказу_попадает_в_план(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 40000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->addDays(5)->toDateString(),
            'document_uuid' => 'a2c4e6f8-1b3d-4507-9e2a-6c8f4d1b7e35',
            'document_kind' => 'order',
            'document_number' => 'A2УТ-000417',
        ]);

        $rows = $this->forecast()->plannedQuery($this->clients(), $this->filters())->get();

        $this->assertCount(1, $rows);

        $row = $this->forecast()->row($rows->first());

        $this->assertEqualsWithDelta(40000.0, $row['unpaid_amount'], 0.01);
        $this->assertSame('Заказ', $row['shipment']['kind_label']);
        $this->assertSame('A2УТ-000417', $row['shipment']['number']);
        // Карточки реализации у заказа нет — ссылка обязана быть пустой,
        // иначе интерфейс предложит перейти в никуда.
        $this->assertNull($row['shipment']['url']);
    }

    /**
     * Строка по реализации сохраняет ссылку на её карточку.
     */
    #[Test]
    public function строка_по_реализации_ведёт_на_карточку(): void
    {
        $this->plan(120000, 0, CarbonImmutable::today()->addDays(5)->toDateString());

        $row = $this->forecast()->row(
            $this->forecast()->plannedQuery($this->clients(), $this->filters())->first(),
        );

        $this->assertSame('Реализация', $row['shipment']['kind_label']);
        $this->assertSame($this->shipment->id, $row['shipment']['id']);
        $this->assertNotNull($row['shipment']['url']);
    }

    /**
     * Закрытая строка — это уже пришедшие деньги. Показав её как ожидаемую,
     * отчёт задвоил бы выручку.
     */
    #[Test]
    public function закрытая_строка_в_план_не_идёт(): void
    {
        $this->plan(120000, 120000, CarbonImmutable::today()->addDays(5)->toDateString());

        $this->assertCount(0, $this->forecast()->plannedQuery($this->clients(), $this->filters())->get());
    }

    /**
     * Фактические движения — не план: они уже в балансе, и в ожидаемых деньгах
     * им места нет.
     */
    #[Test]
    public function фактические_движения_в_план_не_попадают(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -120000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->assertCount(0, $this->forecast()->plannedQuery($this->clients(), $this->filters())->get());
    }

    #[Test]
    public function просрочка_разложена_по_корзинам_давности(): void
    {
        $this->plan(30000, 0, CarbonImmutable::today()->subDays(3)->toDateString());
        $this->plan(50000, 0, CarbonImmutable::today()->subDays(45)->toDateString());
        $this->plan(70000, 0, CarbonImmutable::today()->addDays(10)->toDateString());

        $aging = $this->forecast()->aging($this->clients(), $this->filters());

        $this->assertEqualsWithDelta(80000.0, $aging['total'], 0.01);
        $this->assertSame(2, $aging['count']);
        $this->assertSame(1, $aging['clients']);

        $buckets = collect($aging['buckets'])->keyBy('key');
        $this->assertEqualsWithDelta(30000.0, $buckets['1_7']['amount'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $buckets['31_60']['amount'], 0.01);
    }

    /**
     * v16.7.0 (круг 12): график заказа — план платежа, а не долг. Просроченный план
     * заказа не попадает ни в корзины давности, ни в просрочку балансов, ни в сводку,
     * но из плана поступлений не исчезает — деньги по нему по-прежнему ожидаются.
     */
    #[Test]
    public function просроченный_план_заказа_не_считается_просрочкой(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'document_kind' => 'order',
            'amount' => 151291,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(7)->toDateString(),
        ]);

        $aging = $this->forecast()->aging($this->clients(), $this->filters());
        $this->assertSame(0.0, $aging['total']);
        $this->assertSame(0, $aging['count']);

        $summary = $this->forecast()->summary($this->clients(), $this->filters());
        $this->assertSame(0.0, $summary['overdue_amount']);
        // План жив: ожидаемые деньги периода строку заказа включают.
        $this->assertEqualsWithDelta(151291.0, $summary['expected_period'], 0.01);

        $balances = $this->forecast()->balances($this->clients());
        $this->assertSame([], $balances);
    }

    /**
     * v16.7.0 (круг 12): «Первичный документ» переноса остатков — реальная дебиторка,
     * в просрочке участвует наравне с реализацией.
     */
    #[Test]
    public function просроченная_строка_первичного_документа_считается_просрочкой(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'document_kind' => 'initial',
            'document_uuid' => 'aa1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d99',
            'amount' => 50000,
            'settled_amount' => 20000,
            'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
        ]);

        $aging = $this->forecast()->aging($this->clients(), $this->filters());
        $this->assertEqualsWithDelta(30000.0, $aging['total'], 0.01);

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'amount_rub' => -50000,
            'date' => CarbonImmutable::today()->subDays(60)->toDateString(),
        ]);

        $balances = $this->forecast()->balances($this->clients());
        $this->assertEqualsWithDelta(30000.0, $balances[0]['overdue_debt'], 0.01);
    }

    #[Test]
    public function план_по_дням_сводится_в_рубли(): void
    {
        $day = CarbonImmutable::today()->addDays(3)->toDateString();
        $this->plan(30000, 10000, $day);
        $this->plan(50000, 0, $day);

        $plan = $this->forecast()->dailyPlan($this->clients(), $this->filters());

        $this->assertEqualsWithDelta(70000.0, $plan[$day], 0.01);
    }

    /**
     * Знак уже применён 1С, поэтому возврат вычитается сам — без CASE
     * по направлению платежа.
     */
    #[Test]
    public function факт_по_дням_учитывает_возврат_знаком(): void
    {
        $day = CarbonImmutable::today()->toDateString();

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 100000,
            'amount_rub' => 100000,
            'date' => $day,
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_OUT,
            'amount' => -30000,
            'amount_rub' => -30000,
            'date' => $day,
        ]);

        $facts = $this->forecast()->factsByDay($this->clients(), $day, $day);

        $this->assertEqualsWithDelta(70000.0, $facts[$day]['amount'], 0.01);
        $this->assertSame(2, $facts[$day]['count']);
    }

    /**
     * Формула акта сверки целиком: баланс это сумма фактических движений,
     * и никакой отдельной арифметики для него нет.
     */
    #[Test]
    public function баланс_и_просрочка_считаются_из_одной_ленты(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -120000,
            'amount_rub' => -120000,
            'date' => CarbonImmutable::today()->subDays(20)->toDateString(),
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 65000,
            'amount_rub' => 65000,
            'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
        ]);
        $this->plan(120000, 65000, CarbonImmutable::today()->subDays(5)->toDateString());

        $summary = $this->forecast()->summary($this->clients(), $this->filters());

        $this->assertEqualsWithDelta(-55000.0, $summary['debt_total'], 0.01);
        $this->assertEqualsWithDelta(-55000.0, $summary['balance_fact'], 0.01);
        $this->assertEqualsWithDelta(55000.0, $summary['overdue_amount'], 0.01);
        // Категории «долг без графика» в регистре нет: ключ сохранён ради формы.
        $this->assertSame(0.0, $summary['no_schedule_amount']);
    }

    /**
     * Переплата одного контрагента не гасит долг другого: взаимозачёт делает 1С,
     * а не отчёт. Поэтому аванс считается по контрагентам, а не одним итогом.
     */
    #[Test]
    public function свободный_аванс_это_положительный_баланс(): void
    {
        $other = Company::factory()->create(['user_id' => $this->client->id]);

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 40000,
            'amount_rub' => 40000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -90000,
            'amount_rub' => -90000,
            'company_id' => $other->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $summary = $this->forecast()->summary($this->clients(), $this->filters());

        $this->assertEqualsWithDelta(40000.0, $summary['advances'], 0.01);
        $this->assertEqualsWithDelta(-50000.0, $summary['debt_total'], 0.01);
    }

    #[Test]
    public function балансы_группируются_по_партнёру_и_контрагенту(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -120000,
            'amount_rub' => -120000,
            'date' => CarbonImmutable::today()->subDays(20)->toDateString(),
        ]);
        $this->plan(120000, 0, CarbonImmutable::today()->subDays(5)->toDateString());

        $balances = $this->forecast()->balances($this->clients());

        $this->assertCount(1, $balances);
        $this->assertEqualsWithDelta(-120000.0, $balances[0]['current_balance'], 0.01);
        $this->assertEqualsWithDelta(120000.0, $balances[0]['overdue_debt'], 0.01);
        $this->assertCount(1, $balances[0]['contractors']);
    }

    /**
     * Категории «долг без графика» в регистре не существует. Метод сохранён
     * ради контракта и обязан отдавать выборку, к которой контроллер спокойно
     * дописывает свою сортировку.
     */
    #[Test]
    public function блок_без_графика_пуст_и_не_ломает_запрос(): void
    {
        $rows = $this->forecast()->noScheduleQuery($this->clients(), $this->filters())
            ->orderByDesc('s.date')
            ->get();

        $this->assertCount(0, $rows);
        $this->assertSame(0, $this->forecast()->noScheduleCount($this->clients(), $this->filters()));
        $this->assertSame(0.0, $this->forecast()->noScheduleTotal($this->clients(), $this->filters()));
    }

    /**
     * Агрегаты обязаны собирать свой список колонок: `selectRaw` только добавляет
     * к выбранным в plannedQuery, и MySQL с only_full_group_by отвергает такой
     * запрос, а SQLite молча выполняет. Проверяем форму SQL, раз движки расходятся.
     */
    #[Test]
    public function агрегаты_не_тянут_построчные_колонки(): void
    {
        $this->plan(30000, 0, CarbonImmutable::today()->addDays(2)->toDateString());

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->forecast()->dailyPlan($this->clients(), $this->filters());
        $this->forecast()->summary($this->clients(), $this->filters());

        $grouped = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'group by') && str_contains($sql, 'settlement_entries'),
        ));

        $this->assertNotEmpty($grouped, 'Ожидались запросы с группировкой — иначе проверка бессмысленна.');

        foreach ($grouped as $sql) {
            $select = substr($sql, 0, (int) strpos($sql, ' from '));

            foreach (['shipment_number', 'client_name', 'manager_name'] as $rowColumn) {
                $this->assertStringNotContainsString(
                    $rowColumn,
                    $select,
                    "Агрегирующий запрос тянет построчную колонку «{$rowColumn}»: {$sql}",
                );
            }
        }
    }

    /**
     * Итог журнала «Реализации» считается тем же ядром, что пульт и просрочка.
     *
     * Иначе журнал показывал бы своё число: свой расчёт по документам уже дважды
     * дал ложь — сначала по `shipments.paid_amount` (44,4 млн «долга» против
     * 11,5 млн реальных), потом по `shipment_payment_schedules`, которая
     * с v16.0.0 не пополняется вовсе.
     */
    #[Test]
    public function итог_оплаты_реализаций_берётся_из_регистра(): void
    {
        // Документ на 120 000: плановые строки на 100 000, закрыто 70 000.
        $this->plan(60000, 60000, CarbonImmutable::today()->subDays(5)->toDateString());
        $this->plan(40000, 10000, CarbonImmutable::today()->addDays(5)->toDateString());

        // Переплаченная строка не должна раздувать «оплачено» выше своей суммы.
        $this->plan(1000, 4000, CarbonImmutable::today()->toDateString());

        // Реализация без плановых строк: в остаток не попадает, о ней сообщают числом.
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'uuid' => (string) Str::uuid(),
            'total_amount' => 9000,
            'currency_code' => 'RUB',
        ]);

        $totals = $this->forecast()->shipmentPaymentTotals(
            Shipment::query()->where('user_id', $this->client->id),
        );

        $this->assertSame(1, $totals['without_plan']);
        $this->assertCount(1, $totals['buckets']);

        $bucket = $totals['buckets'][0];

        // 60 000 + 10 000 + 1 000 (а не 4 000).
        $this->assertEqualsWithDelta(71000.0, $bucket['paid'], 0.01);
        // Не закрыто только по второй строке: 40 000 − 10 000.
        $this->assertEqualsWithDelta(30000.0, $bucket['unpaid'], 0.01);
        $this->assertSame(1, $bucket['docs']);
    }
}
