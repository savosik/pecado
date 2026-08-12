<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\Region;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleStockUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * История доступности товара (crm-30).
 *
 * Проверяется главное: журнал пишет переходы, а не снимки, и оба порога
 * действительно отсекают шум. Без них таблица наполнилась бы дребезгом
 * остатков и повторила бы судьбу Pulse, чьи таблицы съели боевую базу.
 */
class ProductAvailabilityTrackerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stock.availability.min_quantity' => 3,
            'stock.availability.min_absence_hours' => 48,
        ]);

        // Доступность считается по складам региона по умолчанию — без региона
        // считать не от чего.
        $this->region = Region::factory()->create();

        $this->product = Product::factory()->create(['external_id' => 'p-availability-1']);
        $this->warehouse = Warehouse::factory()->create([
            'external_id' => 'w-availability-1',
            'is_defect' => false,
            'is_promo_sample' => false,
        ]);

        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
        ]);
    }

    private function stock(int $quantity, ?Warehouse $warehouse = null): void
    {
        app(HandleStockUpdated::class)->handle([
            'event' => 'stock.updated',
            'product_uuid' => $this->product->external_id,
            'warehouse_uuid' => ($warehouse ?? $this->warehouse)->external_id,
            'quantity' => $quantity,
        ]);
    }

    #[Test]
    public function появление_товара_записывается_переходом(): void
    {
        $this->stock(10);

        $this->assertDatabaseHas('product_availability_events', [
            'product_id' => $this->product->id,
            'event' => ProductAvailabilityEvent::IN_STOCK,
            'quantity' => 10,
        ]);
    }

    #[Test]
    public function повторная_выгрузка_того_же_остатка_событие_не_плодит(): void
    {
        $this->stock(10);
        $this->stock(12);
        $this->stock(9);

        // Доступность всё это время не менялась — переход ровно один.
        $this->assertSame(1, ProductAvailabilityEvent::query()->count());
    }

    #[Test]
    public function исчезновение_и_возврат_дают_два_перехода(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
        $this->stock(10);

        $this->travelTo(Carbon::parse('2026-08-02 10:00:00'));
        $this->stock(0);

        $this->travelTo(Carbon::parse('2026-08-06 10:00:00'));
        $this->stock(20);

        $events = ProductAvailabilityEvent::query()->orderBy('happened_at')->get();

        $this->assertCount(3, $events);
        $this->assertSame(ProductAvailabilityEvent::IN_STOCK, $events[0]->event);
        $this->assertSame(ProductAvailabilityEvent::OUT_OF_STOCK, $events[1]->event);
        $this->assertSame(ProductAvailabilityEvent::IN_STOCK, $events[2]->event);
        $this->assertSame(4, $events[2]->missing_days);
    }

    /**
     * Одна штука — не возврат в продажу: звать за ней клиентов не за чем.
     */
    #[Test]
    public function порог_количества_отсекает_появление_одной_штуки(): void
    {
        $this->stock(1);

        $this->assertSame(0, ProductAvailabilityEvent::query()->count());
    }

    /**
     * Отсутствие на пару часов между выгрузками — пересортица, а не дефицит.
     * Парная запись об исчезновении удаляется, иначе в истории остался бы
     * «вечный дефицит», который никогда не закрылся.
     */
    #[Test]
    public function короткое_отсутствие_не_считается_дефицитом(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00'));
        $this->stock(10);

        $this->travelTo(Carbon::parse('2026-08-01 11:00:00'));
        $this->stock(0);

        $this->travelTo(Carbon::parse('2026-08-01 13:00:00'));
        $this->stock(10);

        $events = ProductAvailabilityEvent::query()->get();

        // Остался только первый переход: мелькание нуля стёрлось целиком.
        $this->assertCount(1, $events);
        $this->assertSame(ProductAvailabilityEvent::IN_STOCK, $events[0]->event);
    }

    #[Test]
    public function склад_брака_доступность_не_создаёт(): void
    {
        $defect = Warehouse::factory()->create([
            'external_id' => 'w-defect-1',
            'is_defect' => true,
        ]);

        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $defect->id,
            'type' => 'primary',
        ]);

        $this->stock(100, $defect);

        $this->assertSame(0, ProductAvailabilityEvent::query()->count());
    }

    #[Test]
    public function ретенция_чистит_старые_записи(): void
    {
        config(['stock.availability.retention_days' => 30]);

        ProductAvailabilityEvent::create([
            'product_id' => $this->product->id,
            'event' => ProductAvailabilityEvent::IN_STOCK,
            'quantity' => 5,
            'happened_at' => Carbon::now()->subDays(90),
        ]);
        ProductAvailabilityEvent::create([
            'product_id' => $this->product->id,
            'event' => ProductAvailabilityEvent::OUT_OF_STOCK,
            'quantity' => 0,
            'happened_at' => Carbon::now()->subDays(5),
        ]);

        $this->artisan('stock:cleanup-availability')->assertSuccessful();

        $this->assertSame(1, ProductAvailabilityEvent::query()->count());
    }
}
