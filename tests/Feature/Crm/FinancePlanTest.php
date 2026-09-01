<?php

namespace Tests\Feature\Crm;

use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Раздел «План поступлений»: график платежей из 1С и фактические оплаты.
 *
 * Проверяется главное свойство раздела — он ничего не досчитывает. Всё, что
 * на экране, должно выводиться из строк регистра и сходиться с разделом
 * «Просрочка»; любая поправка «на дисциплину» здесь была бы регрессией.
 */
class FinancePlanTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $card;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->card->id]);
    }

    #[Test]
    public function план_равен_непогашенному_остатку_графика(): void
    {
        $due = now()->addDays(3)->toDateString();
        $this->scheduleFor($this->client, $due, 3000.00, paid: 1200.00);

        $this->planPage()
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Finance/Plan')
                // Ждём ровно остаток: ни поправки на дисциплину, ни коридора
                // сценариев в юридическом плане быть не может.
                ->where('summary.total', 1800));
    }

    /**
     * Заказ в план не попадает ни при каких условиях.
     *
     * Клиент может отменить заказ, и не случится ничего; обязательство создаёт
     * отгрузка, вместе с которой приходит её собственный график. Хуже того,
     * после отгрузки план заказа дублирует план реализации: на 01.09.2026 из
     * 3,1 млн ₽ по заказам 2,04 млн приходились на уже отгруженные — они
     * считались дважды.
     */
    #[Test]
    public function заказ_в_план_не_попадает(): void
    {
        $due = now()->addDays(3)->toDateString();
        $this->scheduleFor($this->client, $due, 3000.00);
        $this->orderScheduleFor($this->client, $due, 500.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 3000)
                ->where('partners.0.total', 3000));
    }

    #[Test]
    public function заказ_не_попадает_ни_в_календарь_ни_в_детали_дня(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->orderScheduleFor($this->client, $day->toDateString(), 500.00);

        $this->planPage(['view' => 'calendar', 'day' => $day->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar.days.'.$day->toDateString().'.plan', 3000)
                ->where('dayPlan.0.amount', 3000)
                ->has('dayPlan.0.documents', 1));
    }

    #[Test]
    public function закрытая_строка_денег_больше_не_ждёт(): void
    {
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 3000.00, paid: 3000.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 0)
                ->where('overdue.total', 0));
    }

    #[Test]
    public function просроченное_вынесено_из_ожиданий_периода(): void
    {
        $this->scheduleFor($this->client, now()->subDays(20)->toDateString(), 1500.00);
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // В ожиданиях только будущий срок: приписать просроченное
                // какому-то дню значило бы выдумать срок, которого нет.
                ->where('summary.total', 2000)
                ->where('overdue.total', 1500)
                ->where('overdue.lines', 1));
    }

    #[Test]
    public function просрочка_считается_строго_без_льготы_и_отсечки(): void
    {
        // Сто рублей, просроченные на день: ниже отсечки дебиторки (5 000 ₽)
        // и внутри её льготного периода, но юридически это просрочка.
        $this->scheduleFor($this->client, now()->subDay()->toDateString(), 100.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('overdue.total', 100)
                ->where('overdue.lines', 1));
    }

    #[Test]
    public function просрочка_по_заказу_в_блок_не_попадает(): void
    {
        // Заказ — намерение: долг создаёт отгрузка, и счёт на предоплату
        // не должен висеть просрочкой вечно (круг 12 сверки).
        $this->orderScheduleFor($this->client, now()->subDays(10)->toDateString(), 700.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('overdue.total', 0));
    }

    #[Test]
    public function горизонт_графика_объявлен_явно(): void
    {
        $last = now()->addDays(5)->toDateString();
        $this->scheduleFor($this->client, $last, 1000.00);

        $this->planPage(['date_from' => now()->toDateString(), 'date_to' => now()->addDays(60)->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.horizon', $last)
                // Пустота за горизонтом — отсутствие данных, а не ноль денег.
                ->where('summary.beyond_horizon', true));
    }

    #[Test]
    public function период_отбирает_строки_по_плановой_дате_включая_границы(): void
    {
        $from = now()->addDays(5);
        $to = now()->addDays(10);

        $this->scheduleFor($this->client, $from->toDateString(), 100.00);
        $this->scheduleFor($this->client, $to->toDateString(), 200.00);
        $this->scheduleFor($this->client, $to->copy()->addDay()->toDateString(), 400.00);

        $this->planPage(['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.total', 300));
    }

    #[Test]
    public function таблица_партнёров_несёт_снимок_долга_и_последний_платёж(): void
    {
        $this->scheduleFor($this->client, now()->addDays(3)->toDateString(), 2000.00);
        $this->factFor($this->client, 900.00, now()->subDays(2)->toDateString());
        // Долг создаёт реализация: движение регистра со знаком «минус».
        $this->debtFor($this->client, 5000.00);
        $this->scheduleFor($this->client, now()->subDays(15)->toDateString(), 1000.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('partners', 1)
                ->where('partners.0.total', 2000)
                ->where('snapshots.'.$this->client->id.'.debt', 4100)
                ->where('snapshots.'.$this->client->id.'.overdue', 1000)
                // Доля просрочки в долге — то, что рисует градусник.
                ->where('snapshots.'.$this->client->id.'.overdue_share', 24)
                ->where('snapshots.'.$this->client->id.'.last_payment.amount', 900));
    }

    #[Test]
    public function календарь_разводит_план_и_факт_по_дням(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->factFor($this->client, 1000.00, $day->toDateString());

        $this->planPage(['view' => 'calendar'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar.days.'.$day->toDateString().'.plan', 3000)
                ->where('calendar.days.'.$day->toDateString().'.fact', 1000));
    }

    /**
     * На прошедшем дне видно не только остаток, но и что было по графику.
     *
     * Иначе экран показывает хвост вместо картины: день, где половину
     * заплатили, выглядит так же, как день, где не платили вовсе.
     */
    #[Test]
    public function прошедший_день_показывает_исполнение_графика(): void
    {
        $past = now()->startOfMonth()->subMonth()->addDays(10);
        $this->scheduleFor($this->client, $past->toDateString(), 3000.00, paid: 1200.00);
        $this->scheduleFor($this->client, $past->toDateString(), 1000.00, paid: 1000.00);

        $this->planPage(['view' => 'calendar', 'month' => $past->format('Y-m')])
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Весь график дня, включая полностью закрытую строку.
                ->where('calendar.days.'.$past->toDateString().'.scheduled', 4000)
                ->where('calendar.days.'.$past->toDateString().'.settled', 2200)
                // «Ждём» — только непогашенный остаток.
                ->where('calendar.days.'.$past->toDateString().'.plan', 1800)
                ->where('calendar.days.'.$past->toDateString().'.scheduled_lines', 2)
                ->where('calendar.days.'.$past->toDateString().'.plan_lines', 1));
    }

    #[Test]
    public function закрытые_строки_в_список_дня_не_попадают(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->scheduleFor($this->client, $day->toDateString(), 500.00, paid: 500.00);

        $this->planPage(['view' => 'calendar', 'day' => $day->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Оплаченную строку в «ждём» показывать нечего.
                ->has('dayPlan.0.documents', 1)
                ->where('dayPlan.0.amount', 3000));
    }

    #[Test]
    public function возврат_уменьшает_факт_дня(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->factFor($this->client, 1000.00, $day->toDateString());
        $this->factFor($this->client, 400.00, $day->toDateString(), SettlementEntry::TYPE_PAYMENT_OUT);

        $this->planPage(['view' => 'calendar'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar.days.'.$day->toDateString().'.fact', 600));
    }

    #[Test]
    public function детализация_дня_группирует_партнёра_и_документы(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->scheduleFor($this->client, $day->toDateString(), 1500.00);

        $this->planPage(['view' => 'calendar', 'day' => $day->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('dayPlan', 1)
                ->where('dayPlan.0.amount', 4500)
                // Один платёж 1С разносит на десяток документов: строки дня
                // обязаны собираться под партнёром, а не лежать плоско.
                ->has('dayPlan.0.documents', 2));
    }

    #[Test]
    public function оплата_ссылается_на_реализацию_когда_номер_нашёлся(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'number' => '29УТ-007601',
            'erp_number' => '29УТ-007601',
            'currency_code' => 'RUB',
        ]);

        $this->factFor($this->client, 500.00, $day->toDateString(), objectName: 'Реализация товаров и услуг 29УТ-007601 от 21.08.2026 11:03:42');

        $this->planPage(['view' => 'calendar', 'day' => $day->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dayFacts.0.documents.0.number', '29УТ-007601')
                ->where('dayFacts.0.documents.0.matched', true)
                ->where('dayFacts.0.documents.0.url', route('crm.shipments.show', $shipment->id)));
    }

    #[Test]
    public function оплата_без_карточки_остаётся_текстом_с_пояснением(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->factFor($this->client, 500.00, $day->toDateString(), objectName: 'Реализация клиенту 00000005032 от 31.12.2025 10:00:00');

        $this->planPage(['view' => 'calendar', 'day' => $day->toDateString()])
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Сказать, за что деньги, важнее, чем дать ссылку: «—» на месте
                // документа читалось бы как потеря данных.
                ->where('dayFacts.0.documents.0.number', '00000005032')
                ->where('dayFacts.0.documents.0.matched', false)
                ->where('dayFacts.0.documents.0.url', null)
                ->where('dayFacts.0.documents.0.unmatched_hint', 'Документ до 19.01.2026 — карточки на сайте нет'));
    }

    #[Test]
    public function раздел_ограничен_клиентами_менеджера(): void
    {
        $due = now()->addDays(3)->toDateString();
        $this->scheduleFor($this->client, $due, 3000.00);
        $this->scheduleFor($this->foreignClient(), $due, 9999.00);

        $this->planPage()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 3000)
                ->has('partners', 1));
    }

    #[Test]
    public function старый_адрес_календаря_ведёт_в_раздел(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertRedirect(route('crm.finance.plan', ['view' => 'calendar']));
    }

    #[Test]
    public function мусор_в_адресе_раздел_не_роняет(): void
    {
        $this->planPage(['view' => 'нечто', 'month' => 'не-месяц', 'day' => '31-02-9999'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'period')
                ->where('day', null));
    }

    /** @param  array<string, mixed>  $query */
    private function planPage(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager)->get(route('crm.finance.plan', $query));
    }

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    /** Плановая строка по реализации — юридическое обязательство. */
    private function scheduleFor(User $client, string $dueDate, float $amount, float $paid = 0.0): SettlementEntry
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'erp_number' => '29УТ-00'.random_int(1000, 9999),
            'currency_code' => 'RUB',
        ]);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'document_number' => $shipment->erp_number,
            'date' => $dueDate,
            'amount' => $amount,
            'settled_amount' => $paid,
            'currency_code' => 'RUB',
        ]);
    }

    /** Плановая строка по заказу — счёт на предоплату. */
    private function orderScheduleFor(User $client, string $dueDate, float $amount): SettlementEntry
    {
        $order = Order::factory()->create(['user_id' => $client->id]);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $client->id,
            'document_uuid' => $order->uuid,
            'document_kind' => 'order',
            'document_number' => $order->erp_number,
            'date' => $dueDate,
            'amount' => $amount,
            // 1С не публикует оплату заказов: ноль здесь — константа, а не
            // «ещё не приехало».
            'settled_amount' => 0,
            'document_settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    private function factFor(
        User $client,
        float $amount,
        string $date,
        string $type = SettlementEntry::TYPE_PAYMENT_IN,
        ?string $objectName = null,
    ): SettlementEntry {
        $signed = $type === SettlementEntry::TYPE_PAYMENT_IN ? abs($amount) : -abs($amount);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => $type,
            'user_id' => $client->id,
            'amount' => $signed,
            'amount_rub' => $signed,
            'currency_code' => 'RUB',
            'date' => $date,
            'document_kind' => 'payment',
            'settlement_object_name' => $objectName,
        ]);
    }

    /** Движение, создающее долг: реализация уменьшает сальдо клиента. */
    private function debtFor(User $client, float $amount): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $client->id,
            'amount' => -abs($amount),
            'amount_rub' => -abs($amount),
            'currency_code' => 'RUB',
            'date' => now()->subDays(20)->toDateString(),
            'document_kind' => 'shipment',
        ]);
    }
}
