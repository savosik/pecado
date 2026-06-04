<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use App\Services\ProductExport\FieldRegistry;
use App\Services\ProductExport\Fields\AllAttributesField;
use App\Services\ProductExport\Fields\ModelExternalIdField;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на AllAttributesField и ModelExternalIdField.
 *
 * AllAttributesField собирает все атрибуты товара в одну колонку для
 * sex-opt-совместимого формата выгрузки. CSV: "name:value\nname:value",
 * JSON: массив объектов [{name, value}, ...]. Пропускает is_partner_only
 * атрибуты и пустые значения.
 */
class AllAttributesFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_is_registered_in_registry(): void
    {
        $registry = app(FieldRegistry::class);

        $this->assertInstanceOf(
            AllAttributesField::class,
            $registry->resolve('all_attributes')
        );
        $this->assertInstanceOf(
            ModelExternalIdField::class,
            $registry->resolve('model.external_id')
        );
    }

    public function test_csv_returns_name_value_separated_by_newline(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $cvet = Attribute::create([
            'name' => 'Цвет', 'slug' => 'cvet', 'type' => 'select', 'is_active' => true,
        ]);
        $cvetVal = AttributeValue::create([
            'attribute_id' => $cvet->id, 'value' => 'Красный',
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $cvet->id, 'attribute_value_id' => $cvetVal->id,
        ]);

        $weight = Attribute::create([
            'name' => 'Вес', 'slug' => 'ves', 'unit' => 'кг', 'type' => 'number', 'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $weight->id, 'number_value' => 0.5,
        ]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'AllAttrs CSV test',
            'format' => ExportFormat::CSV,
            'fields' => [
                ['key' => 'all_attributes', 'label' => 'attrs'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        // CSV содержит "Цвет:Красный" и "Вес, кг:0.5" разделённые \n
        $this->assertStringContainsString('Цвет:Красный', $content);
        $this->assertStringContainsString('Вес, кг:0.5', $content);
    }

    public function test_json_returns_native_object_array(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $color = Attribute::create([
            'name' => 'Цвет', 'slug' => 'cvet', 'type' => 'string', 'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $color->id, 'text_value' => 'Красный',
        ]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'AllAttrs JSON test',
            'format' => ExportFormat::JSON,
            'fields' => [
                ['key' => 'all_attributes', 'label' => 'attrs'],
            ],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];
        $this->assertIsArray($row['attrs']);
        $this->assertSame([['name' => 'Цвет', 'value' => 'Красный']], $row['attrs']);
    }

    public function test_skips_partner_only_attributes(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $partner = Attribute::create([
            'name' => 'Partner attr', 'slug' => 'partner_x', 'type' => 'string',
            'is_active' => true, 'is_partner_only' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $partner->id, 'text_value' => 'secret',
        ]);

        $normal = Attribute::create([
            'name' => 'Normal', 'slug' => 'normal', 'type' => 'string', 'is_active' => true,
        ]);
        $product->attributeValues()->create([
            'attribute_id' => $normal->id, 'text_value' => 'public',
        ]);

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Partner filter test',
            'format' => ExportFormat::JSON,
            'fields' => [['key' => 'all_attributes', 'label' => 'attrs']],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        $row = $decoded[0];
        $names = collect($row['attrs'])->pluck('name')->all();
        $this->assertContains('Normal', $names);
        $this->assertNotContains('Partner attr', $names);
    }

    public function test_returns_null_when_product_has_no_attributes(): void
    {
        $user = User::factory()->create();
        Product::factory()->create();

        $export = ProductExport::create([
            'user_id' => $user->id,
            'client_user_id' => $user->id,
            'name' => 'Empty attrs test',
            'format' => ExportFormat::JSON,
            'fields' => [['key' => 'all_attributes', 'label' => 'attrs']],
            'filters' => [],
            'is_active' => true,
        ]);

        $stream = fopen('php://memory', 'w+');
        app(CustomFieldsPreset::class)->writeToStream($stream, $export);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $decoded = json_decode($content, true);
        // attribute-ключи (с префиксом attribute.) с null отфильтровываются,
        // но 'all_attributes' не attribute-ключ. Проверим что ключ есть и значение null.
        $this->assertArrayHasKey('attrs', $decoded[0]);
        $this->assertNull($decoded[0]['attrs']);
    }

    public function test_model_external_id_field_returns_uuid(): void
    {
        $product = Product::factory()->create();
        $product->model()->associate(\App\Models\ProductModel::create([
            'name' => 'Test Model',
            'external_id' => 'aaaabbbb-1234-1234-1234-aaaabbbbcccc',
        ]));
        $product->save();

        $field = new ModelExternalIdField;
        $this->assertSame('aaaabbbb-1234-1234-1234-aaaabbbbcccc', $field->getValue($product->fresh(['model'])));
    }

    public function test_model_external_id_returns_null_when_no_model(): void
    {
        $product = Product::factory()->create();
        $field = new ModelExternalIdField;
        $this->assertNull($field->getValue($product));
    }
}
