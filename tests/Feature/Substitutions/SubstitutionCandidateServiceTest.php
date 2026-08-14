<?php

namespace Tests\Feature\Substitutions;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Substitution\CandidateKind;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\ProductModel;
use App\Models\ProductSubstitution;
use App\Models\User;
use App\Services\Substitution\SubstitutionCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Слои автоподбора замен (sub-06).
 *
 * Остатки и цены мокируются: остаток по умолчанию 100 на всё, цена — базовая
 * цена товара без скидки. Коридор и слои проверяются на управляемых данных.
 */
class SubstitutionCandidateServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $stockOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['substitutions.enabled' => true]);

        $stock = $this->createMock(StockServiceInterface::class);
        $stock->method('getAvailableStockMap')->willReturnCallback(function (iterable $products) {
            $map = [];
            foreach ($products as $product) {
                $map[$product->id] = $this->stockOverrides[$product->id] ?? 100;
            }

            return $map;
        });
        $stock->method('getAvailableStock')->willReturnCallback(
            fn (Product $product) => $this->stockOverrides[$product->id] ?? 100,
        );
        $stock->method('getPreorderStock')->willReturnCallback(
            fn (Product $product) => $this->stockOverrides['preorder-'.$product->id] ?? 0,
        );
        $this->app->instance(StockServiceInterface::class, $stock);

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getPriceMapForProducts')->willReturnCallback(function (iterable $products) {
            $map = [];
            foreach ($products as $product) {
                $map[$product->id] = PriceResult::withoutDiscount((float) $product->base_price);
            }

            return $map;
        });
        $prices->method('getUserPrice')->willReturnCallback(
            fn (Product $product) => (float) $product->base_price,
        );
        $this->app->instance(PriceServiceInterface::class, $prices);
    }

    private function engine(): SubstitutionCandidateService
    {
        return $this->app->make(SubstitutionCandidateService::class);
    }

    private function makeCancelledLine(Product $product, int $quantity = 5, float $price = 1000.0): OrderItem
    {
        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cancelled' => true,
            'quantity' => $quantity,
            'final_price' => $price,
            'subtotal' => $price * $quantity,
        ]);
    }

    #[Test]
    public function price_corridor_is_asymmetric_and_uses_the_cancelled_line_price(): void
    {
        $category = Category::factory()->create();
        $source = Product::factory()->create(['category_id' => $category->id, 'base_price' => 1000]);

        // −25 % и +10 % — границы; заметно дороже и заметно дешевле отсекаются.
        $tooExpensive = Product::factory()->create(['category_id' => $category->id, 'base_price' => 1200, 'name' => 'Вибратор дорогой']);
        $tooCheap = Product::factory()->create(['category_id' => $category->id, 'base_price' => 700, 'name' => 'Вибратор дешёвый']);
        $inCorridor = Product::factory()->create(['category_id' => $category->id, 'base_price' => 1050, 'name' => 'Вибратор в коридоре']);

        foreach ([$tooExpensive, $tooCheap, $inCorridor] as $candidate) {
            ProductSubstitution::factory()->create([
                'from_product_id' => $source->id,
                'to_product_id' => $candidate->id,
            ]);
        }

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source, price: 1000.0));

        $ids = array_column($set->candidates, 'product_id');
        $this->assertContains($inCorridor->id, $ids);
        $this->assertNotContains($tooExpensive->id, $ids);
        $this->assertNotContains($tooCheap->id, $ids);
    }

    #[Test]
    public function candidates_without_enough_stock_are_dropped(): void
    {
        $source = Product::factory()->create(['base_price' => 1000]);
        $candidate = Product::factory()->create(['base_price' => 1000]);

        ProductSubstitution::factory()->create([
            'from_product_id' => $source->id,
            'to_product_id' => $candidate->id,
        ]);

        $this->stockOverrides[$candidate->id] = 3; // нужно 5

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source, quantity: 5));

        $this->assertNotContains($candidate->id, array_column($set->candidates, 'product_id'));
    }

    #[Test]
    public function defect_batch_of_the_same_product_comes_first_and_allows_partial_quantity(): void
    {
        $source = Product::factory()->create(['base_price' => 1000]);

        $defect = ProductDefect::factory()->sellable(600.0)->create([
            'product_id' => $source->id,
            'quantity' => 2, // частично: 2 из 5
        ]);

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source, quantity: 5));

        $first = $set->candidates[0];
        $this->assertSame(CandidateKind::DEFECT_SAME, $first['kind']);
        $this->assertSame($defect->id, $first['product_defect_id']);
        // Уценка −40 % — вне коридора, но это тот же товар: коридор не применяется.
        $this->assertSame(600.0, $first['price']);
        $this->assertSame(2, $first['suggested_quantity']);
        $this->assertStringContainsString('уценка', $first['reason']);
    }

    #[Test]
    public function confirmed_links_come_before_heuristic_layers(): void
    {
        $model = ProductModel::create(['name' => 'Тестовая модель']);
        $category = Category::factory()->create();

        $source = Product::factory()->create([
            'category_id' => $category->id,
            'model_id' => $model->id,
            'base_price' => 1000,
        ]);
        $variant = Product::factory()->create([
            'category_id' => $category->id,
            'model_id' => $model->id,
            'base_price' => 1000,
            'name' => 'Вариант той же модели',
        ]);
        $linked = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 1000,
            'name' => 'Подтверждённая замена',
        ]);

        ProductSubstitution::factory()->create([
            'from_product_id' => $source->id,
            'to_product_id' => $linked->id,
            'note' => 'Та же линейка, следующее поколение',
        ]);

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source));

        $this->assertSame($linked->id, $set->candidates[0]['product_id']);
        $this->assertSame(CandidateKind::LINKED, $set->candidates[0]['kind']);
        $this->assertSame('Та же линейка, следующее поколение', $set->candidates[0]['reason']);

        $kinds = array_map(fn ($c) => $c['kind'], $set->candidates);
        $this->assertContains(CandidateKind::VARIANT, $kinds);
    }

    #[Test]
    public function unconfirmed_and_rejected_links_are_not_used(): void
    {
        $source = Product::factory()->create(['base_price' => 1000]);
        $awaiting = Product::factory()->create(['base_price' => 1000]);
        $rejected = Product::factory()->create(['base_price' => 1000]);

        ProductSubstitution::factory()->awaitingReview()->create([
            'from_product_id' => $source->id,
            'to_product_id' => $awaiting->id,
        ]);
        ProductSubstitution::factory()->rejected()->create([
            'from_product_id' => $source->id,
            'to_product_id' => $rejected->id,
        ]);

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source));

        $ids = array_column($set->candidates, 'product_id');
        $this->assertNotContains($awaiting->id, $ids);
        $this->assertNotContains($rejected->id, $ids);
    }

    #[Test]
    public function generic_model_groups_are_excluded_from_the_variant_layer(): void
    {
        $genericModel = ProductModel::create(['name' => 'Neutral']);
        $category = Category::factory()->create();

        $source = Product::factory()->create([
            'category_id' => $category->id,
            'model_id' => $genericModel->id,
            'base_price' => 1000,
            'name' => 'Товар из склейки',
        ]);

        // Группа из 12 товаров — заведомо больше порога 10.
        Product::factory()->count(11)->create([
            'category_id' => $category->id,
            'model_id' => $genericModel->id,
            'base_price' => 1000,
        ]);

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source));

        $kinds = array_map(fn ($c) => $c['kind'], $set->candidates);
        $this->assertNotContains(CandidateKind::VARIANT, $kinds);
    }

    #[Test]
    public function a_strap_never_gets_a_collar_as_the_first_candidate(): void
    {
        // Ловушка паспортных атрибутов: ошейник из той же категории не должен
        // обгонять стропу — функция живёт в головном слове названия.
        $category = Category::factory()->create(['name' => 'БДСМ']);

        $source = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 600,
            'name' => 'Стропа Theatre для связывания',
        ]);
        $strap = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 600,
            'name' => 'Стропа Anonymo классическая',
        ]);
        $collar = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 620,
            'name' => 'Ошейник TOYFA Theatre кожаный',
        ]);

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source, price: 600.0));

        $this->assertNotEmpty($set->candidates);
        $this->assertSame($strap->id, $set->candidates[0]['product_id']);
        $this->assertSame(CandidateKind::FUNCTIONAL, $set->candidates[0]['kind']);
    }

    #[Test]
    public function head_word_normalization_merges_synonyms_and_skips_modifiers(): void
    {
        $engine = $this->engine();

        $this->assertSame('вибратор', $engine->normalizedHeadWord('Вибромассажёр Lovense Lush 3'));
        $this->assertSame('втулка', $engine->normalizedHeadWord('Анальная втулка Sexus Glass'));
        $this->assertSame('фаллоимитатор', $engine->normalizedHeadWord('Реалистичный фаллоимитатор Neon'));
        $this->assertSame('лубрикант', $engine->normalizedHeadWord('Смазка на водной основе'));
    }

    #[Test]
    public function wait_option_appears_when_the_same_product_is_expected_back(): void
    {
        $source = Product::factory()->create(['base_price' => 1000]);

        $this->stockOverrides[$source->id] = 0;
        $this->stockOverrides['preorder-'.$source->id] = 20;

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source));

        $this->assertTrue($set->waitAvailable);
        $this->assertStringContainsString('ожидается', $set->waitReason);
    }

    #[Test]
    public function the_candidate_limit_is_respected(): void
    {
        config(['substitutions.matching.max_candidates_per_line' => 2]);

        $source = Product::factory()->create(['base_price' => 1000]);

        Product::factory()->count(5)->create(['base_price' => 1000])->each(
            fn (Product $candidate) => ProductSubstitution::factory()->create([
                'from_product_id' => $source->id,
                'to_product_id' => $candidate->id,
            ]),
        );

        $set = $this->engine()->forOrderItem($this->makeCancelledLine($source));

        $this->assertCount(2, $set->candidates);
    }
}
