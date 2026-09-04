<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Балл сортировки витрины: `catalog:rebuild-sort-scores`.
 *
 * Балл тем выше, чем больше выручка и чем шире охват контрагентов за окно.
 * Наличие в балл не входит — полку ставит ORDER BY по живым остаткам
 * (см. CatalogSortShelvesTest).
 */
class RebuildCatalogSortScoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_grows_with_revenue_and_client_reach(): void
    {
        $best = Product::factory()->create();      // много денег и много клиентов
        $rich = Product::factory()->create();      // столько же денег, но один клиент
        $silent = Product::factory()->create();    // продаж за окно нет

        foreach (range(1, 5) as $i) {
            $this->sale($best, amount: 20_000, daysAgo: 10);
        }
        foreach (range(1, 5) as $i) {
            $this->sale($rich, amount: 20_000, daysAgo: 10, company: $this->sharedCompany());
        }

        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();

        $scores = $this->scores();

        $this->assertGreaterThan($scores[$rich->id], $scores[$best->id], 'Широкий охват должен поднимать товар');
        $this->assertGreaterThan(0, $scores[$rich->id]);
        $this->assertSame(0.0, $scores[$silent->id], 'Без продаж за окно балл нулевой');
    }

    public function test_sales_outside_window_are_ignored(): void
    {
        $stale = Product::factory()->create();
        $this->sale($stale, amount: 100_000, daysAgo: config('catalog_ranking.window_days') + 5);

        $fresh = Product::factory()->create();
        $this->sale($fresh, amount: 1_000, daysAgo: 1);

        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();

        $scores = $this->scores();

        $this->assertSame(0.0, $scores[$stale->id], 'Продажа старше окна в балл не идёт');
        $this->assertGreaterThan(0, $scores[$fresh->id]);
    }

    public function test_previous_score_is_dropped_when_product_leaves_the_window(): void
    {
        $faded = Product::factory()->create();
        $this->sale($faded, amount: 5_000, daysAgo: 1);

        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();
        $this->assertGreaterThan(0, $this->scores()[$faded->id]);

        // Прошло полгода: продажа выпала из окна, а торгует уже другой товар.
        // Вчерашний балл не имеет права остаться на витрине.
        Carbon::setTestNow(now()->addDays(config('catalog_ranking.window_days') + 30));
        $current = Product::factory()->create();
        $this->sale($current, amount: 5_000, daysAgo: 1);

        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();
        Carbon::setTestNow();

        $scores = $this->scores();
        $this->assertSame(0.0, $scores[$faded->id]);
        $this->assertGreaterThan(0, $scores[$current->id]);
    }

    public function test_empty_window_keeps_previous_scores(): void
    {
        $product = Product::factory()->create();
        $this->sale($product, amount: 5_000, daysAgo: 1);
        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();
        $before = $this->scores()[$product->id];

        // Ни одной реализации за окно — это отказ обмена с 1С, а не «всё
        // распродано»: обнулять витрину на такой вводной нельзя.
        DB::table('shipment_items')->delete();
        DB::table('shipments')->delete();

        $this->artisan('catalog:rebuild-sort-scores')->assertSuccessful();

        $this->assertSame($before, $this->scores()[$product->id]);
    }

    public function test_dry_run_does_not_write(): void
    {
        $product = Product::factory()->create();
        $this->sale($product, amount: 5_000, daysAgo: 1);

        $this->artisan('catalog:rebuild-sort-scores --dry-run')->assertSuccessful();

        $this->assertSame(0.0, $this->scores()[$product->id]);
        $this->assertNull(Product::find($product->id)->sort_score_updated_at);
    }

    /**
     * Балл каждого товара как float.
     *
     * @return array<int, float>
     */
    private function scores(): array
    {
        return DB::table('products')
            ->pluck('sort_score', 'id')
            ->map(fn ($score) => (float) $score)
            ->all();
    }

    /** Один и тот же контрагент для всех продаж товара — охват остаётся единичным. */
    private ?Company $sharedCompany = null;

    private function sharedCompany(): Company
    {
        return $this->sharedCompany ??= Company::factory()->create();
    }

    /**
     * Реализация на один товар. Без явного контрагента каждая продажа —
     * новый клиент: так растёт охват.
     */
    private function sale(Product $product, float $amount, int $daysAgo, ?Company $company = null): void
    {
        $shipment = Shipment::factory()->create([
            'currency_code' => 'RUB',
            'company_id' => ($company ?? Company::factory()->create())->id,
            'erp_created_at' => now()->subDays($daysAgo),
        ]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $amount,
            'subtotal' => $amount,
            'total' => $amount,
        ]);
    }
}
