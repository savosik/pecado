<?php

namespace Tests\Feature\Defect;

use App\Models\ClientStatus;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Defect\DefectReferencePriceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Справочная цена клиента в уценке и массовая установка цен от неё.
 */
class DefectReferencePriceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $this->warehouse = Warehouse::factory()->create();
    }

    private function buyer(): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);

        foreach (['products.view', 'defects.view', 'defects.price', 'defects.publish'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Клиент со статусом: только у выгруженных из 1С партнёров есть личные цены. */
    private function client(ClientStatus $status): User
    {
        return User::factory()->create([
            'client_status_id' => $status->id,
            'erp_id' => 'partner-'.fake()->unique()->numerify('######'),
        ]);
    }

    private function price(User $client, Product $product, float $price): void
    {
        IndividualPrice::create([
            'partner_id' => $client->id,
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'price' => $price,
        ]);
    }

    /**
     * @return array{diamond: ClientStatus, vip: ClientStatus, gold: ClientStatus}
     */
    private function statuses(): array
    {
        return [
            'diamond' => ClientStatus::create(['name' => 'Diamond', 'color' => '#B9F2FF', 'amount_from' => 3000000]),
            'vip' => ClientStatus::create(['name' => 'VIP', 'color' => '#C0C0C0', 'amount_from' => 1000000]),
            'gold' => ClientStatus::create(['name' => 'Gold', 'color' => '#FFD700', 'amount_from' => 300000]),
        ];
    }

    #[Test]
    public function reference_price_takes_cheapest_status_available(): void
    {
        $statuses = $this->statuses();
        $product = Product::factory()->create();

        $this->price($this->client($statuses['diamond']), $product, 800);
        $this->price($this->client($statuses['vip']), $product, 900);
        $this->price($this->client($statuses['gold']), $product, 1000);

        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defects.data.0.reference_price.price', 800)
                ->where('defects.data.0.reference_price.status.name', 'Diamond')
                ->has('defects.data.0.reference_price.ladder', 3)
                ->etc()
            );
    }

    #[Test]
    public function reference_price_falls_back_to_next_status(): void
    {
        $statuses = $this->statuses();
        $product = Product::factory()->create();

        // У Diamond цены на этот товар нет — ориентир должен уехать на VIP.
        $this->client($statuses['diamond']);
        $this->price($this->client($statuses['vip']), $product, 950);
        $this->price($this->client($statuses['gold']), $product, 1100);

        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defects.data.0.reference_price.price', 950)
                ->where('defects.data.0.reference_price.status.name', 'VIP')
                ->has('defects.data.0.reference_price.ladder', 2)
                ->etc()
            );
    }

    /**
     * На проде amount_from у статусов не заполнен, и сортировка по нему
     * схлопывалась в алфавитную — наверх всплывал Bronze. Ориентир должен
     * определяться ценой, а не названием статуса.
     */
    #[Test]
    public function reference_price_ignores_status_names_when_thresholds_are_empty(): void
    {
        $bronze = ClientStatus::create(['name' => 'Bronze', 'amount_from' => null]);
        $diamond = ClientStatus::create(['name' => 'Diamond', 'amount_from' => null]);
        $gold = ClientStatus::create(['name' => 'Gold', 'amount_from' => null]);

        $product = Product::factory()->create();

        $this->price($this->client($bronze), $product, 1500);
        $this->price($this->client($diamond), $product, 900);
        $this->price($this->client($gold), $product, 1200);

        ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defects.data.0.reference_price.price', 900)
                ->where('defects.data.0.reference_price.status.name', 'Diamond')
                // Лестница в подсказке — от самой выгодной цены к самой дорогой.
                ->where('defects.data.0.reference_price.ladder.0.name', 'Diamond')
                ->where('defects.data.0.reference_price.ladder.1.name', 'Gold')
                ->where('defects.data.0.reference_price.ladder.2.name', 'Bronze')
                ->etc()
            );
    }

    #[Test]
    public function reference_price_uses_most_common_price_inside_status(): void
    {
        $statuses = $this->statuses();
        $product = Product::factory()->create();

        // Персональный договор одного клиента не должен сдвигать ориентир статуса.
        $this->price($this->client($statuses['diamond']), $product, 700);
        $this->price($this->client($statuses['diamond']), $product, 700);
        $this->price($this->client($statuses['diamond']), $product, 300);

        $this->assertSame(700.0, app(DefectReferencePriceService::class)->priceFor($product->id));
    }

    #[Test]
    public function defect_without_client_prices_has_no_reference(): void
    {
        $this->statuses();
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defects.data.0.id', $defect->id)
                ->where('defects.data.0.reference_price', null)
                ->etc()
            );
    }

    #[Test]
    public function bulk_sets_prices_from_reference_with_discount(): void
    {
        $statuses = $this->statuses();
        $buyer = $this->buyer();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $this->price($this->client($statuses['diamond']), $productA, 1000);
        $this->price($this->client($statuses['diamond']), $productB, 500);

        $first = ProductDefect::factory()->create(['product_id' => $productA->id]);
        $second = ProductDefect::factory()->priced(999)->create(['product_id' => $productB->id]);

        $this->actingAs($buyer)
            ->post('/admin/defects/prices/bulk', [
                'ids' => [$first->id, $second->id],
                'discount_percent' => 20,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('800.00', $first->fresh()->price);
        $this->assertSame('400.00', $second->fresh()->price);
        $this->assertSame($buyer->id, $first->fresh()->priced_by);
    }

    #[Test]
    public function bulk_without_discount_sets_reference_price_as_is(): void
    {
        $statuses = $this->statuses();
        $product = Product::factory()->create();

        $this->price($this->client($statuses['gold']), $product, 1234.56);

        $defect = ProductDefect::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->buyer())
            ->post('/admin/defects/prices/bulk', ['ids' => [$defect->id]])
            ->assertRedirect();

        $this->assertSame('1234.56', $defect->fresh()->price);
    }

    #[Test]
    public function bulk_skips_closed_defects_and_products_without_reference(): void
    {
        $statuses = $this->statuses();
        $product = Product::factory()->create();

        $this->price($this->client($statuses['diamond']), $product, 1000);

        $closed = ProductDefect::factory()->priced(500)->closed()->create(['product_id' => $product->id]);
        $noReference = ProductDefect::factory()->create();

        $this->actingAs($this->buyer())
            ->post('/admin/defects/prices/bulk', [
                'ids' => [$closed->id, $noReference->id],
                'discount_percent' => 10,
            ])
            ->assertSessionHas('error');

        $this->assertSame('500.00', $closed->fresh()->price);
        $this->assertNull($noReference->fresh()->price);
    }

    #[Test]
    public function bulk_rejects_discount_out_of_range(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->buyer())
            ->post('/admin/defects/prices/bulk', [
                'ids' => [$defect->id],
                'discount_percent' => 150,
            ])
            ->assertSessionHasErrors('discount_percent');
    }

    #[Test]
    public function user_without_price_permission_cannot_bulk_price(): void
    {
        $role = Role::firstOrCreate(['name' => 'defects-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'defects.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        $defect = ProductDefect::factory()->create();

        $this->actingAs($user)
            ->post('/admin/defects/prices/bulk', ['ids' => [$defect->id]])
            ->assertForbidden();

        $this->assertNull($defect->fresh()->price);
    }
}
