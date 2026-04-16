<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Erp\Handlers\HandleProductCreated;
use Illuminate\Database\QueryException;
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

    #[Test]
    public function binds_attributes_to_product_category(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-bind-001']);

        $this->handler->handle([
            'event'         => 'product.created',
            'message_id'    => 'msg-bind-001',
            'uuid'          => 'prod-bind-001',
            'name'          => 'Товар с привязкой атрибутов',
            'category_uuid' => 'cat-bind-001',
            'attributes'    => [
                [
                    'property_uuid'  => 'prop-color-bind',
                    'property_label' => 'Цвет',
                    'value_type'     => 'string',
                    'value_uuid'     => 'val-red-bind',
                    'value_label'    => 'Красный',
                ],
                [
                    'property_uuid'  => 'prop-size-bind',
                    'property_label' => 'Размер',
                    'value_type'     => 'string',
                    'value_uuid'     => 'val-m-bind',
                    'value_label'    => 'M',
                ],
            ],
        ]);

        // Проверяем привязку атрибутов к категории
        $category->refresh();
        $this->assertEquals(2, $category->attributes()->count());

        $colorAttr = Attribute::where('external_id', 'prop-color-bind')->first();
        $sizeAttr = Attribute::where('external_id', 'prop-size-bind')->first();

        $this->assertTrue($category->attributes->contains($colorAttr));
        $this->assertTrue($category->attributes->contains($sizeAttr));
    }

    #[Test]
    public function does_not_bind_attributes_when_no_category(): void
    {
        $this->handler->handle([
            'event'      => 'product.created',
            'message_id' => 'msg-bind-002',
            'uuid'       => 'prod-bind-no-cat',
            'name'       => 'Товар без категории',
            'attributes' => [
                [
                    'property_uuid'  => 'prop-nocat-bind',
                    'property_label' => 'Материал',
                    'value_type'     => 'string',
                    'value_uuid'     => null,
                    'value_label'    => 'Хлопок',
                ],
            ],
        ]);

        // Нет связей в attribute_category
        $this->assertEquals(0, \Illuminate\Support\Facades\DB::table('attribute_category')->count());
    }

    // ──────────────────────────────────────────────
    // Дедупликация моделей товаров
    // ──────────────────────────────────────────────

    #[Test]
    public function does_not_duplicate_model_on_repeated_product_created(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-model-dup-001',
            'name'  => 'Товар 1',
            'model' => ['uuid' => 'shared-model-uuid', 'name' => 'Общая модель'],
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-model-dup-002',
            'name'  => 'Товар 2',
            'model' => ['uuid' => 'shared-model-uuid', 'name' => 'Общая модель'],
        ]);

        // Одна модель
        $this->assertEquals(1, \App\Models\ProductModel::count());

        // Оба товара привязаны к одной модели
        $p1 = Product::where('external_id', 'prod-model-dup-001')->first();
        $p2 = Product::where('external_id', 'prod-model-dup-002')->first();
        $this->assertEquals($p1->model_id, $p2->model_id);
    }

    #[Test]
    public function resolves_model_by_name_when_external_id_differs(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-model-name-001',
            'name'  => 'Товар А',
            'model' => ['uuid' => 'model-uuid-old', 'name' => 'Модель X'],
        ]);

        // Второй товар с другим UUID модели, но тем же названием
        $this->handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-model-name-002',
            'name'  => 'Товар Б',
            'model' => ['uuid' => 'model-uuid-new', 'name' => 'Модель X'],
        ]);

        // Должна быть одна модель, а не две
        $this->assertEquals(1, \App\Models\ProductModel::count());

        // external_id обновлён на последний UUID (1С — мастер)
        $model = \App\Models\ProductModel::first();
        $this->assertEquals('model-uuid-new', $model->external_id);
        $this->assertEquals('Модель X', $model->name);

        // Оба товара привязаны к одной модели
        $p1 = Product::where('external_id', 'prod-model-name-001')->first();
        $p2 = Product::where('external_id', 'prod-model-name-002')->first();
        $this->assertEquals($p1->model_id, $p2->model_id);
    }

    #[Test]
    public function retries_product_created_on_deadlock_and_finishes_on_third_attempt(): void
    {
        $handler = \Mockery::mock(HandleProductCreated::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $attempts = 0;

        $handler->shouldReceive('runInTransaction')
            ->times(3)
            ->andReturnUsing(function (callable $callback) use (&$attempts): void {
                $attempts++;

                if ($attempts < 3) {
                    throw new QueryException(
                        'update `attribute_values` set `external_id` = ? where `id` = ?',
                        [],
                        new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', '40001')
                    );
                }

                $callback();
            });

        $handler->shouldReceive('shouldRetryOnDeadlock')
            ->times(2)
            ->andReturnTrue();

        $handler->shouldReceive('processPayload')
            ->passthru();

        $handler->handle([
            'event' => 'product.created',
            'uuid'  => 'prod-deadlock-retry-001',
            'name'  => 'Товар с retry',
        ]);

        $this->assertSame(3, $attempts);
        $this->assertDatabaseHas('products', [
            'external_id' => 'prod-deadlock-retry-001',
            'name' => 'Товар с retry',
        ]);
    }

    #[Test]
    public function does_not_retry_product_created_on_non_deadlock_error(): void
    {
        $handler = \Mockery::mock(HandleProductCreated::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $attempts = 0;

        $handler->shouldReceive('runInTransaction')
            ->once()
            ->andReturnUsing(function () use (&$attempts): void {
                $attempts++;

                throw new \RuntimeException('Обычная ошибка обработки');
            });

        $handler->shouldReceive('shouldRetryOnDeadlock')
            ->once()
            ->andReturnFalse();

        $handler->shouldReceive('processPayload')
            ->passthru();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Обычная ошибка обработки');

        try {
            $handler->handle([
                'event' => 'product.created',
                'uuid'  => 'prod-no-retry-001',
                'name'  => 'Товар без retry',
            ]);
        } finally {
            $this->assertSame(1, $attempts);
            $this->assertDatabaseMissing('products', [
                'external_id' => 'prod-no-retry-001',
            ]);
        }
    }
}
