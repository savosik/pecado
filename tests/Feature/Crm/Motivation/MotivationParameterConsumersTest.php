<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationParameterOrder;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\NoveltyCalculator;
use App\Services\Motivation\ParameterCatalog;
use App\Services\Motivation\PayslipService;
use App\Services\Motivation\PoolPackageService;
use App\Services\Motivation\QuarterlyBonusService;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Приказ действует на всё, что обещает экран «Параметры мотивации».
 *
 * История: 23.09.2026 выяснилось, что срок без закупок, срок возражений, пул,
 * квартальная премия и предел корректировки читались из конфига мимо приказа —
 * руководитель менял число на экране, а система жила по умолчаниям. Здесь два
 * рубежа: статический (никто в app/ не читает умолчания напрямую) и
 * поведенческий (изменённое приказом значение меняет результат сервиса).
 */
class MotivationParameterConsumersTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $manager;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->manager = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function order(array $values, ?CarbonImmutable $from = null): MotivationParameterOrder
    {
        return MotivationParameterOrder::factory()->create([
            'effective_from' => ($from ?? $this->month)->toDateString(),
            'values' => app(ParameterCatalog::class)->complete($values),
        ]);
    }

    private function shipment(User $client, string $date, float $total): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $client->id,
            'date' => $date,
            'erp_created_at' => CarbonImmutable::parse($date),
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

    #[Test]
    #[TestDox('Умолчания Приложения № 1 читают только каталог, установщик схемы и компоненты')]
    public function nobody_reads_default_parameters_directly(): void
    {
        $allowed = [
            'app/Services/Motivation/ParameterCatalog.php',
            'app/Services/Motivation/MotivationSchemeInstaller.php',
            'app/Services/Motivation/EffectiveParameters.php',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (in_array($relative, $allowed, true) || str_starts_with($relative, 'app/Services/Payroll/Components/Motivation/')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), "config('motivation.default_parameters")) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'Эти файлы читают умолчания мимо приказа — используйте EffectiveParameters');
    }

    #[Test]
    #[TestDox('Срок без закупок и период новизны — из приказа')]
    public function novelty_uses_order_values(): void
    {
        $partner = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Вернувшийся']);
        $this->shipment($partner, '2026-03-10', 50_000);
        $this->shipment($partner, '2026-12-15', 50_000);

        app(NoveltyCalculator::class)->rebuild();
        $row = MotivationPartnerNovelty::query()->where('user_id', $partner->id)->firstOrFail();
        $this->assertSame('2026-03-01', $row->novelty_started_on?->toDateString(), 'Перерыв 9 месяцев при сроке 12 — не перерыв');

        $this->order(['no_purchase_months' => 9, 'novelty_periods' => 3]);

        app(NoveltyCalculator::class)->rebuild();
        $row = MotivationPartnerNovelty::query()->where('user_id', $partner->id)->firstOrFail();
        $this->assertSame('2026-12-01', $row->novelty_started_on?->toDateString(), 'При сроке 9 партнёр снова Новый с декабря');
        $this->assertSame('2027-02-28', $row->novelty_ends_on?->toDateString(), 'Период новизны — три периода по приказу');
    }

    #[Test]
    #[TestDox('Предельный размер пакета пула — из приказа')]
    public function pool_package_size_comes_from_the_order(): void
    {
        [$a, $b] = User::factory()->count(2)->create(['personal_manager_id' => null])->all();
        $this->order(['pool_package_size' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Предельный размер пакета — 1 партнёров');

        app(PoolPackageService::class)->issue($this->manager->id, [$a->id, $b->id], $this->head);
    }

    #[Test]
    #[TestDox('Порог квалификации и ступени квартальной премии — из приказа на квартал')]
    public function quarterly_bonus_uses_order_values(): void
    {
        $quarter = CarbonImmutable::parse('2026-04-01');
        $anchor = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->shipment($anchor, '2025-01-15', 10_000);

        $partner = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->shipment($partner, '2026-04-10', 60_000);

        app(NoveltyCalculator::class)->rebuild();
        $bonus = app(QuarterlyBonusService::class)->recalculate($quarter);
        $this->assertSame(0, (int) $bonus->step_reached, 'По умолчанию порог 100 000: партнёр не квалифицирован');

        $this->order([
            'quarterly_qualification_amount' => 50_000,
            'quarterly_steps' => [['count' => 1, 'amount' => 10_000]],
        ], $quarter);

        $bonus = app(QuarterlyBonusService::class)->recalculate($quarter);
        $this->assertSame(1, (int) $bonus->step_reached, 'По приказу порог 50 000 и ступень с одного партнёра');
        $this->assertEqualsWithDelta(10_000, (float) $bonus->amount, 0.01);
    }

    #[Test]
    #[TestDox('Срок заявления возражений — из приказа месяца расчёта')]
    public function objection_deadline_comes_from_the_order(): void
    {
        $service = app(PayrollCalculationService::class);
        $calculation = $service->approve($service->ensureDraft($this->manager->id, $this->month), $this->head);
        $payslips = app(PayslipService::class);
        $later = CarbonImmutable::instance($calculation->approved_at)->addDays(20);

        $this->assertFalse($payslips->objection($calculation, $later)['can_object'], 'Через 20 дней при сроке 5 рабочих — поздно');

        $this->order(['objection_working_days' => 30]);

        $this->assertTrue($payslips->objection($calculation, $later)['can_object'], 'При сроке 30 рабочих дней — ещё можно');
    }
}
