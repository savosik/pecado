<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
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
            'event' => 'product.created',
            'message_id' => 'msg-prod-001',
            'uuid' => 'prod-uuid-v3-001',
            'name' => 'Тестовый товар',
            'code' => 'ТСТ-001',
            'sku' => 'ART001',
            'category_uuid' => 'cat-uuid-001',
            'brand' => 'TestBrand',
            'attributes' => [
                [
                    'property_uuid' => 'prop-color-uuid',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => 'val-red-uuid',
                    'value_label' => 'Красный',
                ],
                [
                    'property_uuid' => 'prop-size-uuid',
                    'property_label' => 'Размер',
                    'value_type' => 'string',
                    'value_uuid' => 'val-128gb-uuid',
                    'value_label' => '128GB',
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
            'event' => 'product.created',
            'message_id' => 'msg-prod-002',
            'uuid' => 'prod-uuid-v3-002',
            'name' => 'Товар со скалярным атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-material-uuid',
                    'property_label' => 'Материал',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Алюминий',
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
    public function skips_attribute_with_empty_value_uuid_and_null_value_label(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-empty-attr-001',
            'uuid' => 'prod-uuid-empty-attr-001',
            'name' => 'Товар с пустым атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-empty-uuid',
                    'property_label' => 'Пустой атрибут',
                    'value_type' => 'reference',
                    'value_uuid' => null,
                    'value_label' => null,
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-uuid-empty-attr-001')->first();
        $this->assertNotNull($product);
        $this->assertEquals(0, $product->attributeValues()->count());
    }

    #[Test]
    public function skips_attribute_with_empty_value_uuid_and_empty_string_label(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-empty-attr-002',
            'uuid' => 'prod-uuid-empty-attr-002',
            'name' => 'Товар с пустой строкой в атрибуте',
            'attributes' => [
                [
                    'property_uuid' => 'prop-empty-str-uuid',
                    'property_label' => 'Пустая строка',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => '',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-uuid-empty-attr-002')->first();
        $this->assertNotNull($product);
        $this->assertEquals(0, $product->attributeValues()->count());
    }

    #[Test]
    public function maps_value_type_reference_to_select(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-003',
            'uuid' => 'prod-uuid-v3-003',
            'name' => 'Товар с reference атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-brand-ref-uuid',
                    'property_label' => 'Бренд (ref)',
                    'value_type' => 'reference',
                    'value_uuid' => 'val-brand-samsung',
                    'value_label' => 'Samsung',
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
            'base_price' => 5000.00,
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-004',
            'uuid' => 'prod-price-test',
            'name' => 'Обновлённое название',
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
            'uuid' => 'prod-brand-obj-001',
            'name' => 'Товар с v4 брендом',
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
            'uuid' => 'prod-brand-null-001',
            'name' => 'Товар без бренда',
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
            'uuid' => 'prod-brand-dup-001',
            'name' => 'Товар 1',
            'brand' => ['uuid' => 'shared-brand-uuid', 'name' => 'Общий бренд'],
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-brand-dup-002',
            'name' => 'Товар 2',
            'brand' => ['uuid' => 'shared-brand-uuid', 'name' => 'Общий бренд'],
        ]);

        $this->assertEquals(1, \App\Models\Brand::where('external_id', 'shared-brand-uuid')->count());
    }

    #[Test]
    public function binds_attributes_to_product_category(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-bind-001']);

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-bind-001',
            'uuid' => 'prod-bind-001',
            'name' => 'Товар с привязкой атрибутов',
            'category_uuid' => 'cat-bind-001',
            'attributes' => [
                [
                    'property_uuid' => 'prop-color-bind',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => 'val-red-bind',
                    'value_label' => 'Красный',
                ],
                [
                    'property_uuid' => 'prop-size-bind',
                    'property_label' => 'Размер',
                    'value_type' => 'string',
                    'value_uuid' => 'val-m-bind',
                    'value_label' => 'M',
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
            'event' => 'product.created',
            'message_id' => 'msg-bind-002',
            'uuid' => 'prod-bind-no-cat',
            'name' => 'Товар без категории',
            'attributes' => [
                [
                    'property_uuid' => 'prop-nocat-bind',
                    'property_label' => 'Материал',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Хлопок',
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
            'uuid' => 'prod-model-dup-001',
            'name' => 'Товар 1',
            'model' => ['uuid' => 'shared-model-uuid', 'name' => 'Общая модель'],
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-model-dup-002',
            'name' => 'Товар 2',
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
            'uuid' => 'prod-model-name-001',
            'name' => 'Товар А',
            'model' => ['uuid' => 'model-uuid-old', 'name' => 'Модель X'],
        ]);

        // Второй товар с другим UUID модели, но тем же названием
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-model-name-002',
            'name' => 'Товар Б',
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
            'uuid' => 'prod-deadlock-retry-001',
            'name' => 'Товар с retry',
        ]);

        $this->assertSame(3, $attempts);
        $this->assertDatabaseHas('products', [
            'external_id' => 'prod-deadlock-retry-001',
            'name' => 'Товар с retry',
        ]);
    }

    #[Test]
    public function retries_product_created_on_duplicate_key_race(): void
    {
        // Race: параллельные воркеры могут одновременно пытаться вставить одну и ту же
        // запись (brands.slug, product_models.external_id, attribute_values.(attribute_id,value)).
        // MySQL errno 1062 должен триггерить тот же retry-механизм, что и deadlock.
        $pdoException = new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'test-brand' for key 'brands.brands_slug_unique'"
        );
        $pdoException->errorInfo = [
            '23000',
            1062,
            "Duplicate entry 'test-brand' for key 'brands.brands_slug_unique'",
        ];
        $duplicateKeyException = new QueryException(
            'mysql',
            'insert into `brands` (`slug`, `name`) values (?, ?)',
            ['test-brand', 'Test Brand'],
            $pdoException,
        );

        $handler = new class extends HandleProductCreated
        {
            public function shouldRetryPublic(\Throwable $e): bool
            {
                return $this->shouldRetryOnDeadlock($e);
            }
        };

        $this->assertTrue(
            $handler->shouldRetryPublic($duplicateKeyException),
            'Duplicate Key (1062) должен триггерить retry — на повторе SELECT найдёт чужой INSERT.'
        );
    }

    #[Test]
    public function cyrillic_named_attribute_is_active_by_default(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-attr-ru-001',
            'name' => 'Товар с русским атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-ru-uuid',
                    'property_label' => 'Длина',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => '10 см',
                ],
            ],
        ]);

        $attr = Attribute::where('external_id', 'prop-ru-uuid')->first();
        $this->assertNotNull($attr);
        $this->assertTrue($attr->is_active, 'Русский атрибут должен создаваться активным');
    }

    #[Test]
    public function english_named_attribute_is_created_inactive(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-attr-en-001',
            'name' => 'Товар со служебным английским атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-en-uuid',
                    'property_label' => 'service_internal_code',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'X-42',
                ],
            ],
        ]);

        $attr = Attribute::where('external_id', 'prop-en-uuid')->first();
        $this->assertNotNull($attr);
        $this->assertFalse($attr->is_active, 'Атрибут с латинским именем должен создаваться неактивным');
    }

    #[Test]
    public function existing_attribute_is_active_status_not_changed_on_product_created(): void
    {
        // Админ вручную активировал английский атрибут
        $attr = Attribute::create([
            'external_id' => 'prop-keep-en-uuid',
            'name' => 'legacy_code',
            'slug' => 'legacy-code',
            'type' => 'string',
            'is_active' => true,
        ]);

        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-keep-active-001',
            'name' => 'Повторный импорт',
            'attributes' => [
                [
                    'property_uuid' => 'prop-keep-en-uuid',
                    'property_label' => 'legacy_code',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Z-1',
                ],
            ],
        ]);

        $attr->refresh();
        $this->assertTrue($attr->is_active, 'Ручная активация админа не должна сбрасываться повторным импортом');
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
                'uuid' => 'prod-no-retry-001',
                'name' => 'Товар без retry',
            ]);
        } finally {
            $this->assertSame(1, $attempts);
            $this->assertDatabaseMissing('products', [
                'external_id' => 'prod-no-retry-001',
            ]);
        }
    }

    #[Test]
    public function sets_is_marked_true_when_payload_contains_true(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-marked-001',
            'uuid' => 'prod-marked-001',
            'name' => 'Маркированный товар',
            'is_marked' => true,
        ]);

        $product = Product::where('external_id', 'prod-marked-001')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->is_marked);
    }

    #[Test]
    public function sets_is_marked_false_when_payload_contains_false(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-marked-002',
            'uuid' => 'prod-marked-002',
            'name' => 'Немаркированный товар',
            'is_marked' => false,
        ]);

        $product = Product::where('external_id', 'prod-marked-002')->first();
        $this->assertNotNull($product);
        $this->assertFalse($product->is_marked);
    }

    #[Test]
    public function boolean_attribute_is_stored_in_boolean_value_only(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-bool-001',
            'uuid' => 'prod-bool-001',
            'name' => 'Товар с булевым атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-relief-uuid',
                    'property_label' => 'С рельефной поверхностью',
                    'value_type' => 'boolean',
                    'value_uuid' => null,
                    'value_label' => true,
                ],
                [
                    'property_uuid' => 'prop-stim-uuid',
                    'property_label' => 'С доп. стимуляцией',
                    'value_type' => 'boolean',
                    'value_uuid' => null,
                    'value_label' => false,
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-bool-001')->first();
        $this->assertNotNull($product);

        $reliefAttr = Attribute::where('external_id', 'prop-relief-uuid')->first();
        $stimAttr = Attribute::where('external_id', 'prop-stim-uuid')->first();
        $this->assertEquals('boolean', $reliefAttr->type);
        $this->assertEquals('boolean', $stimAttr->type);

        $reliefPav = $product->attributeValues()->where('attribute_id', $reliefAttr->id)->first();
        $stimPav = $product->attributeValues()->where('attribute_id', $stimAttr->id)->first();

        // text_value не должен заполняться для boolean — иначе UI отрисует "1" вместо "Да"
        $this->assertNull($reliefPav->text_value);
        $this->assertNull($stimPav->text_value);

        $this->assertTrue($reliefPav->boolean_value);
        $this->assertFalse($stimPav->boolean_value);

        $this->assertSame('Да', $reliefPav->getFormattedValue());
        $this->assertSame('Нет', $stimPav->getFormattedValue());
    }

    #[Test]
    public function number_attribute_does_not_pollute_text_value(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-num-001',
            'name' => 'Товар с числовым атрибутом',
            'attributes' => [
                [
                    'property_uuid' => 'prop-length-uuid',
                    'property_label' => 'Длина',
                    'value_type' => 'number',
                    'value_uuid' => null,
                    'value_label' => 13.8,
                ],
            ],
        ]);

        $attr = Attribute::where('external_id', 'prop-length-uuid')->first();
        $this->assertEquals('number', $attr->type);

        $pav = Product::where('external_id', 'prod-num-001')->first()
            ->attributeValues()->where('attribute_id', $attr->id)->first();

        $this->assertNull($pav->text_value);
        $this->assertEquals(13.8, (float) $pav->number_value);
    }

    #[Test]
    public function date_time_attribute_with_valid_iso_value_is_persisted(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-date-ok-001',
            'name' => 'Товар со сроком годности',
            'attributes' => [
                [
                    'property_uuid' => 'prop-shelf-life-uuid',
                    'property_label' => 'Срок годности',
                    'value_type' => 'date-time',
                    'value_uuid' => null,
                    'value_label' => '2027-08-15T00:00:00+03:00',
                ],
            ],
        ]);

        $attr = Attribute::where('external_id', 'prop-shelf-life-uuid')->first();
        $this->assertSame('date-time', $attr->type);

        $pav = Product::where('external_id', 'prod-date-ok-001')->first()
            ->attributeValues()->where('attribute_id', $attr->id)->first();

        $this->assertNotNull($pav->datetime_value);
        $this->assertSame('2027-08-15', $pav->datetime_value->toDateString());
        $this->assertNull($pav->text_value);
    }

    #[Test]
    public function date_time_attribute_with_1c_stub_year_is_stored_as_null(): void
    {
        // 1С шлёт 1900-01-01 (или 0001-01-01) как «незаполненный срок годности».
        // Эта дата не помещается в TIMESTAMP и до фикса роняла product.created
        // ошибкой SQLSTATE[22007]. Проверяем, что теперь она тихо пишется как NULL.
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-date-stub-001',
            'name' => 'Товар с пустым сроком годности',
            'attributes' => [
                [
                    'property_uuid' => 'prop-shelf-life-uuid',
                    'property_label' => 'Срок годности',
                    'value_type' => 'date-time',
                    'value_uuid' => null,
                    'value_label' => '1900-01-01T00:00:00+03:00',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-date-stub-001')->first();
        $this->assertNotNull($product, 'Товар должен быть создан, несмотря на стаб-дату');

        $attr = Attribute::where('external_id', 'prop-shelf-life-uuid')->first();
        $pav = $product->attributeValues()->where('attribute_id', $attr->id)->first();

        $this->assertNotNull($pav, 'pivot-запись должна создаться');
        $this->assertNull($pav->datetime_value, 'Стаб 1С должен превратиться в NULL');
        $this->assertNull($pav->text_value);
    }

    #[Test]
    public function is_marked_defaults_to_false_when_missing(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-marked-003',
            'uuid' => 'prod-marked-003',
            'name' => 'Товар без флага маркировки',
        ]);

        $product = Product::where('external_id', 'prod-marked-003')->first();
        $this->assertNotNull($product);
        $this->assertFalse($product->is_marked);
    }

    // ──────────────────────────────────────────────
    // v13: full-replace семантика атрибутов
    // ──────────────────────────────────────────────

    #[Test]
    public function full_replace_wipes_stale_attributes_on_repeated_product_created(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-full-replace-001']);

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-001',
            'uuid' => 'prod-fr-001',
            'name' => 'FR товар',
            'code' => 'FR-001',
            'sku' => 'FR-001',
            'category_uuid' => 'cat-full-replace-001',
            'attributes' => [
                [
                    'property_uuid' => 'fr-color',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Красный',
                ],
                [
                    'property_uuid' => 'fr-size',
                    'property_label' => 'Размер',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'M',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-fr-001')->first();
        $this->assertEquals(2, $product->attributeValues()->count());

        // Повторный product.created: size пропал из payload
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-002',
            'uuid' => 'prod-fr-001',
            'name' => 'FR товар',
            'code' => 'FR-001',
            'sku' => 'FR-001',
            'category_uuid' => 'cat-full-replace-001',
            'attributes' => [
                [
                    'property_uuid' => 'fr-color',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Красный',
                ],
            ],
        ]);

        $product->refresh();
        $this->assertEquals(1, $product->attributeValues()->count());
        $color = Attribute::where('external_id', 'fr-color')->first();
        $this->assertNotNull($product->attributeValues()->where('attribute_id', $color->id)->first());
    }

    #[Test]
    public function empty_attributes_array_on_product_created_wipes_all(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-fr-empty']);

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-empty-1',
            'uuid' => 'prod-fr-empty',
            'name' => 'Пусто',
            'code' => 'FR-EMP',
            'sku' => 'FR-EMP',
            'category_uuid' => 'cat-fr-empty',
            'attributes' => [
                [
                    'property_uuid' => 'fr-empty-attr',
                    'property_label' => 'Лишний',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'значение',
                ],
            ],
        ]);

        $product = Product::where('external_id', 'prod-fr-empty')->first();
        $this->assertEquals(1, $product->attributeValues()->count());

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-empty-2',
            'uuid' => 'prod-fr-empty',
            'name' => 'Пусто',
            'code' => 'FR-EMP',
            'sku' => 'FR-EMP',
            'category_uuid' => 'cat-fr-empty',
            'attributes' => [],
        ]);

        $product->refresh();
        $this->assertEquals(0, $product->attributeValues()->count());
    }

    #[Test]
    public function stores_description_html_from_payload(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-desc-html-001',
            'uuid' => 'prod-desc-html-001',
            'name' => 'Товар с HTML-описанием',
            'description' => 'Короткое описание',
            'description_html' => '<p>Подробное <strong>HTML</strong> описание</p>',
        ]);

        $product = Product::where('external_id', 'prod-desc-html-001')->first();
        $this->assertNotNull($product);
        $this->assertSame('Короткое описание', $product->description);
        $this->assertSame('<p>Подробное <strong>HTML</strong> описание</p>', $product->description_html);
    }

    #[Test]
    public function description_html_is_null_when_missing_in_payload(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-desc-html-002',
            'uuid' => 'prod-desc-html-002',
            'name' => 'Товар без HTML-описания',
        ]);

        $product = Product::where('external_id', 'prod-desc-html-002')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->description_html);
    }

    #[Test]
    public function missing_attributes_field_does_not_touch_existing_on_product_created(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-fr-missing']);

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-missing-1',
            'uuid' => 'prod-fr-missing',
            'name' => 'Сохраняем',
            'code' => 'FR-KEEP',
            'sku' => 'FR-KEEP',
            'category_uuid' => 'cat-fr-missing',
            'attributes' => [
                [
                    'property_uuid' => 'fr-keep-attr',
                    'property_label' => 'Сохранить',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'на месте',
                ],
            ],
        ]);

        // Повторный product.created без поля attributes — атрибуты трогать не должны
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-fr-missing-2',
            'uuid' => 'prod-fr-missing',
            'name' => 'Новое имя',
            'code' => 'FR-KEEP',
            'sku' => 'FR-KEEP',
            'category_uuid' => 'cat-fr-missing',
        ]);

        $product = Product::where('external_id', 'prod-fr-missing')->first();
        $this->assertEquals(1, $product->attributeValues()->count());
        $this->assertEquals('Новое имя', $product->name);
    }

    #[Test]
    public function creates_product_with_dimensions_and_classification(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-dims-001',
            'uuid' => 'prod-dims-001',
            'name' => 'Товар с габаритами',
            'code' => 'DIMS-001',
            'sku' => 'DIMS-001',
            'weight_gross' => 1.250,
            'weight_net' => 1.000,
            'width' => 32.5,
            'height' => 12.0,
            'depth' => 8.0,
            'hs_code' => '6204620000',
            'abc_xyz' => 'AX',
            'turnover' => 14.7500,
        ]);

        $product = Product::where('external_id', 'prod-dims-001')->first();
        $this->assertNotNull($product);
        $this->assertEquals('1.250', (string) $product->weight_gross);
        $this->assertEquals('1.000', (string) $product->weight_net);
        $this->assertEquals('32.50', (string) $product->width);
        $this->assertEquals('12.00', (string) $product->height);
        $this->assertEquals('8.00', (string) $product->depth);
        $this->assertEquals('6204620000', $product->hs_code);
        $this->assertEquals('AX', $product->abc_xyz);
        $this->assertEquals('14.7500', (string) $product->turnover);
    }

    #[Test]
    public function creates_product_without_dimensions_keeps_columns_null(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-no-dims-001',
            'uuid' => 'prod-no-dims-001',
            'name' => 'Товар без габаритов',
            'code' => 'NODIMS-001',
            'sku' => 'NODIMS-001',
        ]);

        $product = Product::where('external_id', 'prod-no-dims-001')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->weight_gross);
        $this->assertNull($product->weight_net);
        $this->assertNull($product->width);
        $this->assertNull($product->height);
        $this->assertNull($product->depth);
        $this->assertNull($product->hs_code);
        $this->assertNull($product->abc_xyz);
        $this->assertNull($product->turnover);
    }

    #[Test]
    public function negative_dimensions_are_normalised_to_null(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-neg-dims-001',
            'uuid' => 'prod-neg-dims-001',
            'name' => 'Товар с отрицательными габаритами',
            'code' => 'NEG-001',
            'sku' => 'NEG-001',
            'weight_gross' => -5,
            'width' => -10,
            'turnover' => -1.5,
        ]);

        $product = Product::where('external_id', 'prod-neg-dims-001')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->weight_gross);
        $this->assertNull($product->width);
        $this->assertNull($product->turnover);
    }

    #[Test]
    public function creates_attribute_value_with_long_text_label(): void
    {
        $longLabel = str_repeat('Хранить вдали от прямых солнечных лучей. ', 20); // ~800 символов

        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-long-attr-001',
            'uuid' => 'prod-long-attr-001',
            'name' => 'Товар с длинным значением атрибута',
            'attributes' => [
                [
                    'property_uuid' => 'prop-storage-uuid',
                    'property_label' => 'Условия хранения и обработки',
                    'value_type' => 'reference',
                    'value_uuid' => 'val-storage-uuid-001',
                    'value_label' => $longLabel,
                ],
            ],
        ]);

        $value = AttributeValue::where('external_id', 'val-storage-uuid-001')->first();
        $this->assertNotNull($value);
        $this->assertSame($longLabel, $value->value);
        $this->assertSame(hash('sha256', $longLabel), $value->value_hash);
    }

    #[Test]
    public function reprocessing_with_same_barcodes_is_idempotent(): void
    {
        // Идемпотентность необходима из-за повторной обработки сообщений (deadlock retry,
        // requeue из RabbitMQ). insertOrIgnore вместо upsert не должен падать на дубликате.
        $payload = [
            'event' => 'product.created',
            'uuid' => 'prod-barcodes-idem-001',
            'name' => 'Товар со штрихкодами',
            'barcodes' => ['4627173260060', '4650514400498'],
        ];

        $this->handler->handle($payload);
        $this->handler->handle($payload);

        $product = Product::where('external_id', 'prod-barcodes-idem-001')->first();
        $this->assertNotNull($product);
        $this->assertSame(2, $product->barcodes()->count());
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '4627173260060']);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '4650514400498']);
    }

    #[Test]
    public function deduplicates_barcodes_within_single_payload(): void
    {
        // 1С может прислать дубликаты в массиве — collection->unique() должен это сгладить
        // до того, как до БД дойдёт INSERT с двумя одинаковыми (product_id, barcode).
        $this->handler->handle([
            'event' => 'product.created',
            'uuid' => 'prod-barcodes-dup-001',
            'name' => 'Товар с дубликатами штрихкодов',
            'barcodes' => ['111', '222', '111', ' 111 '],
        ]);

        $product = Product::where('external_id', 'prod-barcodes-dup-001')->first();
        $this->assertNotNull($product);
        $this->assertSame(2, $product->barcodes()->count());
    }

    #[Test]
    public function saves_erp_timestamps_from_payload_v13_10(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-erp-ts-001',
            'uuid' => 'prod-erp-ts-001',
            'name' => 'Товар с аудит-метками',
            'erp_created_at' => '2024-09-15T11:42:00+03:00',
            'erp_updated_at' => '2026-04-26T08:11:09+03:00',
        ]);

        $product = Product::where('external_id', 'prod-erp-ts-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->erp_created_at);
        $this->assertNotNull($product->erp_updated_at);
        // Стенограмма даты в БД совпадает с payload в TZ Europe/Moscow.
        $this->assertEquals('2024-09-15 11:42:00', $product->erp_created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-26 08:11:09', $product->erp_updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function normalizes_erp_timestamps_to_moscow_timezone_v13_10(): void
    {
        // Любая входящая TZ нормализуется к Europe/Moscow (см. App\Casts\ErpDatetime).
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-erp-tz-utc',
            'uuid' => 'prod-erp-ts-utc',
            'name' => 'Товар TZ',
            'erp_created_at' => '2024-09-15T08:42:00Z',           // UTC = 11:42:00 MSK
            'erp_updated_at' => '2026-04-26T13:11:09+05:00',      // = 11:11:09 MSK
        ]);

        $product = Product::where('external_id', 'prod-erp-ts-utc')->first();
        $this->assertNotNull($product);
        $this->assertEquals('2024-09-15 11:42:00', $product->erp_created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-26 11:11:09', $product->erp_updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function leaves_erp_timestamps_null_when_absent_from_payload_v13_10(): void
    {
        $this->handler->handle([
            'event' => 'product.created',
            'message_id' => 'msg-prod-erp-ts-002',
            'uuid' => 'prod-erp-ts-002',
            'name' => 'Товар без аудит-меток',
        ]);

        $product = Product::where('external_id', 'prod-erp-ts-002')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->erp_created_at);
        $this->assertNull($product->erp_updated_at);
    }
}
