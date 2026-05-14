<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\Warehouse;
use App\Services\ProductExport\FieldRegistry;
use App\Services\ProductExport\Fields\BrandExternalIdField;
use App\Services\ProductExport\Fields\CategoryExternalIdField;
use App\Services\ProductExport\Fields\CategoryIdField;
use App\Services\ProductExport\Fields\EmptyPlaceholderField;
use App\Services\ProductExport\Fields\ImageByPositionField;
use App\Services\ProductExport\Fields\WarehouseQuantityField;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поля и модификаторы, добавленные под партнёрские выгрузки в формате внешних
 * систем (sex-opt и т.п.): отдельные колонки по складам/изображениям,
 * UUID бренда, числовой/UUID id категории, плейсхолдеры для «дыр» в шапке.
 */
class ProductExportPartnerFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_external_id_field_returns_uuid(): void
    {
        $brand = Brand::factory()->create(['external_id' => '56a40edc-47ca-11e5-9e15-001e6711ed1c']);
        $product = Product::factory()->create(['brand_id' => $brand->id]);

        $field = new BrandExternalIdField;
        $value = $field->getValue($product->load('brand'));

        $this->assertSame('56a40edc-47ca-11e5-9e15-001e6711ed1c', $value);
    }

    public function test_category_id_and_external_id_fields(): void
    {
        $category = Category::factory()->create([
            'external_id' => '11111111-2222-3333-4444-555555555555',
        ]);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->assertSame($category->id, (new CategoryIdField)->getValue($product->load('category')));
        $this->assertSame(
            '11111111-2222-3333-4444-555555555555',
            (new CategoryExternalIdField)->getValue($product->load('category'))
        );
    }

    public function test_warehouse_quantity_field_resolves_per_warehouse(): void
    {
        $msk = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $tmn = Warehouse::factory()->create(['name' => 'Тюмень Основной']);

        $product = Product::factory()->create();
        $product->warehouses()->attach([
            $msk->id => ['quantity' => 7],
            $tmn->id => ['quantity' => 12],
        ]);

        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);
        $mskField = $registry->resolve("warehouse.{$msk->id}.quantity");
        $tmnField = $registry->resolve("warehouse.{$tmn->id}.quantity");

        $this->assertInstanceOf(WarehouseQuantityField::class, $mskField);
        $this->assertInstanceOf(WarehouseQuantityField::class, $tmnField);

        $product = $product->fresh(['warehouses']);
        $this->assertSame(7, $mskField->getValue($product));
        $this->assertSame(12, $tmnField->getValue($product));
    }

    public function test_warehouse_quantity_returns_zero_when_no_pivot(): void
    {
        $msk = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $product = Product::factory()->create();

        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);
        $field = $registry->resolve("warehouse.{$msk->id}.quantity");

        $this->assertSame(0, $field->getValue($product->load('warehouses')));
    }

    public function test_image_by_position_field_is_virtual_and_resolves_any_index(): void
    {
        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);

        $f0 = $registry->resolve('image.0');
        $f1 = $registry->resolve('image.1');
        $f5 = $registry->resolve('image.5');

        $this->assertInstanceOf(ImageByPositionField::class, $f0);
        $this->assertInstanceOf(ImageByPositionField::class, $f1);
        $this->assertInstanceOf(ImageByPositionField::class, $f5);

        $this->assertSame('image.0', $f0->key());
        $this->assertSame('image.5', $f5->key());

        // Без медиа — все позиции возвращают null
        $product = Product::factory()->create();
        $this->assertNull($f0->getValue($product));
        $this->assertNull($f5->getValue($product));
    }

    public function test_placeholder_field_is_virtual_and_returns_empty_string(): void
    {
        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);

        $field = $registry->resolve('placeholder.brand_code');
        $this->assertInstanceOf(EmptyPlaceholderField::class, $field);
        $this->assertSame('placeholder.brand_code', $field->key());

        $product = Product::factory()->create();
        $this->assertSame('', $field->getValue($product));
    }

    public function test_unique_placeholder_keys_give_separate_columns_in_export(): void
    {
        $product = Product::factory()->create(['name' => 'Тест']);

        /** @var ProductExportService $service */
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'name', 'label' => 'title'],
                ['key' => 'placeholder.brand_code', 'label' => 'brand_code'],
                ['key' => 'placeholder.fixed_price', 'label' => 'fixed_price'],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();

        $this->assertSame('Тест', $row['name']);
        $this->assertSame('', $row['placeholder.brand_code']);
        $this->assertSame('', $row['placeholder.fixed_price']);
    }

    public function test_dynamic_warehouse_fields_are_listed_in_available_fields(): void
    {
        $msk = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $tmn = Warehouse::factory()->create(['name' => 'Тюмень Основной']);

        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);
        $groups = $registry->getAvailableFields();

        $stockGroup = collect($groups)->firstWhere('group', 'Складские остатки');
        $this->assertNotNull($stockGroup);

        $keys = collect($stockGroup['fields'])->pluck('key')->all();
        $this->assertContains("warehouse.{$msk->id}.quantity", $keys);
        $this->assertContains("warehouse.{$tmn->id}.quantity", $keys);
    }
}
