<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Числовые модификаторы конструктора выгрузки: умножение и сложение для
 * полей-цен (`price`) и обычных числовых полей (`numeric`).
 *
 * Порядок операций: (значение × multiply) + add.
 * Округление: int-входы остаются int, float — до 2 знаков.
 */
class ProductExportNumericModifierTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('super-admin');
    }

    protected function service(): ProductExportService
    {
        return app(ProductExportService::class);
    }

    protected function buildExport(int $productId, array $field): ProductExport
    {
        return (new ProductExport)->forceFill([
            'fields' => [$field],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $productId]],
            'client_user_id' => null,
        ]);
    }

    // ─── price ──────────────────────────────────────

    public function test_price_multiply_applies_after_value(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'base_price',
                'modifiers' => ['multiply' => '1.2'],
            ]),
            1
        )->first();

        $this->assertSame(1200.0, $row['base_price']);
    }

    public function test_price_add_applies_after_multiply(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'base_price',
                'modifiers' => ['multiply' => '1.2', 'add' => '50'],
            ]),
            1
        )->first();

        // (1000 × 1.2) + 50 = 1250
        $this->assertSame(1250.0, $row['base_price']);
    }

    public function test_price_add_only_works_without_multiply(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'base_price',
                'modifiers' => ['add' => '99.5'],
            ]),
            1
        )->first();

        $this->assertSame(1099.5, $row['base_price']);
    }

    public function test_price_currency_and_arithmetic_combine(): void
    {
        $product = Product::factory()->create(['base_price' => 9000]);

        // Создаём неосновную валюту с обменом 1 USD = 90 RUB ⇒ 9000 / 90 = 100 USD,
        // далее × 1.5 + 5 = 155.
        $usd = \App\Models\Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_base' => false,
            'official_rate' => 90,
            'rate_coefficient' => 1,
            'exchange_rate' => 90,
            'exchange_rate_date' => now(),
        ]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'base_price',
                'modifiers' => ['currency_id' => $usd->id, 'multiply' => '1.5', 'add' => '5'],
            ]),
            1
        )->first();

        $this->assertSame(155.0, $row['base_price']);
    }

    public function test_price_no_modifiers_returns_raw_value(): void
    {
        $product = Product::factory()->create(['base_price' => 1234]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, ['key' => 'base_price']),
            1
        )->first();

        // Без модификаторов цена возвращается как есть (string из decimal-каста).
        $this->assertEquals(1234, $row['base_price']);
    }

    // ─── numeric ────────────────────────────────────

    public function test_numeric_int_stays_int_after_multiply(): void
    {
        // total_stock возвращает int (sum() по integer-колонке pivot.quantity).
        // × 0.5 от 100 = 50 (int), не 50.0.
        $product = Product::factory()->create();
        $warehouse = \App\Models\Warehouse::factory()->create();
        $product->warehouses()->attach($warehouse->id, ['quantity' => 100]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'total_stock',
                'modifiers' => ['multiply' => '0.5'],
            ]),
            1
        )->first();

        $this->assertSame(50, $row['total_stock']);
    }

    public function test_numeric_int_rounds_to_int_when_result_is_fractional(): void
    {
        // 100 × 0.333 = 33.3 → должно округлиться до 33 (целое).
        $product = Product::factory()->create();
        $warehouse = \App\Models\Warehouse::factory()->create();
        $product->warehouses()->attach($warehouse->id, ['quantity' => 100]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'total_stock',
                'modifiers' => ['multiply' => '0.333'],
            ]),
            1
        )->first();

        $this->assertSame(33, $row['total_stock']);
    }

    public function test_numeric_float_rounds_to_two_decimals(): void
    {
        // weight_gross — decimal(10,3) → строка/float. × 1.5 от 100.5 = 150.75.
        $product = Product::factory()->create(['weight_gross' => 100.5]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'weight_gross',
                'modifiers' => ['multiply' => '1.5'],
            ]),
            1
        )->first();

        $this->assertSame(150.75, $row['weight_gross']);
    }

    public function test_numeric_add_works_on_total_stock(): void
    {
        $product = Product::factory()->create();
        $warehouse = \App\Models\Warehouse::factory()->create();
        $product->warehouses()->attach($warehouse->id, ['quantity' => 10]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'total_stock',
                'modifiers' => ['add' => '5'],
            ]),
            1
        )->first();

        $this->assertSame(15, $row['total_stock']);
    }

    public function test_numeric_empty_modifiers_dont_change_value(): void
    {
        $product = Product::factory()->create();
        $warehouse = \App\Models\Warehouse::factory()->create();
        $product->warehouses()->attach($warehouse->id, ['quantity' => 200]);

        $row = $this->service()->fetchData(
            $this->buildExport($product->id, [
                'key' => 'total_stock',
                'modifiers' => ['multiply' => '', 'add' => null],
            ]),
            1
        )->first();

        $this->assertSame(200, $row['total_stock']);
    }

    // ─── HTTP / TrimStrings ─────────────────────────

    public function test_arithmetic_modifiers_survive_http_preview(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.product-exports.preview'), [
                'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
                'fields' => [[
                    'key' => 'base_price',
                    'label' => 'Цена с наценкой',
                    'modifiers' => ['multiply' => '1.2', 'add' => '10'],
                ]],
                'client_user_id' => null,
            ]);

        $response->assertOk();
        // (1000 × 1.2) + 10 = 1210. JSON-decode целое число отдаст как int.
        $this->assertEquals(1210, $response->json('data.0.base_price'));
    }

    public function test_invalid_multiply_value_is_rejected_by_validation(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.product-exports.preview'), [
                'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
                'fields' => [[
                    'key' => 'base_price',
                    'modifiers' => ['multiply' => 'abc'],
                ]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fields.0.modifiers.multiply']);
    }
}
