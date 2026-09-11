<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\MotivationInputCollector;
use App\Services\Motivation\NoveltyCalculator;
use App\Services\Motivation\PartnerAssignmentBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Кэш новизны и реестр закрепления (карточка mot-23).
 *
 * Проверяется принятое определение Нового партнёра: норма двенадцати месяцев
 * применяется к доступной истории, а там, где глубины истории не хватает,
 * система обязана это признавать, а не выдавать догадку за знание.
 */
class MotivationNoveltyTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Учётка сотрудника — не клиент: иначе она попала бы и в бэкфилл реестра,
        // и в выборку партнёров, как это было бы на живых данных ошибкой.
        $this->manager = PersonalManager::factory()->create([
            'user_id' => User::factory()->create(['user_kind' => UserKind::STAFF->value])->id,
        ]);
    }

    private function partner(string $name): User
    {
        return User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => $name]);
    }

    private function shipment(User $client, string $date, float $total = 50_000): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $client->id,
            'date' => $date,
            'erp_created_at' => Carbon::parse($date),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function novelty(User $partner): MotivationPartnerNovelty
    {
        return MotivationPartnerNovelty::query()->findOrFail($partner->id);
    }

    #[Test]
    #[TestDox('Партнёр без единой отгрузки период новизны не начинает')]
    public function partner_without_shipments_has_no_novelty_window(): void
    {
        $silent = $this->partner('Молчун');
        $buyer = $this->partner('Покупатель');
        $this->shipment($buyer, '2026-03-10');

        app(NoveltyCalculator::class)->rebuild();

        $row = $this->novelty($silent);

        $this->assertNull($row->first_shipment_on);
        $this->assertNull($row->novelty_started_on);
        $this->assertFalse($row->isNewOn(Carbon::parse('2026-06-01')));
    }

    #[Test]
    #[TestDox('Период новизны — шесть месяцев с месяца первой покупки')]
    public function novelty_window_lasts_six_periods(): void
    {
        $partner = $this->partner('Новичок');
        $this->shipment($partner, '2026-03-10');
        $this->shipment($partner, '2026-04-15');

        app(NoveltyCalculator::class)->rebuild();

        $row = $this->novelty($partner);

        $this->assertSame('2026-03-01', $row->novelty_started_on?->toDateString());
        $this->assertSame('2026-08-31', $row->novelty_ends_on?->toDateString());
        $this->assertTrue($row->isNewOn(Carbon::parse('2026-08-31')));
        $this->assertFalse($row->isNewOn(Carbon::parse('2026-09-01')));
    }

    #[Test]
    #[TestDox('Перерыв в двенадцать месяцев делает партнёра новым заново')]
    public function twelve_month_gap_restarts_novelty(): void
    {
        $partner = $this->partner('Вернувшийся');
        $this->shipment($partner, '2025-01-20');
        $this->shipment($partner, '2025-02-20');
        $this->shipment($partner, '2026-04-05');

        app(NoveltyCalculator::class)->rebuild();

        $row = $this->novelty($partner);

        $this->assertSame('2025-01-01', $row->first_shipment_on?->toDateString());
        $this->assertSame('2026-04-01', $row->novelty_started_on?->toDateString(), 'Новизна начинается с возобновления закупок');
        $this->assertSame('2025-02-01', $row->last_shipment_before_gap_on?->toDateString());
        $this->assertNotNull($row->gap_days);
        $this->assertFalse($row->history_incomplete, 'Перерыв виден в истории — догадок не требуется');
    }

    #[Test]
    #[TestDox('Перерыв короче года новизну не возобновляет')]
    public function shorter_gap_does_not_restart_novelty(): void
    {
        $partner = $this->partner('Задумчивый');
        $this->shipment($partner, '2025-06-10');
        $this->shipment($partner, '2026-03-10');   // девять месяцев паузы

        app(NoveltyCalculator::class)->rebuild();

        $row = $this->novelty($partner);

        $this->assertSame('2025-06-01', $row->novelty_started_on?->toDateString());
        $this->assertNull($row->last_shipment_before_gap_on);
        $this->assertFalse($row->isNewOn(Carbon::parse('2026-03-01')), 'Период новизны истёк ещё в 2025 году');
    }

    #[Test]
    #[TestDox('Первая покупка в первом месяце истории помечается как неподтверждаемая')]
    public function first_month_of_history_is_flagged_as_unverifiable(): void
    {
        $early = $this->partner('Из первого месяца');
        $later = $this->partner('Из третьего месяца');

        $this->shipment($early, '2026-01-12');
        $this->shipment($later, '2026-03-12');

        app(NoveltyCalculator::class)->rebuild();

        $this->assertTrue($this->novelty($early)->history_incomplete, 'До января 2026 истории нет — перерыв не проверить');
        $this->assertFalse($this->novelty($later)->history_incomplete);

        // Окно периода новизны записывается в обоих случаях — различие в том,
        // засчитывается ли оно в показатель П2.
        $this->assertNotNull($this->novelty($early)->novelty_started_on);
    }

    #[Test]
    #[TestDox('Партнёр с неподтверждаемым перерывом в П2 не попадает')]
    public function unverifiable_partner_is_not_counted_as_new(): void
    {
        $early = $this->partner('Из первого месяца');
        $later = $this->partner('Из третьего месяца');

        $this->shipment($early, '2026-01-12', 100_000);
        $this->shipment($early, '2026-03-15', 300_000);
        $this->shipment($later, '2026-03-12', 200_000);

        app(NoveltyCalculator::class)->rebuild();

        $inputs = app(MotivationInputCollector::class)->collect($this->manager->id, Carbon::parse('2026-03-01'));

        $this->assertSame(300_000.0, $inputs->baseRevenue, 'Неподтверждаемый партнёр считается закреплённой базой');
        $this->assertSame(200_000.0, $inputs->newPartnersRevenue);
        $this->assertSame(1, $inputs->newPartnersCount);
    }

    #[Test]
    #[TestDox('Со снятым требованием подтверждаемости неподтверждаемый партнёр снова Новый')]
    public function flag_can_be_switched_off_when_history_is_loaded(): void
    {
        config()->set('motivation.novelty.requires_confirmed_history', false);

        $early = $this->partner('Из первого месяца');
        $this->shipment($early, '2026-01-12', 100_000);
        $this->shipment($early, '2026-03-15', 300_000);

        app(NoveltyCalculator::class)->rebuild();

        $inputs = app(MotivationInputCollector::class)->collect($this->manager->id, Carbon::parse('2026-03-01'));

        $this->assertSame(0.0, $inputs->baseRevenue);
        $this->assertSame(300_000.0, $inputs->newPartnersRevenue, 'Правило снимается, когда историю догрузят из 1С');
    }

    #[Test]
    #[TestDox('Пересчёт не задваивает строки и обновляет прежние')]
    public function rebuild_is_idempotent(): void
    {
        $partner = $this->partner('Покупатель');
        $this->shipment($partner, '2026-03-10');

        app(NoveltyCalculator::class)->rebuild();
        $this->shipment($partner, '2026-04-10');
        app(NoveltyCalculator::class)->rebuild();

        $this->assertSame(1, MotivationPartnerNovelty::query()->where('user_id', $partner->id)->count());
        $this->assertSame('2026-03-01', $this->novelty($partner)->novelty_started_on?->toDateString());
    }

    #[Test]
    #[TestDox('После пересчёта отгрузки нового партнёра уходят в П2')]
    public function collector_uses_the_rebuilt_cache(): void
    {
        $base = $this->partner('Старый');
        $newcomer = $this->partner('Новый');

        // Первая покупка в июне 2025, закупается без годовых перерывов:
        // период новизны истёк в ноябре 2025, июньские отгрузки идут в П1.
        $this->shipment($base, '2025-06-20', 100_000);
        $this->shipment($base, '2025-12-20', 100_000);
        $this->shipment($base, '2026-06-05', 400_000);
        $this->shipment($newcomer, '2026-06-10', 150_000);

        app(NoveltyCalculator::class)->rebuild();

        $inputs = app(MotivationInputCollector::class)->collect($this->manager->id, Carbon::parse('2026-06-01'));

        $this->assertSame(400_000.0, $inputs->baseRevenue, 'Период новизны старого партнёра истёк в ноябре 2025');
        $this->assertSame(150_000.0, $inputs->newPartnersRevenue);
        $this->assertSame(1, $inputs->newPartnersCount);
    }

    #[Test]
    #[TestDox('Бэкфилл заводит по одной действующей записи на партнёра и не задваивает при повторе')]
    public function backfill_creates_one_open_row_per_partner(): void
    {
        $a = $this->partner('Первый');
        $b = $this->partner('Второй');

        $first = app(PartnerAssignmentBackfiller::class)->run(Carbon::parse('2026-01-01'));
        $second = app(PartnerAssignmentBackfiller::class)->run(Carbon::parse('2026-01-01'));

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, MotivationPartnerAssignment::query()->count());

        $row = MotivationPartnerAssignment::query()->where('user_id', $a->id)->sole();

        $this->assertSame($this->manager->id, $row->personal_manager_id);
        $this->assertSame(MotivationPartnerAssignment::REASON_INITIAL, $row->reason);
        $this->assertNull($row->ends_on);
        $this->assertSame('2026-01-01', $row->starts_on->toDateString());

        $this->assertSame(
            2,
            MotivationPartnerAssignment::query()->where('personal_manager_id', $this->manager->id)->count(),
            'Оба партнёра числятся за тем же менеджером, что и в карточке',
        );
        $this->assertNotNull($b);
    }

    #[Test]
    #[TestDox('После бэкфилла сбор входов работает по реестру и даёт ту же выручку')]
    public function collection_survives_the_backfill(): void
    {
        $partner = $this->partner('Партнёр');
        $this->shipment($partner, '2026-06-10', 300_000);

        $before = app(MotivationInputCollector::class)->collect($this->manager->id, Carbon::parse('2026-06-01'));

        app(PartnerAssignmentBackfiller::class)->run(Carbon::parse('2026-01-01'));

        $after = app(MotivationInputCollector::class)->collect($this->manager->id, Carbon::parse('2026-06-01'));

        $this->assertSame(300_000.0, $before->baseRevenue);
        $this->assertSame(
            $before->baseRevenue,
            $after->baseRevenue,
            'Бэкфилл переносит текущую картину: цифры меняться не должны',
        );
    }

    #[Test]
    #[TestDox('Партнёр в Пуле в реестре есть, но ни за кем не числится')]
    public function pool_partner_is_recorded_without_a_manager(): void
    {
        $orphan = User::factory()->create(['personal_manager_id' => null, 'name' => 'Ничей']);

        app(PartnerAssignmentBackfiller::class)->run(Carbon::parse('2026-01-01'));

        $row = MotivationPartnerAssignment::query()->where('user_id', $orphan->id)->sole();

        $this->assertNull($row->personal_manager_id);
    }
}
