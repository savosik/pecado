<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * v7.1: INT-based individual_prices вместо UUID CHAR(36).
     * UUID→INT lookup выполняется при импорте в ProcessIndividualPricesFile.
     * Это даёт 7-10x ускорение INSERT и 3-5x ускорение SELECT.
     */
    public function up(): void
    {
        Schema::create('individual_prices', function (Blueprint $table) {
            $table->unsignedInteger('partner_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('price', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['partner_id', 'product_id', 'warehouse_id'], 'individual_prices_pk');
            // Нет дополнительных индексов — composite PK покрывает запрос
            // WHERE partner_id = ? AND product_id IN (...)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('individual_prices');
    }
};
