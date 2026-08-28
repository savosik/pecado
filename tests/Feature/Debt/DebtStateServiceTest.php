<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Events\DebtPauseExpired;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Debt\DebtStateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Лестница долга: отсечка, льготный период, возрастные пороги, стоп-отгрузка,
 * гейт свежести, разблокировка, тень и бой.
 */
class DebtStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        // Четверг: льготный период в 5 банковских дней = предыдущий четверг.
        $this->today = CarbonImmutable::parse('2026-08-27');
        CarbonImmutable::setTestNow($this->today);

        config([
            'debt.enabled' => true,
            'debt.mode' => 'live',
            'debt.live_actions' => 'mail,gate,tasks,cabinet',
            'debt.min_overdue' => 5000,
            'debt.grace_bank_days' => 5,
            'debt.no_preorders_days' => 14,
            'debt.no_orders_days' => 30,
            'debt.hold_days' => 60,
            'debt.hold_share' => 0.9,
            'debt.stale_after_days' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function tiny_overdue_stays_clean(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 4999, 40);

        $report = $this->service()->recalculate($this->today);

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel($user));
        $this->assertSame([], $report['transitions']);
        $this->assertStringContainsString('ниже отсечки', $this->partnerRow($user)->reason);
    }

    #[Test]
    public function grace_period_counts_bank_days(): void
    {
        [$user, $company] = $this->partner();
        // Срок в прошлый четверг: ровно 5 банковских дней — ещё не просрочка.
        $this->overdueLine($user, $company, 50000, 7);

        $this->service()->recalculate($this->today);
        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel($user));

        // Днём раньше — уже просрочка.
        $this->overdueLine($user, $company, 50000, 8);
        $this->service()->recalculate($this->today);
        $this->assertSame(DebtLevel::OVERDUE, $this->partnerLevel($user));
    }

    #[Test]
    public function level_is_set_by_age_at_once(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 45);
        // Свежая отгрузка — долг больше просрочки, стоп-отгрузка не положена.
        $this->fact($user, $company, -200000, 3);

        $service = $this->service();

        // Без постепенного спуска: 45 дней — сразу «заказы закрыты».
        $service->recalculate($this->today);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));

        // Повторный пересчёт ничего не меняет и переходов не порождает.
        $report = $service->recalculate($this->today->addDay());
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));
        $this->assertSame([], $report['transitions']);
    }

    #[Test]
    public function thresholds_by_age(): void
    {
        [$user, $first] = $this->partner();
        $second = Company::factory()->create(['user_id' => $user->id]);
        $third = Company::factory()->create(['user_id' => $user->id]);
        $this->overdueLine($user, $first, 50000, 10);
        $this->overdueLine($user, $second, 50000, 20);
        $this->overdueLine($user, $third, 50000, 35);
        $this->fact($user, $first, -500000, 1);

        $this->service()->recalculate($this->today);

        $this->assertSame(DebtLevel::OVERDUE, $this->contractorRow($user, $first)->level);
        $this->assertSame(DebtLevel::NO_PREORDERS, $this->contractorRow($user, $second)->level);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->contractorRow($user, $third)->level);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));
    }

    #[Test]
    public function hold_requires_old_overdue_that_is_almost_whole_debt(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 70);

        $this->service()->recalculate($this->today);

        $this->assertSame(DebtLevel::HOLD, $this->partnerLevel($user));
        $this->assertSame(DebtLevel::HOLD, $this->contractorRow($user, $company)->level);
        $this->assertStringContainsString('% долга', $this->partnerRow($user)->reason);
    }

    #[Test]
    public function stale_balance_forbids_escalation_but_not_relief(): void
    {
        [$user, $company] = $this->partner(balanceAgeDays: 10);
        $this->overdueLine($user, $company, 50000, 40);

        $service = $this->service();
        $service->recalculate($this->today);

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel($user));
        $this->assertTrue($this->partnerRow($user)->is_stale);
        $this->assertStringContainsString('устарел', $this->contractorRow($user, $company)->reason);
    }

    #[Test]
    public function active_pause_keeps_level_visible_with_a_note(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 40);
        $service = $this->service();
        $service->recalculate($this->today);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));

        // Добавилась просрочка на 70 дней — под разблокировкой ступень не растёт.
        $this->overdueLine($user, $company, 50000, 70);
        DebtPause::create([
            'user_id' => $user->id,
            'company_id' => null,
            'until' => $this->today->addDays(10)->toDateString(),
            'reason' => 'Обещал оплатить до 6 сентября',
            'created_by' => User::factory()->create()->id,
        ]);

        $service->recalculate($this->today->addDay());
        // Ступень считается и видна — под разблокировкой молчат письма, гейт и задачи.
        $this->assertSame(DebtLevel::HOLD, $this->partnerLevel($user));
        $this->assertStringContainsString('разблокировка', $this->contractorRow($user, $company)->reason);
    }

    #[Test]
    public function payment_clears_level_immediately_through_refresh(): void
    {
        Event::fake([DebtLevelChanged::class]);

        [$user, $company] = $this->partner();
        $line = $this->overdueLine($user, $company, 50000, 40);
        $service = $this->service();
        $service->recalculate($this->today);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));

        // Оплата погасила строку — событийный пересчёт снимает ступень сразу.
        $line->update(['settled_amount' => 50000]);
        $service->refresh([$user->id], $this->today->addDay());

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel($user));
        Event::assertDispatched(DebtLevelChanged::class, fn (DebtLevelChanged $event): bool => $event->to === DebtLevel::CLEAN
            && $event->from === DebtLevel::NO_ORDERS
            && $event->isRelief());
    }

    #[Test]
    public function refresh_never_escalates(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 40);

        $this->service()->refresh([$user->id], $this->today);

        $this->assertSame(DebtLevel::CLEAN, $this->partnerLevel($user));
    }

    #[Test]
    public function shadow_mode_writes_dry_run_rows_and_fires_nothing(): void
    {
        Event::fake([DebtLevelChanged::class]);
        config(['debt.mode' => 'shadow']);

        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 40);

        $report = $this->service()->recalculate($this->today);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(1, $report['levels']['no_orders']);
        $this->assertTrue($this->partnerRow($user)->dry_run);
        Event::assertNotDispatched(DebtLevelChanged::class);
    }

    #[Test]
    public function first_live_run_after_shadow_starts_from_clean(): void
    {
        Event::fake([DebtLevelChanged::class]);
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 45);

        config(['debt.mode' => 'shadow']);
        $service = $this->service();
        $service->recalculate($this->today);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user, live: false));

        // Включили бой: тень не считается предупреждением — переход из clean,
        // письмо о достигнутой ступени уходит один раз.
        config(['debt.mode' => 'live']);
        $service->recalculate($this->today->addDay());

        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));
        $this->assertFalse($this->partnerRow($user)->dry_run);
        Event::assertDispatched(DebtLevelChanged::class, fn (DebtLevelChanged $event): bool => $event->from === DebtLevel::CLEAN && $event->to === DebtLevel::NO_ORDERS);
    }

    #[Test]
    public function partner_level_is_the_worst_of_its_contractors(): void
    {
        [$user, $first] = $this->partner();
        $second = Company::factory()->create(['user_id' => $user->id]);
        $this->overdueLine($user, $first, 50000, 40);
        $this->overdueLine($user, $second, 50000, 8);
        $this->fact($user, $first, -300000, 2);

        $this->service()->recalculate($this->today);

        $this->assertSame(DebtLevel::NO_ORDERS, $this->contractorRow($user, $first)->level);
        $this->assertSame(DebtLevel::OVERDUE, $this->contractorRow($user, $second)->level);
        $this->assertSame(DebtLevel::NO_ORDERS, $this->partnerLevel($user));
    }

    #[Test]
    public function expired_pause_is_released_and_reported(): void
    {
        Event::fake([DebtPauseExpired::class]);
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 40);

        $pause = DebtPause::create([
            'user_id' => $user->id,
            'until' => $this->today->subDay()->toDateString(),
            'reason' => 'Обещал',
            'created_by' => User::factory()->create()->id,
        ]);

        $report = $this->service()->recalculate($this->today);

        $this->assertSame(1, $report['expired_pauses']);
        $this->assertSame(DebtPause::RELEASED_EXPIRED, $pause->fresh()->released_reason);
        Event::assertDispatched(DebtPauseExpired::class);
    }

    #[Test]
    public function explain_lists_contractors_and_thresholds(): void
    {
        [$user, $company] = $this->partner();
        $this->overdueLine($user, $company, 50000, 40);
        $this->service()->recalculate($this->today);

        $explain = $this->service()->explain($user, $this->today);

        $this->assertSame('no_orders', $explain['partner']['level']);
        $this->assertCount(1, $explain['contractors']);
        $this->assertSame(5000.0, $explain['thresholds']['min_overdue']);
    }

    // --- фикстуры -----------------------------------------------------------

    /**
     * @return array{0: User, 1: Company}
     */
    private function partner(int $balanceAgeDays = 0): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);

        ContractorBalance::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'current_balance' => 0,
            'overdue_debt' => 0,
            'balance_erp_updated_at' => $this->today->subDays($balanceAgeDays)->toDateTimeString(),
        ]);

        return [$user, $company];
    }

    private function overdueLine(User $user, Company $company, float $amount, int $daysAgo): SettlementEntry
    {
        $this->fact($user, $company, -$amount, $daysAgo + 14);

        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'document_kind' => 'shipment',
            'date' => $this->today->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'amount_rub' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);
    }

    private function fact(User $user, Company $company, float $amount, int $daysAgo): void
    {
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'document_kind' => 'shipment',
            'date' => $this->today->subDays($daysAgo)->toDateString(),
            'amount' => $amount,
            'amount_rub' => $amount,
            'currency_code' => 'RUB',
        ]);
    }

    private function service(): DebtStateService
    {
        return app(DebtStateService::class);
    }

    private function partnerLevel(User $user, bool $live = true): DebtLevel
    {
        $row = DebtState::query()->partners()->where('user_id', $user->id)->when($live, fn ($q) => $q->live())->first();

        return $row?->level ?? DebtLevel::CLEAN;
    }

    private function partnerRow(User $user): DebtState
    {
        return DebtState::query()->partners()->where('user_id', $user->id)->firstOrFail();
    }

    private function partnerRowOrNull(User $user): ?DebtState
    {
        return DebtState::query()->partners()->where('user_id', $user->id)->first();
    }

    private function contractorRow(User $user, Company $company): DebtState
    {
        return DebtState::query()->where('user_id', $user->id)->where('company_id', $company->id)->firstOrFail();
    }
}
