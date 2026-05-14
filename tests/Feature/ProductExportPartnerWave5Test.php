<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductExport;
use App\Services\ProductExport\FieldRegistry;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Волна 5: substring-модификатор, дубли ключа в fields через #alias,
 * обогащение товаров через атрибуты.
 */
class ProductExportPartnerWave5Test extends TestCase
{
    use RefreshDatabase;

    public function test_substring_modifier_truncates_string_value(): void
    {
        $product = Product::factory()->create([
            'external_id' => '11111111-2222-3333-4444-555555555555',
        ]);
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                [
                    'key' => 'external_id',
                    'modifiers' => ['substring_start' => 0, 'substring_length' => 16],
                ],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame('11111111-2222-33', $row['external_id']);
    }

    public function test_duplicate_keys_via_alias_produce_separate_columns(): void
    {
        $product = Product::factory()->create(['name' => 'Тест дубля']);
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                ['key' => 'name', 'label' => 'title'],
                ['key' => 'name#again', 'label' => 'title_copy'],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame('Тест дубля', $row['name']);
        $this->assertSame('Тест дубля', $row['name#again']);
        $this->assertNotSame('name', 'name#again');
    }

    public function test_field_registry_resolve_strips_alias_suffix(): void
    {
        /** @var FieldRegistry $registry */
        $registry = app(FieldRegistry::class);
        $baseField = $registry->resolve('name');
        $aliased = $registry->resolve('name#whatever');

        $this->assertNotNull($baseField);
        // Алиас резолвится в тот же экземпляр базового поля
        $this->assertSame($baseField, $aliased);
    }

    public function test_partner_attributes_seeded_by_migration(): void
    {
        // RefreshDatabase прогоняет все миграции — атрибуты должны быть на месте
        $slugs = [
            'partner_group_code',
            'partner_group_title',
            'partner_brand_code',
            'partner_retail_price',
            'partner_embed3d',
        ];
        $count = Attribute::whereIn('slug', $slugs)->count();
        $this->assertSame(5, $count);

        $a = Attribute::where('slug', 'partner_group_code')->first();
        $this->assertTrue((bool) $a->is_active);
        $this->assertTrue((bool) $a->show_in_export);
        $this->assertFalse((bool) $a->show_on_site);
        $this->assertFalse((bool) $a->is_filterable);

        // retail_price — number
        $rrc = Attribute::where('slug', 'partner_retail_price')->first();
        $this->assertSame('number', $rrc->type);
    }

    public function test_enrich_command_writes_attribute_values(): void
    {
        $p1 = Product::factory()->create(['code' => 'TEST-001']);
        $p2 = Product::factory()->create(['code' => 'TEST-002']);

        $csv = tempnam(sys_get_temp_dir(), 'partner_').'.csv';
        file_put_contents(
            $csv,
            "code;group_code;brand_code;retail_price\n".
            "TEST-001;0T-00001;000000123;1500\n".
            "TEST-002;0T-00002;000000124;0\n".
            "TEST-MISSING;0T-00003;000000125;2000\n"
        );

        $mapping = json_encode([
            'group_code' => 'partner_group_code',
            'brand_code' => 'partner_brand_code',
            'retail_price' => 'partner_retail_price',
        ]);

        Artisan::call('partner-export:enrich-from-csv', [
            'path' => $csv,
            '--mapping' => $mapping,
        ]);

        unlink($csv);

        // Проверяем что значения попали в product_attribute_values
        $groupAttr = Attribute::where('slug', 'partner_group_code')->first();
        $brandAttr = Attribute::where('slug', 'partner_brand_code')->first();
        $rrcAttr = Attribute::where('slug', 'partner_retail_price')->first();

        $v1Group = DB::table('product_attribute_values')
            ->where('product_id', $p1->id)
            ->where('attribute_id', $groupAttr->id)
            ->value('text_value');
        $v1Brand = DB::table('product_attribute_values')
            ->where('product_id', $p1->id)
            ->where('attribute_id', $brandAttr->id)
            ->value('text_value');
        $v1Rrc = DB::table('product_attribute_values')
            ->where('product_id', $p1->id)
            ->where('attribute_id', $rrcAttr->id)
            ->value('number_value');

        $this->assertSame('0T-00001', $v1Group);
        $this->assertSame('000000123', $v1Brand);
        // SQLite в тестах возвращает int, MySQL — строку decimal — нормализуем
        $this->assertSame(1500.0, (float) $v1Rrc);

        // Товар 002 — retail_price=0, текст-поля заполнены
        $v2Group = DB::table('product_attribute_values')
            ->where('product_id', $p2->id)
            ->where('attribute_id', $groupAttr->id)
            ->value('text_value');
        $this->assertSame('0T-00002', $v2Group);

        // TEST-MISSING — товара нет, ничего не сохранено
        $output = Artisan::output();
        $this->assertStringContainsString('Без совпадения по code', $output);
    }

    public function test_enrich_command_is_idempotent(): void
    {
        $product = Product::factory()->create(['code' => 'IDEMP-001']);

        $csv = tempnam(sys_get_temp_dir(), 'partner_').'.csv';
        file_put_contents($csv, "code;group_code\nIDEMP-001;FIRST\n");

        $mapping = json_encode(['group_code' => 'partner_group_code']);

        Artisan::call('partner-export:enrich-from-csv', ['path' => $csv, '--mapping' => $mapping]);

        // Перезапускаем с другим значением
        file_put_contents($csv, "code;group_code\nIDEMP-001;SECOND\n");
        Artisan::call('partner-export:enrich-from-csv', ['path' => $csv, '--mapping' => $mapping]);
        unlink($csv);

        $attr = Attribute::where('slug', 'partner_group_code')->first();
        $value = DB::table('product_attribute_values')
            ->where('product_id', $product->id)
            ->where('attribute_id', $attr->id)
            ->value('text_value');

        // Значение перезаписалось — только одна запись
        $count = DB::table('product_attribute_values')
            ->where('product_id', $product->id)
            ->where('attribute_id', $attr->id)
            ->count();
        $this->assertSame(1, $count);
        $this->assertSame('SECOND', $value);
    }

    public function test_substring_works_for_field_without_modifier_type(): void
    {
        // external_id — текстовое поле без modifierType. substring-модификатор
        // должен работать поверх него (это пост-модификатор).
        $product = Product::factory()->create(['external_id' => 'abcdef1234567890XYZ']);
        $service = app(ProductExportService::class);

        $export = (new ProductExport)->forceFill([
            'fields' => [
                [
                    'key' => 'external_id',
                    'modifiers' => ['substring_length' => 6],
                ],
            ],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame('abcdef', $row['external_id']);
    }
}
