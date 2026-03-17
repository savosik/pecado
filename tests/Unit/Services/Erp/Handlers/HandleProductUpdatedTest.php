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
            'name'        => 'Старое название',
            'sku'         => 'OLD-SKU',
        ]);

        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-001',
            'uuid'       => 'partial-upd-001',
            'name'       => 'Новое название',
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
            'base_price'  => 9999.00,
        ]);

        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-002',
            'uuid'       => 'partial-upd-002',
            'name'       => 'Другое название',
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
            'name'        => 'Старый',
            'slug'        => 'staryj',
            'type'        => 'string',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $oldAttr->id,
            'text_value'   => 'старое значение',
        ]);

        $this->assertEquals(1, $product->attributeValues()->count());

        // Обновляем атрибуты через product.updated
        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-003',
            'uuid'       => 'partial-upd-003',
            'attributes' => [
                [
                    'property_uuid'  => 'new-prop-uuid',
                    'property_label' => 'Новый',
                    'value_type'     => 'string',
                    'value_uuid'     => null,
                    'value_label'    => 'новое значение',
                ],
            ],
        ]);

        // Старый атрибут удалён, новый добавлен (полная замена)
        $this->assertEquals(1, $product->attributeValues()->count());
        $pav = $product->attributeValues()->first();
        $this->assertEquals('новое значение', $pav->text_value);

        $newAttr = Attribute::where('external_id', 'new-prop-uuid')->first();
        $this->assertNotNull($newAttr);
    }

    #[Test]
    public function does_not_touch_attributes_when_not_in_payload(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'partial-upd-004',
        ]);

        $attr = Attribute::create([
            'external_id' => 'keep-prop-uuid',
            'name'        => 'Сохранить',
            'slug'        => 'sohranit',
            'type'        => 'string',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'text_value'   => 'оставить',
        ]);

        // Обновляем только name (без attributes)
        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-004',
            'uuid'       => 'partial-upd-004',
            'name'       => 'Только имя',
        ]);

        // Атрибуты должны остаться
        $this->assertEquals(1, $product->attributeValues()->count());
        $this->assertEquals('оставить', $product->attributeValues()->first()->text_value);
    }

    #[Test]
    public function warns_when_product_not_found(): void
    {
        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-005',
            'uuid'       => 'nonexistent-product',
            'name'       => 'Не найден',
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
            'barcode'    => 'OLD-BARCODE',
        ]);

        $this->handler->handle([
            'event'      => 'product.updated',
            'message_id' => 'msg-upd-006',
            'uuid'       => 'partial-upd-006',
            'barcodes'   => ['NEW-BARCODE-1', 'NEW-BARCODE-2'],
        ]);

        $this->assertEquals(2, $product->barcodes()->count());
        $this->assertDatabaseMissing('product_barcodes', ['barcode' => 'OLD-BARCODE']);
        $this->assertDatabaseHas('product_barcodes', ['barcode' => 'NEW-BARCODE-1']);
    }
}
