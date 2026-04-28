<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('brand_name_snapshot')->nullable()->after('name')
                ->comment('Имя бренда товара на момент создания строки. Используется для fuzzy-поиска без JOIN.');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable()->after('product_id')
                ->comment('Имя товара на момент создания возврата. Сохраняется при удалении товара из каталога.');
            $table->string('brand_name_snapshot')->nullable()->after('product_name_snapshot')
                ->comment('Имя бренда товара на момент создания возврата.');
        });

        Schema::table('shipment_items', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable()->after('product_id')
                ->comment('Имя товара на момент создания строки реализации.');
            $table->string('brand_name_snapshot')->nullable()->after('product_name_snapshot')
                ->comment('Имя бренда товара на момент создания строки реализации.');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('brand_name_snapshot');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->dropColumn(['product_name_snapshot', 'brand_name_snapshot']);
        });

        Schema::table('shipment_items', function (Blueprint $table) {
            $table->dropColumn(['product_name_snapshot', 'brand_name_snapshot']);
        });
    }
};
