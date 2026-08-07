<?php

namespace Tests\Feature\Products;

use App\Console\Commands\BiSyncGrants;
use App\Models\ApiToken;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\FieldRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Себестоимость (US-18, v15.13.0) — коммерческая тайна.
 *
 * Товар сериализуется целиком в десятках мест, поэтому основной барьер —
 * Product::$hidden. Тесты фиксируют, что барьер стоит на всех каналах,
 * по которым товар уходит наружу, и что в админке поле открывается только
 * по праву product-costs.view.
 */
class ProductCostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function productWithCost(): Product
    {
        $product = Product::factory()->create([
            'external_id' => 'cost-visibility-uuid',
            'base_price' => 12500.50,
        ]);

        $product->cost_price = 8450.00;
        $product->cost_price_updated_at = now();
        $product->save();

        return $product->refresh();
    }

    #[Test]
    public function cost_is_not_serialized_by_default(): void
    {
        $product = $this->productWithCost();

        $array = $product->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('cost_price_updated_at', $array);
        $this->assertStringNotContainsString('cost_price', $product->toJson());
    }

    #[Test]
    public function cost_is_readable_in_code_despite_being_hidden(): void
    {
        // $hidden прячет поле только при сериализации — обработчики и отчёты
        // должны читать его как обычно.
        $product = $this->productWithCost();

        $this->assertEquals(8450.00, (float) $product->cost_price);
    }

    #[Test]
    public function cost_can_be_opened_explicitly(): void
    {
        $product = $this->productWithCost();

        $array = $product->makeCostVisible()->toArray();

        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('cost_price_updated_at', $array);
    }

    #[Test]
    public function hiding_cost_does_not_break_searchability(): void
    {
        // Регрессия: штатный $hidden столкнулся бы с колонкой products.hidden
        // и обнулил бы shouldBeSearchable() для всех товаров разом.
        $product = $this->productWithCost();

        $this->assertTrue($product->shouldBeSearchable());
    }

    #[Test]
    public function cost_is_not_in_search_index(): void
    {
        $product = $this->productWithCost();

        $this->assertArrayNotHasKey('cost_price', $product->toSearchableArray());
    }

    #[Test]
    public function cost_is_not_mass_assignable(): void
    {
        // Себестоимости нет в $fillable: админская форма не должна её выставлять.
        $product = Product::factory()->create();

        $product->update(['cost_price' => 777.00]);

        $this->assertNull($product->fresh()->cost_price);
    }

    #[Test]
    public function cost_is_not_an_export_field(): void
    {
        $keys = app(FieldRegistry::class)->all()->map(fn ($field) => $field->key())->all();

        foreach ($keys as $key) {
            $this->assertStringNotContainsString('cost', (string) $key);
        }
    }

    #[Test]
    public function cost_is_not_exposed_by_client_api(): void
    {
        $product = $this->productWithCost();

        $user = User::factory()->create(['erp_id' => 'partner-cost-uuid']);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/client-api/{$token->token}/prices");

        $response->assertOk();
        $this->assertStringNotContainsString('cost_price', $response->getContent());
        $this->assertStringNotContainsString('8450', $response->getContent());
        $this->assertSame($product->external_id, $response->json('data.0.uuid'));
    }

    #[Test]
    public function cost_columns_are_confidential_for_bi_agent(): void
    {
        // BI-агентом пользуются рядовые менеджеры: колонка обязана вырезаться вьюхой,
        // иначе себестоимость утечёт мимо права product-costs.view.
        $confidential = (new \ReflectionClass(BiSyncGrants::class))
            ->getConstant('CONFIDENTIAL_COLUMNS');

        $this->assertContains('products.cost_price', $confidential);
        $this->assertContains('products.cost_price_updated_at', $confidential);
        $this->assertContains('shipment_items.cost_price_snapshot', $confidential);
    }

    #[Test]
    public function admin_with_permission_sees_cost_on_product_page(): void
    {
        $product = $this->productWithCost();

        $response = $this->actingAs($this->adminWithCostPermission())
            ->get(route('admin.products.show', $product->id));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('can_view_cost', true)
                ->where('product.cost_price', '8450.00')
        );
    }

    #[Test]
    public function admin_without_permission_does_not_see_cost(): void
    {
        $product = $this->productWithCost();

        $response = $this->actingAs($this->adminWithoutCostPermission())
            ->get(route('admin.products.show', $product->id));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->where('can_view_cost', false)
                ->where('product.cost_price', null)
        );
        $this->assertStringNotContainsString('8450', $response->getContent());
    }

    private function adminWithCostPermission(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user->fresh();
    }

    private function adminWithoutCostPermission(): User
    {
        // Каталоговед ведёт карточки товаров, но себестоимость ему не положена.
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('catalogist');

        return $user->fresh();
    }
}
