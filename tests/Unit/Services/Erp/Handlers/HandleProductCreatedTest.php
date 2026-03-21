<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Erp\Handlers\HandleProductCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleProductCreatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleProductCreated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandleProductCreated::class);
    }

    #[Test]
    public function creates_product_with_v3_structured_attributes(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-uuid-001']);

        $this->handler->handle([
            'event'         => 'product.created',
            'message_id'    => 'msg-prod-001',
            'uuid'          => 'prod-uuid-v3-001',
            'name'          => 'Тестовый товар',
            'code'          => 'ТСТ-001',
            'sku'           => 'ART001',
            'category_uuid' => 'cat-uuid-001',
            'brand'         => 'TestBrand',
            'attributes'    => [
                [
                    'property_uuid'  => 'prop-color-uuid',
                    'property_label' => 'Цвет',
                    'value_type'     => 'string',
                    'value_uuid'     => 'val-red-uuid',
                    'value_label'    => 'Красный',
                ],
                [
                    'property_uuid'  => 'prop-size-uuid',
                    'property_label' => 'Размер',
                    'value_type'     => 'string',
                    'value_uuid'     => 'val-128gb-uuid',
                    'value_label'    => '128GB',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-uuid-v3-001')->first();
        $this->assertNotNull($product);
        $this->assertEquals('Тестовый товар', $product->name);

        // Проверяем атрибуты
        $this->assertEquals(2, $product->attributeValues()->count());

        // Проверяем что Attribute создан с external_id
        $colorAttr = Attribute::where('external_id', 'prop-color-uuid')->first();
        $this->assertNotNull($colorAttr);
        $this->assertEquals('Цвет', $colorAttr->name);
        $this->assertEquals('string', $colorAttr->type);

        // Проверяем что AttributeValue создан с external_id
        $redValue = AttributeValue::where('external_id', 'val-red-uuid')->first();
        $this->assertNotNull($redValue);
        $this->assertEquals('Красный', $redValue->value);
    }

    #[Test]
    public function handles_attribute_with_null_value_uuid(): void
    {
        $this->handler->handle([
            'event'      => 'product.created',
            'message_id' => 'msg-prod-002',
            'uuid'       => 'prod-uuid-v3-002',
            'name'       => 'Товар со скалярным атрибутом',
            'attributes' => [
                [
                    'property_uuid'  => 'prop-material-uuid',
                    'property_label' => 'Материал',
                    'value_type'     => 'string',
                    'value_uuid'     => null,
                    'value_label'    => 'Алюминий',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-uuid-v3-002')->first();
        $this->assertNotNull($product);

        $pav = $product->attributeValues()->first();
        $this->assertNotNull($pav);
        $this->assertNull($pav->attribute_value_id); // нет value_uuid → нет AttributeValue
        $this->assertEquals('Алюминий', $pav->text_value);
    }

    #[Test]
    public function maps_value_type_reference_to_select(): void
    {
        $this->handler->handle([
            'event'      => 'product.created',
            'message_id' => 'msg-prod-003',
            'uuid'       => 'prod-uuid-v3-003',
            'name'       => 'Товар с reference атрибутом',
            'attributes' => [
                [
                    'property_uuid'  => 'prop-brand-ref-uuid',
                    'property_label' => 'Бренд (ref)',
                    'value_type'     => 'reference',
                    'value_uuid'     => 'val-brand-samsung',
                    'value_label'    => 'Samsung',
                ],
            ],
        ]);

        $attr = Attribute::where('external_id', 'prop-brand-ref-uuid')->first();
        $this->assertNotNull($attr);
        $this->assertEquals('select', $attr->type);
    }

    #[Test]
    public function does_not_overwrite_base_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'prod-price-test',
            'base_price'  => 5000.00,
        ]);

        $this->handler->handle([
            'event'      => 'product.created',
            'message_id' => 'msg-prod-004',
            'uuid'       => 'prod-price-test',
            'name'       => 'Обновлённое название',
        ]);

        $product->refresh();
        $this->assertEquals(5000.00, (float) $product->base_price);
        $this->assertEquals('Обновлённое название', $product->name);
    }

    // ──────────────────────────────────────────────
    // US-13 v4: brand как объект {uuid, name}
    // ──────────────────────────────────────────────

    #[Test]
    public function creates_product_with_brand_object_format(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-brand-obj-001',
            'name'  => 'Товар с v4 брендом',
            'brand' => ['uuid' => 'brand-v4-uuid-001', 'name' => 'Nike'],
        ]);

        $product = Product::where('external_id', 'prod-brand-obj-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->brand_id);

        $brand = \App\Models\Brand::find($product->brand_id);
        $this->assertEquals('brand-v4-uuid-001', $brand->external_id);
        $this->assertEquals('Nike', $brand->name);
    }

    #[Test]
    public function creates_product_with_null_brand(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-brand-null-001',
            'name'  => 'Товар без бренда',
            'brand' => null,
        ]);

        $product = Product::where('external_id', 'prod-brand-null-001')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->brand_id);
    }

    #[Test]
    public function does_not_duplicate_brand_on_repeated_product_created(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-brand-dup-001',
            'name'  => 'Товар 1',
            'brand' => ['uuid' => 'shared-brand-uuid', 'name' => 'Общий бренд'],
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-brand-dup-002',
            'name'  => 'Товар 2',
            'brand' => ['uuid' => 'shared-brand-uuid', 'name' => 'Общий бренд'],
        ]);

        $this->assertEquals(1, \App\Models\Brand::where('external_id', 'shared-brand-uuid')->count());
    }
}
