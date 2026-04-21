<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Erp\Handlers\HandleProductUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleProductUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleProductUpdated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandleProductUpdated::class);
    }

    #[Test]
    public function updates_only_name_when_only_name_provided(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-001',
            'name' => 'Старое название',
            'sku' => 'OLD-SKU',
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-001',
            'uuid' => 'partial-upd-001',
            'name' => 'Новое название',
        ]);

        $product->refresh();
        $this->assertEquals('Новое название', $product->name);
        $this->assertEquals('OLD-SKU', $product->sku); // Не затронут
    }

    #[Test]
    public function does_not_touch_base_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-002',
            'base_price' => 9999.00,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-002',
            'uuid' => 'partial-upd-002',
            'name' => 'Другое название',
        ]);

        $product->refresh();
        $this->assertEquals(9999.00, (float) $product->base_price);
    }

    #[Test]
    public function updates_attributes_partially(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-003',
        ]);

        // Сначала добавляем старый атрибут
        $oldAttr = Attribute::create([
            'external_id' => 'old-prop-uuid',
            'name' => 'Старый',
            'slug' => 'staryj',
            'type' => 'string',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $oldAttr->id,
            'text_value' => 'старое значение',
        ]);

        $this->assertEquals(1, $product->attributeValues()->count());

        // Обновляем атрибуты через product.updated
        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-003',
            'uuid' => 'partial-upd-003',
            'attributes' => [
                [
                    'property_uuid' => 'new-prop-uuid',
                    'property_label' => 'Новый',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'новое значение',
                ],
            ],
        ]);

        // v4: мерж — старый атрибут сохраняется, новый добавляется
        $this->assertEquals(2, $product->attributeValues()->count());

        $newAttr = Attribute::where('external_id', 'new-prop-uuid')->first();
        $this->assertNotNull($newAttr);

        // Проверяем что новый атрибут добавлен
        $newPav = $product->attributeValues()->where('attribute_id', $newAttr->id)->first();
        $this->assertNotNull($newPav);
        $this->assertEquals('новое значение', $newPav->text_value);

        // Старый атрибут тоже на месте
        $oldPav = $product->attributeValues()->where('attribute_id', $oldAttr->id)->first();
        $this->assertNotNull($oldPav);
        $this->assertEquals('старое значение', $oldPav->text_value);
    }

    #[Test]
    public function does_not_touch_attributes_when_not_in_payload(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-004',
        ]);

        $attr = Attribute::create([
            'external_id' => 'keep-prop-uuid',
            'name' => 'Сохранить',
            'slug' => 'sohranit',
            'type' => 'string',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'text_value' => 'оставить',
        ]);

        // Обновляем только name (без attributes)
        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-004',
            'uuid' => 'partial-upd-004',
            'name' => 'Только имя',
        ]);

        // Атрибуты должны остаться
        $this->assertEquals(1, $product->attributeValues()->count());
        $this->assertEquals('оставить', $product->attributeValues()->first()->text_value);
    }

    #[Test]
    public function warns_when_product_not_found(): void
    {
        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-005',
            'uuid' => 'nonexistent-product',
            'name' => 'Не найден',
        ]);

        // Товар не должен быть создан
        $this->assertEquals(0, Product::count());
    }

    #[Test]
    public function updates_barcodes_when_provided(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-006',
        ]);

        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'OLD-BARCODE',
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'message_id' => 'msg-upd-006',
            'uuid' => 'partial-upd-006',
            'barcodes' => ['NEW-BARCODE-1', 'NEW-BARCODE-2'],
        ]);

        $this->assertEquals(2, $product->barcodes()->count());
        $this->assertDatabaseMissing('product_barcodes', ['barcode' => 'OLD-BARCODE']);
        $this->assertDatabaseHas('product_barcodes', ['barcode' => 'NEW-BARCODE-1']);
    }

    // ──────────────────────────────────────────────
    // US-13 v4: brand как объект {uuid, name}
    // ──────────────────────────────────────────────

    #[Test]
    public function updates_brand_from_object_format(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'brand-obj-upd-001',
            'brand_id' => null,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'brand-obj-upd-001',
            'brand' => ['uuid' => 'brand-uuid-v4', 'name' => 'Бренд V4'],
        ]);

        $product->refresh();
        $this->assertNotNull($product->brand_id);

        $brand = Brand::find($product->brand_id);
        $this->assertEquals('brand-uuid-v4', $brand->external_id);
        $this->assertEquals('Бренд V4', $brand->name);
    }

    #[Test]
    public function does_not_duplicate_brand_on_repeated_update(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'brand-obj-upd-002',
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'brand-obj-upd-002',
            'brand' => ['uuid' => 'same-brand-uuid', 'name' => 'Тот же бренд'],
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'brand-obj-upd-002',
            'brand' => ['uuid' => 'same-brand-uuid', 'name' => 'Тот же бренд обновлённый'],
        ]);

        $this->assertEquals(1, Brand::where('external_id', 'same-brand-uuid')->count());
        $brand = Brand::where('external_id', 'same-brand-uuid')->first();
        $this->assertEquals('Тот же бренд обновлённый', $brand->name);
    }

    #[Test]
    public function sets_null_brand_when_brand_is_null(): void
    {
        $brand = Brand::create(['name' => 'Удаляемый', 'slug' => 'udalyaemiy']);
        $product = Product::factory()->create([
            'external_id' => 'brand-null-upd',
            'brand_id' => $brand->id,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'brand-null-upd',
            'brand' => null,
        ]);

        $product->refresh();
        $this->assertNull($product->brand_id);
    }

    // ──────────────────────────────────────────────
    // US-13 v4: мерж — обновление существующего атрибута
    // ──────────────────────────────────────────────

    #[Test]
    public function updates_existing_attribute_value_via_merge(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'attr-merge-upd-001',
        ]);

        $attr = Attribute::create([
            'external_id' => 'merge-prop-uuid',
            'name' => 'Цвет',
            'slug' => 'tsvet',
            'type' => 'string',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'text_value' => 'Красный',
        ]);

        // Обновляем тот же атрибут через product.updated
        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'attr-merge-upd-001',
            'attributes' => [
                [
                    'property_uuid' => 'merge-prop-uuid',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => null,
                    'value_label' => 'Синий',
                ],
            ],
        ]);

        // Только 1 атрибут (обновлён, не добавлен дубль)
        $this->assertEquals(1, $product->attributeValues()->count());
        $pav = $product->attributeValues()->first();
        $this->assertEquals('Синий', $pav->text_value);
    }

    #[Test]
    public function binds_attributes_to_product_category_on_update(): void
    {
        $category = Category::factory()->create(['uuid' => 'cat-upd-bind-001']);
        $product = Product::factory()->create([
            'external_id' => 'prod-upd-bind-001',
            'category_id' => $category->id,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'prod-upd-bind-001',
            'attributes' => [
                [
                    'property_uuid' => 'prop-color-upd-bind',
                    'property_label' => 'Цвет',
                    'value_type' => 'string',
                    'value_uuid' => 'val-blue-upd-bind',
                    'value_label' => 'Синий',
                ],
            ],
        ]);

        $category->refresh();
        $this->assertEquals(1, $category->attributes()->count());

        $colorAttr = Attribute::where('external_id', 'prop-color-upd-bind')->first();
        $this->assertTrue($category->attributes->contains($colorAttr));
    }

    #[Test]
    public function updates_is_marked_when_field_present(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'prod-upd-marked-001',
            'is_marked' => false,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'prod-upd-marked-001',
            'is_marked' => true,
        ]);

        $product->refresh();
        $this->assertTrue($product->is_marked);
    }

    #[Test]
    public function clears_is_marked_when_field_false(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'prod-upd-marked-002',
            'is_marked' => true,
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'prod-upd-marked-002',
            'is_marked' => false,
        ]);

        $product->refresh();
        $this->assertFalse($product->is_marked);
    }

    #[Test]
    public function preserves_is_marked_when_field_absent_from_payload(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'prod-upd-marked-003',
            'is_marked' => true,
            'name' => 'Прежнее название',
        ]);

        $this->handler->handle([
            'event' => 'product.updated',
            'uuid' => 'prod-upd-marked-003',
            'name' => 'Новое название',
        ]);

        $product->refresh();
        $this->assertEquals('Новое название', $product->name);
        $this->assertTrue($product->is_marked);
    }
}
