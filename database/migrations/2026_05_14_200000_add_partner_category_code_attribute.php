<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Дополнительный «партнёрский» атрибут под коды категорий из 1С поставщика.
 *
 * У Vakulov значение category_code иногда обрезанный UUID
 * (`ab0bf883-4286-11`), иногда числовое (`503`) — это их внутренний код
 * категории, не совпадающий ни с нашим `category.id`, ни с
 * `category.external_id` обрезанным. Единственный надёжный способ дать
 * клиенту 1-в-1 — импортировать значение в атрибут.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('attributes')->where('slug', 'partner_category_code')->exists()) {
            return;
        }
        $groupId = DB::table('attribute_groups')->where('name', 'Партнёрские данные')->value('id');
        if (! $groupId) {
            // Если предыдущая миграция не отработала по какой-то причине — создаём группу
            $groupId = DB::table('attribute_groups')->insertGetId([
                'name' => 'Партнёрские данные',
                'sort_order' => 9999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('attributes')->insert([
            'external_id' => Str::uuid()->toString(),
            'name' => 'Код категории поставщика',
            'slug' => 'partner_category_code',
            'type' => 'string',
            'unit' => null,
            'is_filterable' => false,
            'is_active' => true,
            'show_on_site' => false,
            'show_in_export' => true,
            'is_variant_forming' => false,
            'sort_order' => 9005,
            'attribute_group_id' => $groupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('attributes')->where('slug', 'partner_category_code')->delete();
    }
};
