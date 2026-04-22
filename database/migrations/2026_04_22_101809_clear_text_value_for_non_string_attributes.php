<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Чистим "мусорные" text_value, попавшие от ERP-импортов для boolean/number/date-time атрибутов:
        // ранее text_value писался через (string)$valueLabel, что давало "1"/"" для boolean и мешало UI.
        $nonStringAttributeIds = DB::table('attributes')
            ->whereIn('type', ['boolean', 'number', 'date-time'])
            ->pluck('id');

        if ($nonStringAttributeIds->isEmpty()) {
            return;
        }

        DB::table('product_attribute_values')
            ->whereIn('attribute_id', $nonStringAttributeIds)
            ->whereNotNull('text_value')
            ->update(['text_value' => null]);
    }

    public function down(): void
    {
        // Откатить невозможно — исходные «грязные» значения не восстановимы.
    }
};
