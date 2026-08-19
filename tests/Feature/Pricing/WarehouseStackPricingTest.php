<?php

namespace Tests\Feature\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing\IndividualPriceProxy;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Цены в режиме стопки складов: действует индивидуальная цена склада-победителя
 * по остаткам; без стопки — детерминированное правило «минимальный warehouse_id»,
 * одинаковое для точечного и батч-пути (раньше они могли разойтись).
 */
class WarehouseStackPricingTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): PriceService
    {
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        return new PriceService(
            Mockery::mock(CurrencyConversionServiceInterface::class),
            $currencyResolver,
        );
    }

    /**
     * @return array{0: User, 1: Warehouse, 2: Warehouse}
     */
    private function makeStackRegion(): array
    {
        $region = Region::factory()->create(['stock_stack_enabled' => true]);
        $top = Warehouse::factory()->create();
        $bottom = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $region->id, 'warehouse_id' => $top->id, 'type' => 'primary', 'priority' => 1],
            ['region_id' => $region->id, 'warehouse_id' => $bottom->id, 'type' => 'primary', 'priority' => 2],
        ]);

        $user = User::factory()->create(['erp_id' => 'stack-price-partner', 'region_id' => $region->id]);

        return [$user, $top, $bottom];
    }

    #[Test]
    public function действует_цена_склада_победителя_по_остаткам(): void
    {
        [$user, $top, $bottom] = $this->makeStackRegion();

        $product = Product::factory()->create(['base_price' => 1000]);

        // Товар в наличии только на нижнем складе — он и победитель.
        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $top->id, 'quantity' => 0],
            ['product_id' => $product->id, 'warehouse_id' => $bottom->id, 'quantity' => 10],
        ]);

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $top->id, 'price' => 700]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $bottom->id, 'price' => 900]);

        $service = $this->makeService();

        $this->assertSame(900.0, $service->getPriceResult($product, $user)->getDisplayPrice());
        $this->assertSame(900.0, $service->getPriceMapForProducts(collect([$product]), $user)[$product->id]->getDisplayPrice());
    }

    #[Test]
    public function без_цены_на_победителе_действует_базовая_цена(): void
    {
        [$user, $top, $bottom] = $this->makeStackRegion();

        $product = Product::factory()->create(['base_price' => 1000]);

        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $top->id, 'quantity' => 5],
        ]);

        // Индивидуальная цена есть только у нижнего склада, но победитель — верхний.
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $bottom->id, 'price' => 700]);

        $service = $this->makeService();

        $this->assertSame(1000.0, $service->getPriceResult($product, $user)->getDisplayPrice());
        $this->assertFalse($service->getPriceMapForProducts(collect([$product]), $user)[$product->id]->hasDiscount);
    }

    #[Test]
    public function без_стопки_точечный_и_батч_путь_дают_одну_и_ту_же_цену(): void
    {
        // Регион без стопки: несколько складских цен на товар — раньше
        // findPrice() и loadPriceMap() могли вернуть разные строки.
        $user = User::factory()->create(['erp_id' => 'no-stack-partner', 'region_id' => null]);
        $product = Product::factory()->create(['base_price' => 1000]);

        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();
        $minWarehouseId = min($w1->id, $w2->id);

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w1->id, 'price' => 800]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w2->id, 'price' => 600]);

        $expected = (float) IndividualPrice::where('warehouse_id', $minWarehouseId)->value('price');

        $service = $this->makeService();
        $pointPrice = $service->getPriceResult($product, $user)->getDisplayPrice();
        $batchPrice = $service->getPriceMapForProducts(collect([$product]), $user)[$product->id]->getDisplayPrice();

        $this->assertSame($expected, $pointPrice);
        $this->assertSame($pointPrice, $batchPrice);
    }

    #[Test]
    public function явная_карта_складов_имеет_приоритет_над_авторезолвом(): void
    {
        [$user, $top, $bottom] = $this->makeStackRegion();

        $product = Product::factory()->create(['base_price' => 1000]);

        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $top->id, 'quantity' => 5],
        ]);

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $top->id, 'price' => 700]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $bottom->id, 'price' => 900]);

        // Зафиксированный в заказе склад (нижний) важнее текущего победителя.
        $map = $this->makeService()->getPriceMapForProducts(
            collect([$product]),
            $user,
            [$product->id => $bottom->id],
        );

        $this->assertSame(900.0, $map[$product->id]->getDisplayPrice());
    }

    #[Test]
    public function прокси_отдаёт_детерминированную_строку_без_склада(): void
    {
        $user = User::factory()->create(['erp_id' => 'proxy-det-partner']);
        $product = Product::factory()->create(['base_price' => 1000]);

        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w2->id, 'price' => 600]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w1->id, 'price' => 800]);

        $minWarehouseId = min($w1->id, $w2->id);
        $expected = (float) IndividualPrice::where('warehouse_id', $minWarehouseId)->value('price');

        $found = IndividualPriceProxy::findPrice($user->id, $product->id);
        $mapped = IndividualPriceProxy::loadPriceMap($user->id, [$product->id]);

        $this->assertSame($expected, (float) $found->price);
        $this->assertSame($expected, (float) $mapped[$product->id]);
    }
}
