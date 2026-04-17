<?php

namespace Tests\Feature\Requests;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFilterRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Route::get('/_test/catalog-filter', function (\App\Http\Requests\User\ProductFilterRequest $request) {
            return response()->json([
                'validated' => $request->validated(),
                'filters_applied' => $request->filtersApplied(),
            ]);
        });
    }

    private function filterRequest(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/_test/catalog-filter?'.http_build_query($params));
    }

    // ─── prepareForValidation (compact URL) ─────────────────

    public function test_compact_param_fv_expands_to_attribute_value_ids(): void
    {
        $response = $this->filterRequest(['fv' => [999]]);
        // 999 не существует, поэтому будет ошибка валидации — это нормально,
        // но параметр должен развернуться
        $response->assertJsonValidationErrors(['attribute_value_ids.0']);
    }

    public function test_compact_param_b_expands_to_brand_ids(): void
    {
        $brand = Brand::factory()->create();

        $response = $this->filterRequest(['b' => [$brand->id]]);

        $response->assertOk();
        $response->assertJsonPath('validated.brand_ids', [(string) $brand->id]);
    }

    public function test_compact_param_c_expands_to_category_ids(): void
    {
        $category = Category::factory()->create();

        $response = $this->filterRequest(['c' => [$category->id]]);

        $response->assertOk();
        $response->assertJsonPath('validated.category_ids', [(string) $category->id]);
    }

    public function test_compact_param_s_expands_to_sort(): void
    {
        $response = $this->filterRequest(['s' => 'price_asc']);

        $response->assertOk();
        $response->assertJsonPath('validated.sort', 'price_asc');
    }

    public function test_compact_param_pp_expands_to_per_page(): void
    {
        $response = $this->filterRequest(['pp' => 40]);

        $response->assertOk();
        $response->assertJsonPath('validated.per_page', '40');
    }

    public function test_compact_param_p_expands_to_page(): void
    {
        $response = $this->filterRequest(['p' => 3]);

        $response->assertOk();
        $response->assertJsonPath('validated.page', '3');
    }

    public function test_full_name_takes_priority_over_compact(): void
    {
        $response = $this->filterRequest([
            's' => 'price_asc',
            'sort' => 'price_desc',
        ]);

        $response->assertOk();
        $response->assertJsonPath('validated.sort', 'price_desc');
    }

    // ─── Валидация: валидные данные ─────────────────────────

    public function test_empty_request_is_valid(): void
    {
        $this->filterRequest()->assertOk();
    }

    public function test_valid_search_query(): void
    {
        $this->filterRequest(['q' => 'платье красное'])->assertOk();
    }

    public function test_valid_sort_values(): void
    {
        foreach (['newest', 'price_asc', 'price_desc', 'name_asc', 'name_desc'] as $sort) {
            $this->filterRequest(['sort' => $sort])->assertOk();
        }
    }

    public function test_valid_view_values(): void
    {
        foreach (['grid', 'list'] as $view) {
            $this->filterRequest(['view' => $view])->assertOk();
        }
    }

    public function test_valid_per_page_values(): void
    {
        foreach ([10, 20, 40, 60, 100] as $pp) {
            $this->filterRequest(['per_page' => $pp])->assertOk();
        }
    }

    public function test_valid_price_range(): void
    {
        $this->filterRequest(['price_min' => 100, 'price_max' => 5000])->assertOk();
    }

    public function test_valid_category_id(): void
    {
        $category = Category::factory()->create();

        $this->filterRequest(['category_id' => $category->id])->assertOk();
    }

    public function test_valid_brand_ids(): void
    {
        $brands = Brand::factory()->count(2)->create();

        $this->filterRequest([
            'brand_ids' => $brands->pluck('id')->toArray(),
        ])->assertOk();
    }

    public function test_valid_collection_ids(): void
    {
        $selection = ProductSelection::factory()->create(['slug' => 'test-selection']);

        $this->filterRequest([
            'collection_ids' => [$selection->id],
        ])->assertOk();
    }

    public function test_valid_in_stock_mode(): void
    {
        foreach (['instock', 'preorder', 'notavailable'] as $mode) {
            $this->filterRequest(['in_stock_mode' => $mode])->assertOk();
        }
    }

    public function test_valid_in_sale(): void
    {
        $this->filterRequest(['in_sale' => '1'])->assertOk();
        $this->filterRequest(['in_sale' => '0'])->assertOk();
    }

    // ─── Валидация: невалидные данные ───────────────────────

    public function test_invalid_sort_value_rejected(): void
    {
        $this->filterRequest(['sort' => 'invalid'])
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_invalid_view_value_rejected(): void
    {
        $this->filterRequest(['view' => 'table'])
            ->assertJsonValidationErrors(['view']);
    }

    public function test_invalid_per_page_value_rejected(): void
    {
        $this->filterRequest(['per_page' => 15])
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_negative_price_rejected(): void
    {
        $this->filterRequest(['price_min' => -100])
            ->assertJsonValidationErrors(['price_min']);
    }

    public function test_search_query_too_long_rejected(): void
    {
        $this->filterRequest(['q' => str_repeat('а', 201)])
            ->assertJsonValidationErrors(['q']);
    }

    public function test_nonexistent_category_id_rejected(): void
    {
        $this->filterRequest(['category_id' => 99999])
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_nonexistent_brand_id_rejected(): void
    {
        $this->filterRequest(['brand_ids' => [99999]])
            ->assertJsonValidationErrors(['brand_ids.0']);
    }

    public function test_page_zero_rejected(): void
    {
        $this->filterRequest(['page' => 0])
            ->assertJsonValidationErrors(['page']);
    }

    public function test_invalid_in_stock_mode_rejected(): void
    {
        $this->filterRequest(['in_stock_mode' => 'invalid'])
            ->assertJsonValidationErrors(['in_stock_mode']);
    }

    // ─── filtersApplied() ───────────────────────────────────

    public function test_filters_applied_false_when_empty(): void
    {
        $response = $this->filterRequest();

        $response->assertOk();
        $response->assertJsonPath('filters_applied', false);
    }

    public function test_filters_applied_false_when_only_sort_and_view(): void
    {
        $response = $this->filterRequest(['sort' => 'price_asc', 'view' => 'list', 'per_page' => 40]);

        $response->assertOk();
        $response->assertJsonPath('filters_applied', false);
    }

    public function test_filters_applied_true_when_search(): void
    {
        $response = $this->filterRequest(['q' => 'test']);

        $response->assertOk();
        $response->assertJsonPath('filters_applied', true);
    }

    public function test_filters_applied_true_when_brand_filter(): void
    {
        $brand = Brand::factory()->create();

        $response = $this->filterRequest(['brand_ids' => [$brand->id]]);

        $response->assertOk();
        $response->assertJsonPath('filters_applied', true);
    }

    // ─── Русские сообщения об ошибках ───────────────────────

    public function test_error_messages_are_in_russian(): void
    {
        $response = $this->filterRequest(['sort' => 'invalid']);

        $response->assertJsonValidationErrors(['sort']);
        $json = $response->json('errors.sort.0');
        $this->assertStringContainsString('сортировк', mb_strtolower($json));
    }
}
