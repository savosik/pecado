<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\YmlPreset;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Партнёрские атрибуты (`is_partner_only = true`) изолированы от стандартных
 * пресетов, но остаются доступны в кастомных выгрузках по слугу.
 *
 * Why: без этого флага атрибут вроде `partner_group_code` с данными из 1С
 * sex-opt уходил в YML/Shopify/Google Merchant выгрузки всех остальных
 * клиентов — это бесполезный шум.
 */
class PartnerOnlyAttributesIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_only_attribute_is_skipped_in_yml_preset(): void
    {
        // Берём заведённый миграцией атрибут partner_group_title (is_partner_only=1)
        // и обычный атрибут, который мы создаём специально
        $partnerAttr = Attribute::where('slug', 'partner_group_title')->first();
        $this->assertNotNull($partnerAttr);
        $this->assertTrue((bool) $partnerAttr->is_partner_only);

        $regularAttr = Attribute::create([
            'name' => 'Цвет (тест)',
            'slug' => 'test-color',
            'type' => 'string',
            'is_active' => true,
            'show_on_site' => true,
            'show_in_export' => true,
            'is_partner_only' => false,
        ]);

        $product = Product::factory()->create(['name' => 'Тест YML', 'code' => 'YML-001', 'sku' => 'YML-1']);
        DB::table('product_attribute_values')->insert([
            [
                'product_id' => $product->id,
                'attribute_id' => $partnerAttr->id,
                'text_value' => 'СЕКРЕТ_ПАРТНЁРА',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $product->id,
                'attribute_id' => $regularAttr->id,
                'text_value' => 'Красный',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Сгенерируем YML
        $export = (new ProductExport)->forceFill([
            'preset' => 'yml',
            'format' => 'xml',
            'filters' => [],
            'fields' => [],
        ]);
        $preset = app(YmlPreset::class);

        $tmp = tempnam(sys_get_temp_dir(), 'yml_').'.xml';
        $stream = fopen($tmp, 'w');
        $preset->writeToStream($stream, $export);
        fclose($stream);
        $content = file_get_contents($tmp);
        unlink($tmp);

        // Обычный атрибут — должен быть, партнёрский — нет.
        $this->assertStringContainsString('Красный', $content, 'Обычный атрибут должен попасть в YML');
        $this->assertStringNotContainsString('СЕКРЕТ_ПАРТНЁРА', $content, 'Партнёрский атрибут не должен попасть в YML');
    }

    public function test_partner_only_attribute_still_available_in_custom_export(): void
    {
        // В кастомной выгрузке тот же partner_group_code, если клиент его явно
        // выбрал по слугу — выводится. is_partner_only фильтрует только пресеты.
        $product = Product::factory()->create(['code' => 'CUSTOM-001']);
        $attr = Attribute::where('slug', 'partner_group_title')->first();
        DB::table('product_attribute_values')->insert([
            'product_id' => $product->id,
            'attribute_id' => $attr->id,
            'text_value' => 'Эта группа партнёра',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ProductExportService::class);
        $export = (new ProductExport)->forceFill([
            'fields' => [['key' => 'attribute.partner_group_title', 'label' => 'group_title']],
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $product->id]],
        ]);

        $row = $service->fetchData($export, 1)->first();
        $this->assertSame('Эта группа партнёра', $row['attribute.partner_group_title']);
    }

    public function test_all_seeded_partner_attributes_have_flag(): void
    {
        $slugs = [
            'partner_group_code',
            'partner_group_title',
            'partner_brand_code',
            'partner_retail_price',
            'partner_embed3d',
            'partner_category_code',
        ];
        foreach ($slugs as $slug) {
            $attr = Attribute::where('slug', $slug)->first();
            $this->assertNotNull($attr, "Атрибут {$slug} должен быть создан миграцией");
            $this->assertTrue((bool) $attr->is_partner_only, "{$slug} должен иметь is_partner_only=true");
        }
    }
}
