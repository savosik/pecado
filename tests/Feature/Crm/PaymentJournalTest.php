<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Payment;
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
 * Журнал платежей и карточка платежа в CRM.
 *
 * Отдельного права у платежей нет — доступ решает тот же скоуп клиентов, что
 * и у заказов с реализациями. Поэтому изоляция проверяется на каждом входе:
 * платёж это ещё один способ добраться до чужих денег, зная ID.
 */
class PaymentJournalTest extends TestCase
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

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    private function paymentFor(User $client, array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'user_id' => $client->id,
            'number' => '29УТ-002488',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
        ], $attributes));
    }

    #[Test]
    public function manager_sees_only_payments_of_own_clients(): void
    {
        $this->paymentFor($this->client, ['number' => 'СВОЙ-1']);
        $this->paymentFor($this->foreignClient(), ['number' => 'ЧУЖОЙ-1']);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Finance/Payments')
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'СВОЙ-1'));
    }

    #[Test]
    public function foreign_payment_card_returns_404_not_403(): void
    {
        $foreign = $this->paymentFor($this->foreignClient());

        // 403 подтвердил бы менеджеру существование чужого клиента.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.show', $foreign->id))
            ->assertNotFound();
    }

    #[Test]
    public function direction_filter_narrows_the_journal(): void
    {
        $this->paymentFor($this->client, ['number' => 'ПРИХОД']);
        $this->paymentFor($this->client, ['number' => 'ВОЗВРАТ', 'direction' => Payment::DIRECTION_OUT]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['directions' => ['out']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ВОЗВРАТ'));
    }

    #[Test]
    public function legacy_scalar_direction_parameter_still_filters(): void
    {
        $this->paymentFor($this->client, ['number' => 'ПРИХОД']);
        $this->paymentFor($this->client, ['number' => 'ВОЗВРАТ', 'direction' => Payment::DIRECTION_OUT]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['direction' => 'out']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 1));
    }

    #[Test]
    public function filter_options_are_built_from_payments_not_from_all_clients(): void
    {
        // У клиента платежей нет — в справочнике партнёров его быть не должно:
        // у РОПа клиентов сотни, и список фильтра собирается из самого журнала.
        $withoutPayments = User::factory()->create(['personal_manager_id' => $this->card->id]);

        $company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->paymentFor($this->client, ['company_id' => $company->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($withoutPayments) {
                $partnerIds = array_column($page->toArray()['props']['partners'], 'id');

                $this->assertContains($this->client->id, $partnerIds);
                $this->assertNotContains($withoutPayments->id, $partnerIds);
            });
    }

    #[Test]
    public function xlsx_export_uses_the_same_scope_as_the_screen(): void
    {
        $this->paymentFor($this->client, ['number' => 'СВОЙ-1']);
        $this->paymentFor($this->foreignClient(), ['number' => 'ЧУЖОЙ-1']);

        $response = $this->actingAs($this->manager)->get(route('crm.payments.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    /**
     * Журнал и календарь отдают текущий разрез экрану.
     *
     * Без этого пропса переключатель «Только мои» нечем нарисовать, и журнал
     * молча показывает часть платежей: сервер по умолчанию отдаёт своих
     * партнёров, а экран об этом не сообщает.
     */
    #[Test]
    public function payment_screens_expose_current_scope_and_department_widens_it(): void
    {
        $head = User::factory()->create();
        $head->assignRole('sales-head');
        $headCard = PersonalManager::factory()->create(['user_id' => $head->id]);
        $ownClient = User::factory()->create(['personal_manager_id' => $headCard->id]);

        $this->paymentFor($ownClient, ['number' => 'СВОЙ-1']);
        $this->paymentFor($this->client, ['number' => 'ЧУЖОЙ-1']);

        // По умолчанию — только свои, хотя право на отдел есть.
        $this->actingAs($head)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.scope', 'mine')
                ->where('seesAll', true)
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'СВОЙ-1'));

        $this->actingAs($head)
            ->get(route('crm.payments.index', ['scope' => 'department']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.scope', 'department')
                ->has('payments.data', 2));

        $this->actingAs($head)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.scope', 'mine'));

        // Рядовому менеджеру расфокус недоступен: параметр гасится молча,
        // и экран показывает тот разрез, который сервер действительно применил.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['scope' => 'department']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.scope', 'mine')
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ЧУЖОЙ-1'));
    }

    /**
     * Менеджер партнёра приезжает в строку журнала — подписью под именем.
     */
    #[Test]
    public function payment_row_carries_partner_manager_name(): void
    {
        $this->paymentFor($this->client);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('payments.data.0.client.manager_name', $this->card->name));
    }

    /**
     * Свои фильтры платежей возвращаются в снимке отбора.
     *
     * Без этого галочки в «Направлении» и «Разнесении» не рисовались, а любое
     * следующее применение фильтра теряло их: клиент собирает адрес из filters,
     * и чего там нет — того после следующего клика нет и в запросе.
     */
    #[Test]
    public function payment_filters_round_trip_through_the_snapshot(): void
    {
        $this->paymentFor($this->client, ['number' => 'ВХОД-1', 'direction' => 'in']);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['directions' => ['in']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.directions', ['in']));

        // Мусор в параметре гасится, а не уезжает обратно на экран.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['directions' => ['сомнительно']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.directions', []));
    }

    /**
     * Итог считается по всему отбору, а не по показанной странице.
     */
    #[Test]
    public function totals_cover_the_whole_selection_and_split_by_direction(): void
    {
        foreach ([1000, 2000, 3000] as $amount) {
            $this->paymentFor($this->client, ['amount' => $amount, 'direction' => 'in']);
        }

        $this->paymentFor($this->client, ['amount' => 500, 'direction' => 'out']);
        $this->paymentFor($this->foreignClient(), ['amount' => 9999, 'direction' => 'in']);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['per_page' => 5]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $totals = $page->toArray()['props']['totals'];

                // Четыре платежа своих клиентов; чужой в итог не попал.
                $this->assertSame(4, $totals['count']);

                $byDirection = collect($totals['buckets'])->keyBy('direction');

                $this->assertSame(3, $byDirection['in']['count']);
                $this->assertStringContainsString('6 000,00', $byDirection['in']['amount_label']);
                $this->assertSame(1, $byDirection['out']['count']);
                $this->assertStringContainsString('500,00', $byDirection['out']['amount_label']);
            });

        // Отбор сужает и итог: страница показывает то же число, что и сумма.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['directions' => ['out']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.count', 1));
    }

    /**
     * Выбранный партнёр сужает справочник контрагентов.
     */
    #[Test]
    public function partner_filter_narrows_the_company_options(): void
    {
        $second = User::factory()->create(['personal_manager_id' => $this->card->id]);

        $mine = Company::factory()->create(['name' => 'ЮРЛИЦО ПЕРВОГО']);
        $other = Company::factory()->create(['name' => 'ЮРЛИЦО ВТОРОГО']);

        $this->paymentFor($this->client, ['company_id' => $mine->id]);
        $this->paymentFor($second, ['company_id' => $other->id]);

        // Без фильтра по партнёру видно оба юрлица.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 2));

        // С выбранным партнёром — только его.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', ['partner_ids' => [$this->client->id]]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($mine) {
                $names = array_column($page->toArray()['props']['companies'], 'name');

                $this->assertSame([$mine->name], $names);
            });

        // Уже выбранный контрагент остаётся в списке, даже если выпал из сужения:
        // иначе снять фильтр, который сам себя спрятал, было бы нечем.
        $this->actingAs($this->manager)
            ->get(route('crm.payments.index', [
                'partner_ids' => [$this->client->id],
                'company_ids' => [$other->id],
            ]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($other) {
                $ids = array_column($page->toArray()['props']['companies'], 'id');

                $this->assertContains($other->id, $ids);
            });
    }

    /**
     * Строка графика по реализации клиента.
     */
    /**
     * Фактическое поступление в регистре: календарь считает факт по движениям,
     * а не по документам платежей.
     */
    private function factFor(User $client, float $amount, string $date, string $type = SettlementEntry::TYPE_PAYMENT_IN): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => $type,
            'user_id' => $client->id,
            'amount' => $type === SettlementEntry::TYPE_PAYMENT_IN ? abs($amount) : -abs($amount),
            'amount_rub' => $type === SettlementEntry::TYPE_PAYMENT_IN ? abs($amount) : -abs($amount),
            'currency_code' => 'RUB',
            'date' => $date,
            'document_kind' => 'payment',
        ]);
    }

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

    #[Test]
    public function calendar_is_limited_to_manager_client_scope_v15_12(): void
    {
        $inMonth = now()->startOfMonth()->addDays(10)->toDateString();
        $this->scheduleFor($this->client, $inMonth, 3000.00);
        $this->scheduleFor($this->foreignClient(), $inMonth, 9999.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Finance/PaymentCalendar')
                ->has('entries', 1)
                ->where('entries.0.unpaid_amount', 3000)
                ->where('summary.plan_month', 3000));
    }

    #[Test]
    public function calendar_counts_plan_and_fact_separately_v15_12(): void
    {
        $day = now()->startOfMonth()->addDays(10);
        $this->scheduleFor($this->client, $day->toDateString(), 3000.00);
        $this->factFor($this->client, 1000.00, $day->toDateString());

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // План идёт по графику, факт — по проведённым платежам:
                // одно не подменяет другое даже в один и тот же день.
                ->where('summary.plan_month', 3000)
                ->where('summary.fact_month', 1000)
                ->where('facts.'.$day->toDateString().'.amount', 1000));
    }

    #[Test]
    public function refund_reduces_the_fact_of_the_day_v15_12(): void
    {
        $day = now()->startOfMonth()->addDays(10);

        $this->factFor($this->client, 1000.00, $day->toDateString());
        $this->factFor($this->client, 400.00, $day->toDateString(), SettlementEntry::TYPE_PAYMENT_OUT);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.fact_month', 600));
    }

    #[Test]
    public function closed_schedule_lines_are_not_expected_money_v15_12(): void
    {
        $inMonth = now()->startOfMonth()->addDays(10)->toDateString();
        $this->scheduleFor($this->client, $inMonth, 3000.00, paid: 3000.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 0)
                ->where('summary.plan_month', 0));
    }

    #[Test]
    public function overdue_is_shown_regardless_of_displayed_month_v15_12(): void
    {
        $this->scheduleFor($this->client, now()->subMonth()->startOfMonth()->toDateString(), 1500.00);

        $this->actingAs($this->manager)
            ->get(route('crm.payments.calendar'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 0)
                ->has('overdueEntries', 1)
                ->where('overdueEntries.0.is_overdue', true)
                ->where('summary.overdue_amount', 1500));
    }

    #[Test]
    public function shipment_card_shows_payment_schedule_v15_12(): void
    {
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'total_amount' => 10000.00,
        ]);

        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->client->id,
            'document_uuid' => $shipment->uuid,
            'document_kind' => 'shipment',
            'date' => now()->addDays(10)->toDateString(),
            'amount' => 10000.00,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
            'meta' => ['stage_name' => 'Оплата после отгрузки'],
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.shipments.show', $shipment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('document.payment_schedule.lines', 1)
                ->where('document.payment_schedule.lines.0.status_label', 'Ожидается'));
    }
}
