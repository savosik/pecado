<?php

namespace Tests\Feature;

use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing\IndividualPriceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IndividualPriceAdminStatsTest extends TestCase
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

    public function test_index_shows_actual_individual_price_stats_from_prices_db(): void
    {
        Cache::put('individual_prices_stats', [
            'total_prices' => 0,
            'total_partners' => 0,
        ], 3600);

        app(IndividualPriceStatsService::class)->forget();

        $partnerOne = User::factory()->create();
        $partnerTwo = User::factory()->create();
        $warehouse = Warehouse::factory()->create();

        IndividualPrice::create([
            'partner_id' => $partnerOne->id,
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'price' => 100.00,
        ]);

        IndividualPrice::create([
            'partner_id' => $partnerTwo->id,
            'product_id' => Product::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'price' => 200.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.individual-prices.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pages/IndividualPrices/Index', false)
            ->where('stats.total_prices', 2)
            ->where('stats.total_partners', 2)
            ->has('prices.data', 2)
        );
    }
}
