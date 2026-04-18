<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заменяет idx_individual_prices_updated_at на covering-индекс (updated_at, price),
 * чтобы пагинированный запрос ORDER BY updated_at DESC не делал table lookup за price.
 *
 * Без этого индекса MySQL читал из каждой из 64 HASH-партиций по 25 строк
 * с random I/O в кластеризованный индекс для получения price.
 */
return new class extends Migration
{
    protected $connection = 'prices';

    public function up(): void
    {
        Schema::connection('prices')->table('individual_prices', function (Blueprint $table) {
            $table->index(['updated_at', 'price'], 'idx_individual_prices_updated_at_price');
        });
    }

    public function down(): void
    {
        Schema::connection('prices')->table('individual_prices', function (Blueprint $table) {
            $table->dropIndex('idx_individual_prices_updated_at_price');
        });
    }
};
