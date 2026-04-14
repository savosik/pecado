<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Добавляет currency_id к regions и заполняет RUB для всех существующих регионов.
     */
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('currency_id')
                ->nullable()
                ->after('name')
                ->constrained('currencies')
                ->nullOnDelete();
        });

        // Заполнить RUB для всех существующих регионов
        $rubId = DB::table('currencies')->where('code', 'RUB')->value('id');
        if ($rubId) {
            DB::table('regions')->whereNull('currency_id')->update(['currency_id' => $rubId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
