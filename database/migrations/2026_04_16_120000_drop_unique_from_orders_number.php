<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1С при retry может генерировать новый uuid для того же заказа,
     * тогда INSERT с тем же number падает на unique constraint.
     * Снимаем unique — идемпотентность обеспечивается через upsert по uuid.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unique('number');
        });
    }
};
