<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('individual_prices', function (Blueprint $table) {
            $table->char('partner_uuid', 36);
            $table->char('product_uuid', 36);
            $table->char('warehouse_uuid', 36);
            $table->decimal('price', 15, 2);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['partner_uuid', 'product_uuid', 'warehouse_uuid'], 'individual_prices_pk');
            $table->index('partner_uuid', 'idx_individual_prices_partner');
            $table->index('product_uuid', 'idx_individual_prices_product');
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
