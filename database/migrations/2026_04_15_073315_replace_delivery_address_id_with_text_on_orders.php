<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Добавить текстовое поле delivery_address
        Schema::table('orders', function (Blueprint $table) {
            $table->text('delivery_address')->nullable()->after('delivery_address_id');
        });

        // 2. Мигрировать данные из delivery_addresses
        DB::statement('
            UPDATE orders
            SET delivery_address = (
                SELECT address FROM delivery_addresses
                WHERE delivery_addresses.id = orders.delivery_address_id
            )
            WHERE delivery_address_id IS NOT NULL
        ');

        // 3. Удалить FK и колонку delivery_address_id
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_address_id']);
            $table->dropColumn('delivery_address_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_address_id')->nullable()->after('company_id')
                ->constrained('delivery_addresses')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_address');
        });
    }
};
