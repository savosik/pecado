<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг `is_partner_only` — атрибут содержит данные, импортированные из
 * внешней системы (1С партнёра, sex-opt-выгрузка и т.п.) и предназначенные
 * для конкретного партнёрского формата выгрузки.
 *
 * Поведение:
 *  - в кастомных выгрузках (CustomFieldsPreset) — атрибут доступен через
 *    `attribute.{slug}` как обычно, если клиент его выбрал;
 *  - в стандартных пресетах (YML, Shopify, WooCommerce, Google, Tilda и т.д.)
 *    — атрибут НЕ попадает в attributes-список, чтобы не засорять чужие
 *    выгрузки бесполезными данными из чужой 1С;
 *  - на сайте и в фильтрах — управляется отдельными флагами
 *    `show_on_site` / `is_filterable`.
 *
 * Сразу проставляется true для уже созданных партнёрских атрибутов
 * (с префиксом `partner_` в slug), чтобы регрессия с их попаданием в
 * пресетные YML/Shopify-выгрузки была устранена не в два шага, а сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function ($table) {
            $table->boolean('is_partner_only')->default(false)->after('is_variant_forming');
        });

        DB::table('attributes')->where('slug', 'like', 'partner_%')->update(['is_partner_only' => true]);
    }

    public function down(): void
    {
        Schema::table('attributes', function ($table) {
            $table->dropColumn('is_partner_only');
        });
    }
};
