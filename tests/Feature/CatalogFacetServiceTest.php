<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Product\CatalogFacetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogFacetServiceTest extends TestCase
{
    use RefreshDatabase;

    private CatalogFacetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CatalogFacetService;
    }

    // ─── getAttributeFacets ─────────────────────────────────

    public function test_attribute_facets_groups_by_attribute(): void
    {
        $attr = Attribute::create([
            'name' => 'Цвет',
            'slug' => 'color',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        $red = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Красный', 'sort_order' => 0]);
        $blue = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Синий', 'sort_order' => 1]);

        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $p3 = Product::factory()->create();

        $p1->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $red->id]);
        $p2->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $red->id]);
        $p3->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $blue->id]);

        $result = $this->service->getAttributeFacets(Product::query());

        $this->assertCount(1, $result);
        $this->assertEquals('Цвет', $result[0]['name']);
        $this->assertCount(2, $result[0]['values']);

        $redFacet = collect($result[0]['values'])->firstWhere('id', $red->id);
        $blueFacet = collect($result[0]['values'])->firstWhere('id', $blue->id);

        $this->assertEquals(2, $redFacet['count']);
        $this->assertEquals(1, $blueFacet['count']);
    }

    public function test_attribute_facets_respects_base_query(): void
    {
        $brand1 = Brand::factory()->create();
        $brand2 = Brand::factory()->create();

        $attr = Attribute::create([
            'name' => 'Размер',
            'slug' => 'size',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        $small = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'S', 'sort_order' => 0]);

        $p1 = Product::factory()->create(['brand_id' => $brand1->id]);
        $p2 = Product::factory()->create(['brand_id' => $brand2->id]);

        $p1->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $small->id]);
        $p2->attributeValues()->create(['attribute_id' => $attr->id, 'attribute_value_id' => $small->id]);

        // Фильтрация по brand1 — должен быть count=1
        $result = $this->service->getAttributeFacets(
            Product::query()->where('brand_id', $brand1->id)
        );

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['values'][0]['count']);
    }

    public function test_attribute_facets_only_filterable(): void
    {
        $filterable = Attribute::create([
            'name' => 'Цвет',
            'slug' => 'color',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        $notFilterable = Attribute::create([
            'name' => 'Материал',
            'slug' => 'material',
            'type' => 'select',
            'is_filterable' => false,
            'sort_order' => 1,
        ]);

        $val1 = AttributeValue::create(['attribute_id' => $filterable->id, 'value' => 'Красный', 'sort_order' => 0]);
        $val2 = AttributeValue::create(['attribute_id' => $notFilterable->id, 'value' => 'Кожа', 'sort_order' => 0]);

        $p = Product::factory()->create();
        $p->attributeValues()->create(['attribute_id' => $filterable->id, 'attribute_value_id' => $val1->id]);
        $p->attributeValues()->create(['attribute_id' => $notFilterable->id, 'attribute_value_id' => $val2->id]);

        $result = $this->service->getAttributeFacets(Product::query());

        $this->assertCount(1, $result);
        $this->assertEquals('Цвет', $result[0]['name']);
    }

    public function test_attribute_facets_empty(): void
    {
        Product::factory()->count(2)->create();

        $result = $this->service->getAttributeFacets(Product::query());

        $this->assertCount(0, $result);
    }

    public function test_attribute_facets_sorted_by_count_desc(): void
    {
        $attr = Attribute::create([
            'name' => 'Цвет',
            'slug' => 'color',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        // sort_order намеренно нарушает ожидаемый count-порядок:
        // если бы сортировка осталась по sort_order, первым шёл бы «А-редкий».
        $rare = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'А-редкий', 'sort_order' => 0]);
        $popular = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Я-популярный', 'sort_order' => 1]);
        $medium = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'М-средний', 'sort_order' => 2]);

        foreach (range(1, 5) as $_) {
            Product::factory()->create()->attributeValues()->create([
                'attribute_id' => $attr->id,
                'attribute_value_id' => $popular->id,
            ]);
        }
        foreach (range(1, 3) as $_) {
            Product::factory()->create()->attributeValues()->create([
                'attribute_id' => $attr->id,
                'attribute_value_id' => $medium->id,
            ]);
        }
        Product::factory()->create()->attributeValues()->create([
            'attribute_id' => $attr->id,
            'attribute_value_id' => $rare->id,
        ]);

        $result = $this->service->getAttributeFacets(Product::query());

        $values = $result[0]['values'];
        $this->assertEquals($popular->id, $values[0]['id'], 'Самый частый — сверху');
        $this->assertEquals($medium->id, $values[1]['id']);
        $this->assertEquals($rare->id, $values[2]['id']);
    }

    public function test_attribute_facets_pin_selected_to_top(): void
    {
        $attr = Attribute::create([
            'name' => 'Цвет',
            'slug' => 'color',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        $popular = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Популярный', 'sort_order' => 0]);
        $rare = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Редкий', 'sort_order' => 1]);
        $medium = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'Средний', 'sort_order' => 2]);

        foreach (range(1, 10) as $_) {
            Product::factory()->create()->attributeValues()->create([
                'attribute_id' => $attr->id,
                'attribute_value_id' => $popular->id,
            ]);
        }
        foreach (range(1, 5) as $_) {
            Product::factory()->create()->attributeValues()->create([
                'attribute_id' => $attr->id,
                'attribute_value_id' => $medium->id,
            ]);
        }
        Product::factory()->create()->attributeValues()->create([
            'attribute_id' => $attr->id,
            'attribute_value_id' => $rare->id,
        ]);

        // Выбираем «Редкий» — он должен оказаться сверху, несмотря на самый низкий count.
        $result = $this->service->getAttributeFacets(Product::query(), [$rare->id]);

        $values = $result[0]['values'];
        $this->assertEquals($rare->id, $values[0]['id'], 'Выбранный значение прибито к верху');
        $this->assertEquals($popular->id, $values[1]['id'], 'Затем — по count desc');
        $this->assertEquals($medium->id, $values[2]['id']);
    }

    public function test_attribute_facets_pin_selected_preserves_selection_order(): void
    {
        $attr = Attribute::create([
            'name' => 'Цвет',
            'slug' => 'color',
            'type' => 'select',
            'is_filterable' => true,
            'sort_order' => 0,
        ]);
        $a = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'A', 'sort_order' => 0]);
        $b = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'B', 'sort_order' => 1]);
        $c = AttributeValue::create(['attribute_id' => $attr->id, 'value' => 'C', 'sort_order' => 2]);

        // У всех одинаковый count, чтобы порядок выбора был единственным дискриминатором.
        foreach ([$a, $b, $c] as $val) {
            Product::factory()->create()->attributeValues()->create([
                'attribute_id' => $attr->id,
                'attribute_value_id' => $val->id,
            ]);
        }

        // Выбираем в порядке C, A — ожидаем именно такой порядок сверху.
        $result = $this->service->getAttributeFacets(Product::query(), [$c->id, $a->id]);

        $values = $result[0]['values'];
        $this->assertEquals($c->id, $values[0]['id']);
        $this->assertEquals($a->id, $values[1]['id']);
        $this->assertEquals($b->id, $values[2]['id']);
    }

    // ─── getBrandFacets ─────────────────────────────────────

    public function test_brand_facets_returns_correct_counts(): void
    {
        $brand1 = Brand::factory()->create(['name' => 'Lelo']);
        $brand2 = Brand::factory()->create(['name' => 'Satisfyer']);

        Product::factory()->count(3)->create(['brand_id' => $brand1->id]);
        Product::factory()->count(2)->create(['brand_id' => $brand2->id]);

        $result = $this->service->getBrandFacets(Product::query());

        $this->assertCount(2, $result);

        $lelo = collect($result)->firstWhere('id', $brand1->id);
        $satisfyer = collect($result)->firstWhere('id', $brand2->id);

        $this->assertEquals(3, $lelo['count']);
        $this->assertEquals('Lelo', $lelo['name']);
        $this->assertEquals(2, $satisfyer['count']);
    }

    public function test_brand_facets_excludes_null_brand(): void
    {
        $brand = Brand::factory()->create();

        Product::factory()->create(['brand_id' => $brand->id]);
        Product::factory()->create(['brand_id' => null]);

        $result = $this->service->getBrandFacets(Product::query());

        $this->assertCount(1, $result);
        $this->assertEquals($brand->id, $result[0]['id']);
    }

    public function test_brand_facets_respects_base_query(): void
    {
        $brand1 = Brand::factory()->create();
        $brand2 = Brand::factory()->create();

        Product::factory()->count(3)->create(['brand_id' => $brand1->id, 'base_price' => 100]);
        Product::factory()->count(2)->create(['brand_id' => $brand2->id, 'base_price' => 500]);

        // Только дешёвые — бренд2 не попадёт
        $result = $this->service->getBrandFacets(
            Product::query()->where('base_price', '<=', 200)
        );

        $this->assertCount(1, $result);
        $this->assertEquals($brand1->id, $result[0]['id']);
    }

    public function test_brand_facets_respects_hidden_scope(): void
    {
        // Регрессия для бага #26: раньше cloneBaseIds использовал getQuery()
        // без applyScopes → HiddenScope игнорировался и в счётчик попадали hidden-товары.
        $brand = Brand::factory()->create();

        Product::factory()->count(2)->create(['brand_id' => $brand->id, 'hidden' => true]);
        Product::factory()->create(['brand_id' => $brand->id, 'hidden' => false]);

        $result = $this->service->getBrandFacets(Product::query());

        $this->assertCount(1, $result);
        $this->assertEquals($brand->id, $result[0]['id']);
        $this->assertEquals(1, $result[0]['count'], 'В счётчик не должны попадать скрытые товары');
    }

    // ─── getCategoryFacets ──────────────────────────────────

    public function test_category_facets_returns_correct_counts(): void
    {
        $cat1 = Category::factory()->create(['name' => 'Бельё']);
        $cat2 = Category::factory()->create(['name' => 'Аксессуары']);

        Product::factory()->count(4)->create(['category_id' => $cat1->id]);
        Product::factory()->count(1)->create(['category_id' => $cat2->id]);

        $result = $this->service->getCategoryFacets(Product::query());

        $this->assertCount(2, $result);

        $bele = collect($result)->firstWhere('id', $cat1->id);
        $this->assertEquals(4, $bele['count']);
        $this->assertEquals('Бельё', $bele['name']);
    }

    public function test_category_facets_respects_base_query(): void
    {
        $brand = Brand::factory()->create();
        $cat = Category::factory()->create();

        Product::factory()->count(2)->create(['category_id' => $cat->id, 'brand_id' => $brand->id]);
        Product::factory()->count(3)->create(['category_id' => $cat->id, 'brand_id' => null]);

        // Только товары с брендом
        $result = $this->service->getCategoryFacets(
            Product::query()->whereNotNull('brand_id')
        );

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['count']);
    }

    // ─── getPriceIntervals ──────────────────────────────────

    public function test_price_intervals_returns_min_max(): void
    {
        Product::factory()->create(['base_price' => 150]);
        Product::factory()->create(['base_price' => 5000]);
        Product::factory()->create(['base_price' => 12000]);

        $result = $this->service->getPriceIntervals(Product::query());

        $this->assertEquals(150, $result['min']);
        $this->assertEquals(12000, $result['max']);
        $this->assertArrayHasKey('buckets', $result);
        $this->assertNotEmpty($result['buckets']);
    }

    public function test_price_intervals_returns_buckets(): void
    {
        Product::factory()->create(['base_price' => 100]);
        Product::factory()->create(['base_price' => 500]);
        Product::factory()->create(['base_price' => 1000]);
        Product::factory()->create(['base_price' => 5000]);
        Product::factory()->create(['base_price' => 10000]);

        $result = $this->service->getPriceIntervals(Product::query());

        $this->assertNotEmpty($result['buckets']);

        // Сумма count по всем бакетам должна равняться количеству товаров
        $totalCount = collect($result['buckets'])->sum('count');
        $this->assertEquals(5, $totalCount);

        // Каждый бакет должен иметь from, to, count
        foreach ($result['buckets'] as $bucket) {
            $this->assertArrayHasKey('from', $bucket);
            $this->assertArrayHasKey('to', $bucket);
            $this->assertArrayHasKey('count', $bucket);
            $this->assertLessThanOrEqual($bucket['to'], $bucket['from']);
        }
    }

    public function test_price_intervals_empty(): void
    {
        // Нет товаров
        $result = $this->service->getPriceIntervals(Product::query());

        $this->assertEquals(0, $result['min']);
        $this->assertEquals(0, $result['max']);
        $this->assertEmpty($result['buckets']);
    }
}
