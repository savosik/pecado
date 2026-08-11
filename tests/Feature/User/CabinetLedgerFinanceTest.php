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

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->client = User::factory()->create();
        $this->organization = Organization::factory()->create(['is_stub' => false]);
        Company::factory()->create(['user_id' => $this->client->id]);

        config(['cabinet.finance_enabled' => true, 'settlements.ledger_enabled' => true]);
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

    /**
     * Два флага, а не один: деньги кабинета и источник расчёта включаются
     * независимо. Регистр может быть включён для CRM, пока кабинет ещё закрыт.
     */
    #[Test]
    public function при_выключенном_флаге_регистра_дашборд_читает_старую_модель(): void
    {
        config(['settlements.ledger_enabled' => false]);

        $this->entry([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -100000,
            'amount_rub' => -100000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        // Балансов старой модели нет, значит блок не показывается вовсе —
        // и уж точно не берётся из регистра.
        $this->actingAs($this->client)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('balance', null));
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
}
