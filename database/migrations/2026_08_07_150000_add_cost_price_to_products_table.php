<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-18 (v15.13.0): себестоимость товара из 1С.
 *
 * Приходит событием cost.updated в очередь erp_in.prices. Значение конфиденциальное:
 * скрыто от клиента через Product::$hidden и от BI-агента через вьюху v_products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->nullable()->after('base_price')
                ->comment('Себестоимость из 1С в рублях. Конфиденциально: не отдаётся клиенту');
            $table->timestamp('cost_price_updated_at')->nullable()->after('cost_price')
                ->comment('Когда себестоимость обновлена событием cost.updated');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'cost_price_updated_at']);
        });
    }
};
