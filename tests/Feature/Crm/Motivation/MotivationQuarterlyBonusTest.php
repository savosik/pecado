<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationQuarterlyBonus;
use App\Models\Motivation\MotivationQuarterlyQualification;
use App\Models\Motivation\MotivationQuarterlyShare;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\NoveltyCalculator;
use App\Services\Motivation\QuarterlyBonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Квартальная премия отдела (карточка mot-24).
 *
 * Главное, что проверяется, — поклиентный зачёт (п. 7.3) и невлияние премии
 * на месячный расчёт (пп. 7.6, 7.7).
 */
class MotivationQuarterlyBonusTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $head;

    private Carbon $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->quarter = Carbon::parse('2026-04-01');
        $this->manager = PersonalManager::factory()->create([
            'user_id' => User::factory()->create(['user_kind' => UserKind::STAFF->value])->id,
        ]);
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);

        // Якорь истории: без отгрузок 2025 года первым месяцем выгрузки окажется
        // апрель 2026, и каждый партнёр теста будет помечен как неподтверждаемый.
        $anchor = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->shipmentFor($anchor, 10_000, '2025-01-15');
    }

    private function shipmentFor(User $partner, float $amount, string $date): Shipment
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $date,
            'erp_created_at' => Carbon::parse($date),
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

        return $shipment;
    }

    /**
     * Новый партнёр с отгрузкой на указанную сумму внутри квартала.
     */
    private function newPartner(float $amount, string $firstPurchase = '2026-04-10'): User
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->shipmentFor($partner, $amount, $firstPurchase);

        return $partner;
    }

    private function calculate(): MotivationQuarterlyBonus
    {
        app(NoveltyCalculator::class)->rebuild();

        return app(QuarterlyBonusService::class)->recalculate($this->quarter);
    }

    #[Test]
    #[TestDox('Зачёт поклиентный: совокупный объём ступень не открывает')]
    public function qualification_is_per_partner_not_by_total(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->newPartner(150_000);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(40_000);
        }

        $bonus = $this->calculate();

        $this->assertSame(4, $bonus->qualified_count);
        $this->assertSame(0, $bonus->step_reached, 'Первая ступень — восемь партнёров');
        $this->assertSame('0.00', $bonus->amount);
        $this->assertSame(920_000.0, (float) $bonus->snapshot['total_amount']);
        $this->assertSame(12, MotivationQuarterlyQualification::query()->forQuarter($this->quarter)->count());
    }

    #[Test]
    #[TestDox('Восемь квалифицированных открывают первую ступень')]
    public function first_step_pays_when_reached(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(120_000);
        }

        $bonus = $this->calculate();

        $this->assertSame(8, $bonus->qualified_count);
        $this->assertSame(1, $bonus->step_reached);
        $this->assertSame('40000.00', $bonus->amount);
    }

    #[Test]
    #[TestDox('Партнёр вне периода новизны в зачёт не идёт')]
    public function only_new_partners_are_counted(): void
    {
        $this->newPartner(150_000);

        // Закупается с прошлого года без перерывов — период новизны давно истёк.
        $old = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        foreach (['2025-02-15', '2025-07-15', '2026-05-15'] as $date) {
            $this->shipmentFor($old, 900_000, $date);
        }

        $bonus = $this->calculate();

        $this->assertSame(1, $bonus->qualified_count, 'Крупный старый партнёр в целевой показатель не входит (п. 7.2)');
        $this->assertSame(1, (int) $bonus->snapshot['candidates']);
    }

    #[Test]
    #[TestDox('Партнёр, ставший новым в прошлом квартале, засчитывается, пока период новизны не истёк')]
    public function partner_new_since_previous_quarter_still_counts(): void
    {
        // Первая покупка в марте: период новизны идёт по август, отгрузки
        // второго квартала попадают в зачёт (п. 7.2 говорит о Новых партнёрах,
        // а не о привлечённых именно в этом квартале).
        $partner = $this->newPartner(50_000, '2026-03-05');

        $this->shipmentFor($partner, 130_000, '2026-05-20');

        $bonus = $this->calculate();

        $this->assertSame(1, $bonus->qualified_count);
        $row = MotivationQuarterlyQualification::query()->forQuarter($this->quarter)->sole();
        $this->assertSame('130000.00', $row->shipments_amount, 'В зачёт идут только отгрузки квартала');
    }

    #[Test]
    #[TestDox('Пересчёт не задваивает строки зачёта')]
    public function recalculation_replaces_qualification_rows(): void
    {
        $this->newPartner(150_000);

        $this->calculate();
        $this->calculate();

        $this->assertSame(1, MotivationQuarterlyQualification::query()->forQuarter($this->quarter)->count());
        $this->assertSame(1, MotivationQuarterlyBonus::query()->count());
    }

    #[Test]
    #[TestDox('Утверждённая премия не пересчитывается')]
    public function approved_bonus_is_frozen(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(120_000);
        }

        $bonus = $this->calculate();
        app(QuarterlyBonusService::class)->approve($bonus, $this->head);

        $this->newPartner(500_000);   // ещё один квалифицированный уже после утверждения
        $again = $this->calculate();

        $this->assertSame(MotivationQuarterlyBonus::STATUS_APPROVED, $again->status);
        $this->assertSame(8, $again->qualified_count, 'Утверждённый итог квартала неизменен');
    }

    #[Test]
    #[TestDox('Распределение возможно только после утверждения и только на всю сумму')]
    public function distribution_requires_approval_and_full_amount(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(120_000);
        }

        $bonus = $this->calculate();
        $second = PersonalManager::factory()->create();
        $service = app(QuarterlyBonusService::class);

        try {
            $service->distribute($bonus, [$this->manager->id => 40_000], $this->head);
            $this->fail('Черновик распределять нельзя');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('утверждённую', $e->getMessage());
        }

        $service->approve($bonus, $this->head);

        try {
            $service->distribute($bonus, [$this->manager->id => 30_000], $this->head);
            $this->fail('Недораспределённая премия не принимается');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('не равна сумме премии', $e->getMessage());
        }

        $service->distribute($bonus, [$this->manager->id => 25_000, $second->id => 15_000], $this->head, 'По вкладу');

        $this->assertSame(2, MotivationQuarterlyShare::query()->where('bonus_id', $bonus->id)->count());
        $this->assertSame(40_000.0, (float) MotivationQuarterlyShare::query()->where('bonus_id', $bonus->id)->sum('amount'));
    }

    #[Test]
    #[TestDox('Нераспределённую премию нельзя отметить выплаченной')]
    public function unpaid_distribution_blocks_payment(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(120_000);
        }

        $service = app(QuarterlyBonusService::class);
        $bonus = $this->calculate();
        $service->approve($bonus, $this->head);

        try {
            $service->markPaid($bonus, $this->head);
            $this->fail('Выплата без распределения не должна проходить');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('не распределена', $e->getMessage());
        }

        $service->distribute($bonus, [$this->manager->id => 40_000], $this->head);
        $service->markPaid($bonus->refresh(), $this->head);

        $this->assertSame(MotivationQuarterlyBonus::STATUS_PAID, $bonus->refresh()->status);
    }

    #[Test]
    #[TestDox('Перераспределение выплаченной премии запрещено')]
    public function paid_bonus_cannot_be_redistributed(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->newPartner(120_000);
        }

        $service = app(QuarterlyBonusService::class);
        $bonus = $this->calculate();
        $service->approve($bonus, $this->head);
        $service->distribute($bonus, [$this->manager->id => 40_000], $this->head);
        $service->markPaid($bonus->refresh(), $this->head);

        $this->expectException(\InvalidArgumentException::class);
        $service->distribute($bonus->refresh(), [$this->manager->id => 40_000], $this->head);
    }

    #[Test]
    #[TestDox('Возвраты уменьшают объём партнёра для зачёта')]
    public function returns_reduce_the_qualification_amount(): void
    {
        $partner = $this->newPartner(110_000);

        $return = \App\Models\ProductReturn::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $partner->id,
            'status' => \App\Enums\ReturnStatus::COMPLETED->value,
            'total_amount' => 20_000,
        ]);
        $return->forceFill(['created_at' => Carbon::parse('2026-05-01')])->saveQuietly();

        $bonus = $this->calculate();

        $this->assertSame(0, $bonus->qualified_count, '110 000 − 20 000 ниже порога 100 000');
        $row = MotivationQuarterlyQualification::query()->forQuarter($this->quarter)->sole();
        $this->assertSame('20000.00', $row->returns_amount);
    }
}
