<?php

namespace Tests\Feature\User;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Деньги клиента в кабинете на регистре (v16.0.0, карточка fin-10).
 *
 * Деньги в кабинете скрыли 09.08.2026, потому что показывать завышенный долг
 * хуже, чем не показывать никакого. Тест закрепляет, что вернуть их можно только
 * осознанно — двумя флагами, — и что цифра при этом отвечает на вопрос клиента
 * «сколько я должен прямо сейчас», а не «каково сальдо».
 */
class CabinetLedgerFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Календарь показывает текущий месяц, а тесты ставят сроки «сегодня + N дней»:
        // в последние дни месяца срок уезжал в следующий, и тест падал по календарю.
        // Дата закреплена в середине месяца.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->startOfMonth()->addDays(9)->setTime(12, 0));

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->client = User::factory()->create();
        $this->organization = Organization::factory()->create(['is_stub' => false]);
        Company::factory()->create(['user_id' => $this->client->id]);

        config(['cabinet.finance_enabled' => true]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $this->client->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
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
            'document_kind' => 'shipment',
            'document_number' => '29УТ-006915',
        ]);
    }

    /**
     * Главное число дашборда — «к оплате сейчас», а не сальдо. В сальдо входят
     * обязательства, срок которых ещё не наступил, и клиент решил бы,
     * что должен больше, чем на самом деле.
     */
    #[Test]
    public function дашборд_показывает_к_оплате_сейчас_а_не_сальдо(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -100000,
            'amount_rub' => -100000,
            'date' => CarbonImmutable::today()->subDays(20)->toDateString(),
        ]);
        // Срок наступил.
        $this->plan(30000, 0, CarbonImmutable::today()->subDays(2)->toDateString());
        // Срок ещё не наступил — в «к оплате сейчас» не входит.
        $this->plan(70000, 0, CarbonImmutable::today()->addDays(10)->toDateString());

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('balance.due_now', 30000)
                ->where('balance.overdue', 30000)
                ->where('balance.balance', -100000));
    }

    /**
     * Переплата показывается переплатой, а не «долгом −5 000 ₽»: клиент читает
     * минус как ошибку сайта и звонит менеджеру.
     */
    #[Test]
    public function переплата_показывается_переплатой(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 5000,
            'amount_rub' => 5000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('balance.advance', 5000)
                ->where('balance.due_now', 0));
    }

    #[Test]
    public function ближайшая_дата_платежа_попадает_на_дашборд(): void
    {
        $due = CarbonImmutable::today()->addDays(6);
        $this->plan(40000, 0, $due->toDateString());

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('balance.next_due_date', $due->format('d.m.Y')));
    }

    #[Test]
    public function календарь_строится_из_регистра(): void
    {
        $this->plan(50000, 20000, CarbonImmutable::today()->addDays(3)->toDateString());
        $this->plan(15000, 0, CarbonImmutable::today()->subDays(9)->toDateString());

        $this->actingAs($this->client)
            ->get('/cabinet/payments/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Payments/Calendar')
                ->where('summary.week_amount', 30000)
                ->where('summary.overdue_amount', 15000)
                ->where('summary.overdue_count', 1)
                ->has('overdueEntries', 1));
    }

    /**
     * v16.7.0 (круг 12): график заказа — план платежа, а не долг. План заказа
     * клиенту не показывается нигде: ни просрочкой, ни в «к оплате сейчас»,
     * ни строкой календаря — 1С погашение по заказам не публикует, и после
     * отгрузки он задваивал бы реализацию.
     */
    #[Test]
    public function план_заказа_не_показывается_клиенту_ни_долгом_ни_просрочкой(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 151291,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(7)->toDateString(),
            'document_kind' => 'order',
            'document_number' => 'A2УТ-000653',
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('balance.overdue', 0)
                ->where('balance.due_now', 0));

        $this->actingAs($this->client)
            ->get('/cabinet/payments/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.overdue_amount', 0)
                ->where('summary.overdue_count', 0)
                ->has('overdueEntries', 0)
                ->has('entries', 0));
    }

    /**
     * Кейс ИП Дорофеевой (27.08.2026): заказ на 33 910,15 отгружен двумя
     * реализациями на ту же дату оплаты, план заказа 1С не погасила — календарь
     * показал 68 311,05 «к оплате», и клиент чуть не заплатил дважды.
     * В календаре и на дашборде — только реализации.
     */
    #[Test]
    public function отгруженный_заказ_не_задваивает_реализацию_в_календаре(): void
    {
        $due = CarbonImmutable::today()->addDays(2)->toDateString();

        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 33910.15,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'order',
            'document_number' => '29УТ-013310',
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 32145.15,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-007699',
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 1765,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-007700',
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 2)
                ->where('entries.0.shipment.number', '29УТ-007699')
                ->where('entries.1.shipment.number', '29УТ-007700')
                ->where('summary.week_amount', 33910.15)
                ->where('summary.month_amount', 33910.15));

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('balance.next_due_date', CarbonImmutable::parse($due)->format('d.m.Y')));
    }

    #[Test]
    public function акт_сверки_доступен_клиенту(): void
    {
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -10000,
            'amount_rub' => -10000,
            'date' => '2026-02-10',
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/reconciliation?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Payments/Reconciliation')
                ->where('act.closing_balance', -10000)
                ->has('act.rows', 1));
    }

    /**
     * У клиента бывает несколько юрлиц-контрагентов; сверяются по каждому
     * отдельно, поэтому акт и календарь принимают разрез company_id.
     */
    #[Test]
    public function акт_сверки_фильтруется_по_контрагенту(): void
    {
        $first = Company::factory()->create(['user_id' => $this->client->id]);
        $second = Company::factory()->create(['user_id' => $this->client->id]);

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -10000,
            'date' => '2026-02-10',
            'company_id' => $first->id,
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -70000,
            'date' => '2026-02-11',
            'company_id' => $second->id,
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/reconciliation?date_from=2026-02-01&date_to=2026-02-28&company_id='.$first->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('act.rows', 1)
                ->where('act.closing_balance', -10000)
                ->has('companies', 2)
                ->where('form.company_id', $first->id));
    }

    #[Test]
    public function календарь_фильтруется_по_контрагенту(): void
    {
        $first = Company::factory()->create(['user_id' => $this->client->id]);
        $second = Company::factory()->create(['user_id' => $this->client->id]);

        $due = CarbonImmutable::today()->addDays(3)->toDateString();
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 50000,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-000001',
            'company_id' => $first->id,
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 30000,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-000002',
            'company_id' => $second->id,
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/calendar?company_id='.$first->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 1)
                ->where('summary.week_amount', 50000)
                ->where('companyId', $first->id)
                ->has('companies', 2));
    }

    /**
     * Клиент видит, перед каким нашим юрлицом долг, но должен видеть и обратное:
     * какое из ЕГО юрлиц должно. Платёжку выставляет конкретный контрагент,
     * и без подписи бухгалтер клиента не поймёт, чью оплату ждут.
     */
    #[Test]
    public function долг_на_дашборде_дробится_по_юрлицам_клиента(): void
    {
        $first = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'ООО «Ромашка»']);
        $second = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'ИП Иванов']);

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'amount_rub' => -50000,
            'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
            'company_id' => $first->id,
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -20000,
            'amount_rub' => -20000,
            'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
            'company_id' => $second->id,
        ]);
        // Полностью закрытый контрагент в списке не появляется — как и в старом
        // расчёте, строки «0 ₽» клиенту не показываем.
        $closed = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'ООО «Закрытые расчёты»']);
        foreach ([-7000, 7000] as $amount) {
            $this->entry([
                'nature' => SettlementEntry::NATURE_FACT,
                'type' => $amount < 0 ? SettlementEntry::TYPE_SHIPMENT : SettlementEntry::TYPE_PAYMENT_IN,
                'amount' => $amount,
                'amount_rub' => $amount,
                'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
                'company_id' => $closed->id,
            ]);
        }

        // Просрочка только у первого юрлица — не должна размазаться на второе.
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 10000,
            'settled_amount' => 0,
            'date' => CarbonImmutable::today()->subDays(3)->toDateString(),
            'document_kind' => 'shipment',
            'document_number' => '29УТ-000003',
            'company_id' => $first->id,
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Карточка организации одна, юрлица клиента — списком внутри неё.
                ->has('balance.organizations', 1)
                ->where('balance.organizations.0.organization_id', $this->organization->id)
                ->where('balance.organizations.0.current_balance', -70000)
                ->where('balance.organizations.0.due_total', 70000)
                ->where('balance.organizations.0.advance_total', 0)
                ->where('balance.organizations.0.overdue_debt', 10000)
                ->has('balance.organizations.0.contractors', 2)
                // Ключ пары для кнопки «Платёжка» — у каждого юрлица свой.
                ->where('balance.organizations.0.contractors.0.company_id', $second->id)
                ->where('balance.organizations.0.contractors.1.company_id', $first->id)
                ->where('paymentOrdersEnabled', true)
                // Сортировка по имени контрагента: «И» < «О».
                ->where('balance.organizations.0.contractors.0.name', 'ИП Иванов')
                ->where('balance.organizations.0.contractors.0.current_balance', -20000)
                ->where('balance.organizations.0.contractors.0.overdue_debt', 0)
                ->where('balance.organizations.0.contractors.1.name', 'ООО «Ромашка»')
                ->where('balance.organizations.0.contractors.1.current_balance', -50000)
                ->where('balance.organizations.0.contractors.1.overdue_debt', 10000));
    }

    /**
     * Пока юрлицо у клиента одно, подпись «По контрагенту» — шум: строка одна
     * на организацию и без имени контрагента.
     */
    #[Test]
    public function единственный_контрагент_не_дробит_долг_на_дашборде(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        foreach ([-10000, -5000] as $amount) {
            $this->entry([
                'nature' => SettlementEntry::NATURE_FACT,
                'type' => SettlementEntry::TYPE_SHIPMENT,
                'amount' => $amount,
                'amount_rub' => $amount,
                'date' => CarbonImmutable::today()->subDays(10)->toDateString(),
                'company_id' => $company->id,
            ]);
        }

        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('balance.organizations', 1)
                ->has('balance.organizations.0.contractors', 0)
                ->where('balance.organizations.0.current_balance', -15000));
    }

    /**
     * Акт показывает только свои движения: скоуп задаёт сессия, а не параметр.
     */
    #[Test]
    public function чужие_движения_в_акт_клиента_не_попадают(): void
    {
        $stranger = User::factory()->create();
        SettlementEntry::factory()->create([
            'user_id' => $stranger->id,
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -99000,
            'currency_code' => 'RUB',
            'date' => '2026-02-10',
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/reconciliation?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('act.rows', 0));
    }

    /**
     * Раздел закрыт флагом кабинета целиком, независимо от источника расчёта.
     */
    #[Test]
    public function при_выключенном_флаге_кабинета_акт_недоступен(): void
    {
        config(['cabinet.finance_enabled' => false]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/reconciliation')
            ->assertNotFound();
    }

    /**
     * У строки графика — пара «контрагент → наше юрлицо» и ключи для платёжки:
     * бухгалтер клиента с двумя компаниями иначе не понимает, от кого платить,
     * а кнопка «Платёжка» открывает диалог именно по этой строке регистра.
     */
    #[Test]
    public function строка_календаря_знает_контрагента_и_наше_юрлицо(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'ООО «Ромашка»']);
        $this->organization->update(['name' => 'ООО «Пекадо»']);
        $stub = Organization::factory()->create(['is_stub' => true, 'name' => 'Заглушка']);

        $due = CarbonImmutable::today()->addDays(3)->toDateString();
        $line = $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 12000,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-000001',
            'company_id' => $company->id,
        ]);
        $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => 500,
            'settled_amount' => 0,
            'date' => $due,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-000002',
            'company_id' => $company->id,
            'organization_id' => $stub->id,
        ]);

        $this->actingAs($this->client)
            ->get('/cabinet/payments/calendar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 2)
                ->where('entries.0.id', $line->id)
                ->where('entries.0.company_id', $company->id)
                ->where('entries.0.organization_id', $this->organization->id)
                ->where('entries.0.company', 'ООО «Ромашка»')
                ->where('entries.0.organization', 'ООО «Пекадо»')
                // Заглушка организации клиенту не показывается — как в документах.
                ->where('entries.1.organization', null)
                ->where('paymentOrdersEnabled', true));
    }
}
