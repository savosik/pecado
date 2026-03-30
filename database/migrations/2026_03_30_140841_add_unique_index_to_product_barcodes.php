<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем дубликаты — оставляем запись с наименьшим id
        // Использует подзапрос совместимый и с MySQL и с SQLite
        DB::statement('
            DELETE FROM product_barcodes
            WHERE id NOT IN (
                SELECT min_id FROM (
                    SELECT MIN(id) as min_id
                    FROM product_barcodes
                    GROUP BY product_id, barcode
                ) as t
            )
        ');

        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->unique(['product_id', 'barcode'], 'product_barcodes_product_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            // MySQL не позволяет дропнуть unique index пока на нём висит FK
            // Сначала дропаем FK, затем индекс, затем восстанавливаем FK
            $table->dropForeign(['product_id']);
            $table->dropUnique('product_barcodes_product_barcode_unique');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }
};
