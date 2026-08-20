<?php

namespace Tests\Feature\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing\IndividualPriceProxy;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Индивидуальные цены приходят из 1С в разрезе складов, поэтому на один товар
 * их может быть несколько. Точечный путь (карточка товара) и батч-путь (каталог,
 * корзина, экспорт, промо) обязаны выбирать одну и ту же строку: раньше findPrice()
 * брал первую попавшуюся, а loadPriceMap() через mapWithKeys оставлял произвольную
 * строку набора — клиент видел разные цены на один товар в разных местах сайта.
 * Общее правило без явного склада — минимальный warehouse_id.
 */
class IndividualPriceDeterminismTest extends TestCase
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

    #[Test]
    public function точечный_и_батч_путь_дают_одну_и_ту_же_цену(): void
    {
        $user = User::factory()->create(['erp_id' => 'det-partner-001']);
        $product = Product::factory()->create(['base_price' => 1000]);

        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w1->id, 'price' => 800]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w2->id, 'price' => 600]);

        $expected = (float) IndividualPrice::where('warehouse_id', min($w1->id, $w2->id))->value('price');

        $service = $this->makeService();
        $pointPrice = $service->getPriceResult($product, $user)->getDisplayPrice();
        $batchPrice = $service->getPriceMapForProducts(collect([$product]), $user)[$product->id]->getDisplayPrice();

        $this->assertSame($expected, $pointPrice);
        $this->assertSame($pointPrice, $batchPrice);
    }

    #[Test]
    public function прокси_отдаёт_детерминированную_строку_без_склада(): void
    {
        $user = User::factory()->create(['erp_id' => 'det-partner-002']);
        $product = Product::factory()->create(['base_price' => 1000]);

        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();

        // Порядок вставки обратный порядку id — выбор не должен от него зависеть.
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w2->id, 'price' => 600]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w1->id, 'price' => 800]);

        $expected = (float) IndividualPrice::where('warehouse_id', min($w1->id, $w2->id))->value('price');

        $found = IndividualPriceProxy::findPrice($user->id, $product->id);
        $mapped = IndividualPriceProxy::loadPriceMap($user->id, [$product->id]);
        $modelFound = IndividualPrice::findPrice($user->id, $product->id);

        $this->assertSame($expected, (float) $found->price);
        $this->assertSame($expected, (float) $mapped[$product->id]);
        $this->assertSame($expected, (float) $modelFound->price);
    }

    #[Test]
    public function батч_путь_фильтрует_цены_по_указанному_складу(): void
    {
        $user = User::factory()->create(['erp_id' => 'det-partner-003']);
        $product = Product::factory()->create(['base_price' => 1000]);

        $w1 = Warehouse::factory()->create();
        $w2 = Warehouse::factory()->create();

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w1->id, 'price' => 800]);
        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $w2->id, 'price' => 600]);

        $service = $this->makeService();

        $mapW1 = $service->getPriceMapForProducts(collect([$product]), $user, $w1->id);
        $mapW2 = $service->getPriceMapForProducts(collect([$product]), $user, $w2->id);

        $this->assertSame(800.0, $mapW1[$product->id]->getDisplayPrice());
        $this->assertSame(600.0, $mapW2[$product->id]->getDisplayPrice());

        // Тот же склад — та же цена, что и в точечном пути.
        $this->assertSame(
            $service->getPriceResult($product, $user, $w2->id)->getDisplayPrice(),
            $mapW2[$product->id]->getDisplayPrice(),
        );
    }

    #[Test]
    public function без_строки_по_указанному_складу_действует_базовая_цена(): void
    {
        $user = User::factory()->create(['erp_id' => 'det-partner-004']);
        $product = Product::factory()->create(['base_price' => 1000]);

        $priced = Warehouse::factory()->create();
        $empty = Warehouse::factory()->create();

        IndividualPrice::create(['partner_id' => $user->id, 'product_id' => $product->id, 'warehouse_id' => $priced->id, 'price' => 800]);

        $map = $this->makeService()->getPriceMapForProducts(collect([$product]), $user, $empty->id);

        $this->assertSame(1000.0, $map[$product->id]->getDisplayPrice());
        $this->assertFalse($map[$product->id]->hasDiscount);
    }
}
