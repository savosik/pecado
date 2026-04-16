<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Создаёт таблицу individual_prices в отдельной prices DB с HASH-партиционированием.
 *
 * Запускается на соединении 'prices' (отдельный контейнер pecado-mysql-prices).
 * dropIfExists перед created обеспечивает идемпотентность при migrate:fresh.
 */
return new class extends Migration
{
    protected $connection = 'prices';

    public function up(): void
    {
        // dropIfExists обеспечивает идемпотентность при повторных migrate:fresh в тестах
        Schema::connection('prices')->dropIfExists('individual_prices');

        Schema::connection('prices')->create('individual_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('price', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['partner_id', 'product_id', 'warehouse_id'], 'individual_prices_pk');
            $table->index('partner_id', 'idx_individual_prices_partner');
            $table->index('product_id', 'idx_individual_prices_product');
        });

        // HASH-партиционирование по partner_id — Laravel Schema Builder не поддерживает нативно
        DB::connection('prices')->statement(
            'ALTER TABLE individual_prices PARTITION BY HASH(partner_id) PARTITIONS 64'
        );
    }

    public function down(): void
    {
        Schema::connection('prices')->dropIfExists('individual_prices');
    }
};
