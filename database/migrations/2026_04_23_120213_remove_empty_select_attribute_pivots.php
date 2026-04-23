<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Чистка «мусорных» pivot-записей в product_attribute_values для select-атрибутов.
     *
     * Источник проблемы: до фикса в HandleProductCreated/Updated handler писал запись
     * (attribute_value_id = NULL, text_value = '', *_value = NULL) для атрибутов
     * type='select', когда payload приходил с пустыми value_uuid и value_label. Такие
     * строки попадали в inline-цикл CatalogFacetService::computeAttributeFacets и ломали
     * сортировку (Undefined array key "raw_value", приводило к 500 на /api/catalog/products/facets).
     */
    public function up(): void
    {
        // Используем subquery (а не DELETE … JOIN), чтобы SQL работал и в MySQL,
        // и в SQLite (тестовая среда поднимает миграции на :memory:).
        DB::table('product_attribute_values')
            ->whereIn('attribute_id', function ($query) {
                $query->select('id')
                    ->from('attributes')
                    ->where('type', 'select');
            })
            ->whereNull('attribute_value_id')
            ->where(function ($query) {
                $query->whereNull('text_value')->orWhere('text_value', '');
            })
            ->whereNull('number_value')
            ->whereNull('boolean_value')
            ->whereNull('datetime_value')
            ->delete();
    }

    public function down(): void
    {
        // No-op: восстанавливать заведомо мусорные pivot-записи бессмысленно.
    }
};
