<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int> ID товаров в ответе index
     */
    private function indexIds(array $params): array
    {
        $ids = [];

        $this->actingAs($this->admin)
            ->get(route('admin.products.index', $params))
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $data = $page->toArray()['props']['products']['data'];
                $ids = array_column($data, 'id');
            });

        return $ids;
    }

    #[Test]
    public function filters_by_brand(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        $match = Product::factory()->create(['brand_id' => $brandA->id]);
        Product::factory()->create(['brand_id' => $brandB->id]);

        $ids = $this->indexIds(['brands' => [$brandA->id]]);

        $this->assertSame([$match->id], $ids);
    }

    #[Test]
    public function filters_by_hidden_visibility(): void
    {
        $hidden = Product::factory()->create(['hidden' => true]);
        $visible = Product::factory()->create(['hidden' => false]);

        $this->assertSame([$hidden->id], $this->indexIds(['hidden' => 'yes']));
        $this->assertSame([$visible->id], $this->indexIds(['hidden' => 'no']));
    }

    #[Test]
    public function filters_by_description_presence(): void
    {
        $withDesc = Product::factory()->create(['description' => 'Полезное описание']);
        $withoutDesc = Product::factory()->create(['description' => null]);
        $emptyDesc = Product::factory()->create(['description' => '']);

        $withIds = $this->indexIds(['description_filter' => 'with']);
        $withoutIds = $this->indexIds(['description_filter' => 'without']);

        $this->assertSame([$withDesc->id], $withIds);
        $this->assertEqualsCanonicalizing([$withoutDesc->id, $emptyDesc->id], $withoutIds);
    }

    #[Test]
    public function filters_by_price_range(): void
    {
        $cheap = Product::factory()->create(['base_price' => 100]);
        $mid = Product::factory()->create(['base_price' => 500]);
        $expensive = Product::factory()->create(['base_price' => 1000]);

        $ids = $this->indexIds(['price_min' => 200, 'price_max' => 800]);

        $this->assertSame([$mid->id], $ids);
        $this->assertNotContains($cheap->id, $ids);
        $this->assertNotContains($expensive->id, $ids);
    }

    #[Test]
    public function filters_by_flags(): void
    {
        $liquidation = Product::factory()->create(['is_liquidation' => true, 'is_new' => false]);
        Product::factory()->create(['is_liquidation' => false, 'is_new' => true]);

        $ids = $this->indexIds(['flags' => ['is_liquidation']]);

        $this->assertSame([$liquidation->id], $ids);
    }

    #[Test]
    public function export_respects_filters(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $brand->id]);
        Product::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.products.export', ['brands' => [$brand->id]]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type'),
        );
    }
}
