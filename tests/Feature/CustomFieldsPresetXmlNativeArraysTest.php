<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Models\Certificate;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регресс на XML-вывод multi-value полей в CustomFieldsPreset.
 *
 * Раньше XML отдавал `<barcodes>111, 222</barcodes>` — склеенную строку.
 * После рефакторинга должен отдавать `<barcodes><item>111</item><item>222</item></barcodes>`,
 * а для объектов (warehouse {id, name, quantity}) — вложенные элементы.
 */
class CustomFieldsPresetXmlNativeArraysTest extends TestCase
{
    use RefreshDatabase;

    protected function runExport(ProductExport $export): string
    {
        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    public function test_xml_emits_item_elements_for_barcodes(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $product->barcodes()->create(['barcode' => '111']);
        $product->barcodes()->create(['barcode' => '222']);
        $product->barcodes()->create(['barcode' => '333']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'XML barcodes test',
            'format' => ExportFormat::XML,
            'fields' => [
                ['key' => 'id', 'label' => 'id'],
                ['key' => 'barcodes.barcode', 'label' => 'barcodes'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $xml = simplexml_load_string($this->runExport($export));
        $this->assertNotFalse($xml);

        $barcodesNode = $xml->product->barcodes_barcode;
        $this->assertTrue(isset($barcodesNode->item), 'Должны быть <item> элементы внутри barcodes');
        $items = [];
        foreach ($barcodesNode->item as $item) {
            $items[] = (string) $item;
        }
        $this->assertEqualsCanonicalizing(['111', '222', '333'], $items);
    }

    public function test_xml_emits_object_for_warehouses_quantity(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $msk = Warehouse::factory()->create(['name' => 'Москва Основной']);
        $product->warehouses()->attach([$msk->id => ['quantity' => 5]]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'XML warehouse object test',
            'format' => ExportFormat::XML,
            'fields' => [
                ['key' => 'warehouses.pivot.quantity', 'label' => 'warehouses_stocks'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $xml = simplexml_load_string($this->runExport($export));
        $this->assertNotFalse($xml);

        $stocksNode = $xml->product->warehouses_pivot_quantity;
        $this->assertTrue(isset($stocksNode->item));
        $this->assertSame((string) $msk->id, (string) $stocksNode->item->id);
        $this->assertSame('Москва Основной', (string) $stocksNode->item->name);
        $this->assertSame('5', (string) $stocksNode->item->quantity);
    }

    public function test_xml_emits_string_array_for_certificates(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $cert = Certificate::create(['name' => 'Сертификат А', 'type' => 'conformity']);
        $product->certificates()->attach([$cert->id]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'XML certificates test',
            'format' => ExportFormat::XML,
            'fields' => [
                ['key' => 'certificates.name', 'label' => 'certificates'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $xml = simplexml_load_string($this->runExport($export));
        $this->assertNotFalse($xml);

        $node = $xml->product->certificates_name;
        $this->assertTrue(isset($node->item));
        $this->assertSame('Сертификат А', (string) $node->item);
    }

    public function test_xml_scalar_fields_remain_simple(): void
    {
        // Backward-compat: скалярные поля остаются как <name>value</name>, не как <name><item>...</item></name>
        $user = User::factory()->create();
        Product::factory()->create(['name' => 'Тестовый товар']);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'XML scalar test',
            'format' => ExportFormat::XML,
            'fields' => [
                ['key' => 'name', 'label' => 'name'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $xml = simplexml_load_string($this->runExport($export));
        $this->assertNotFalse($xml);
        $this->assertSame('Тестовый товар', (string) $xml->product->name);
        $this->assertFalse(isset($xml->product->name->item), 'Скаляр не должен оборачиваться в <item>');
    }
}
