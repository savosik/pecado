<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->boolean('is_variant_forming')->default(false)->after('show_in_export');
            $table->index('is_variant_forming');
        });

        $presetNames = [
            'Аромат',
            'Вкус',
            'Цвет',
            'Размер',
            'Объём',
            'Объем',
            'Материал',
        ];

        DB::table('attributes')
            ->whereIn('name', $presetNames)
            ->update(['is_variant_forming' => true]);
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropIndex(['is_variant_forming']);
            $table->dropColumn('is_variant_forming');
        });
    }
};
