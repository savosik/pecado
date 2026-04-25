<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('sku', 'products_sku_idx');
            $table->index('code', 'products_code_idx');
            $table->index('barcode', 'products_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_sku_idx');
            $table->dropIndex('products_code_idx');
            $table->dropIndex('products_barcode_idx');
        });
    }
};
