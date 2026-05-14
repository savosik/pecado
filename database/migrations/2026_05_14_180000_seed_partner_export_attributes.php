<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Сид «партнёрских» атрибутов, которыми обогащаются товары из внешних
 * выгрузок (sex-opt и т.п.). Эти атрибуты:
 *
 *  - `is_active = true`     — учитываются в FieldRegistry и доступны в
 *                             конструкторе выгрузки как attribute.{slug};
 *  - `show_in_export = true` — выводятся в стандартных пресетах;
 *  - `show_on_site = false`  — не показываются в карточке товара на сайте;
 *  - `is_filterable = false` — не участвуют в фильтрах каталога;
 *  - `is_variant_forming = false`.
 *
 * Содержат внешние коды/значения из 1С партнёра, которые не имеют прямого
 * аналога в нашей доменной модели (Номенклатурная группа, числовой код
 * бренда, РРЦ, embed-URL 3D-просмотра).
 */
return new class extends Migration
{
    public function up(): void
    {
        $groupId = DB::table('attribute_groups')->where('name', 'Партнёрские данные')->value('id');
        if (! $groupId) {
            $groupId = DB::table('attribute_groups')->insertGetId([
                'name' => 'Партнёрские данные',
                'sort_order' => 9999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $attributes = [
            ['slug' => 'partner_group_code',    'name' => 'Группа поставщика, код',     'type' => 'string'],
            ['slug' => 'partner_group_title',   'name' => 'Группа поставщика, название', 'type' => 'string'],
            ['slug' => 'partner_brand_code',    'name' => 'Бренд поставщика, код',      'type' => 'string'],
            ['slug' => 'partner_retail_price',  'name' => 'РРЦ поставщика',             'type' => 'number'],
            ['slug' => 'partner_embed3d',       'name' => 'URL 3D-просмотра поставщика', 'type' => 'string'],
        ];

        foreach ($attributes as $i => $attr) {
            $exists = DB::table('attributes')->where('slug', $attr['slug'])->exists();
            if ($exists) {
                continue;
            }

            $data = [
                'external_id' => Str::uuid()->toString(),
                'name' => $attr['name'],
                'slug' => $attr['slug'],
                'type' => $attr['type'],
                'unit' => null,
                'is_filterable' => false,
                'is_active' => true,
                'show_on_site' => false,
                'show_in_export' => true,
                'is_variant_forming' => false,
                'sort_order' => 9000 + $i,
                'attribute_group_id' => $groupId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // is_partner_only добавляется отдельной миграцией позже —
            // ставим только если колонка уже существует (для прогона на
            // свежей БД, где обе миграции идут подряд).
            if (\Illuminate\Support\Facades\Schema::hasColumn('attributes', 'is_partner_only')) {
                $data['is_partner_only'] = true;
            }
            DB::table('attributes')->insert($data);
        }
    }

    public function down(): void
    {
        $slugs = [
            'partner_group_code',
            'partner_group_title',
            'partner_brand_code',
            'partner_retail_price',
            'partner_embed3d',
        ];
        DB::table('attributes')->whereIn('slug', $slugs)->delete();
        DB::table('attribute_groups')->where('name', 'Партнёрские данные')->delete();
    }
};
