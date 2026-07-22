<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Уценка в корзине: третий тип позиции.
 *
 * Корзина уже делится на instock/preorder (spillover в CartService), теперь добавляется
 * defect — позиция ссылается на конкретную партию некондиции, а не просто на товар,
 * потому что у одного артикула бывает несколько партий с разными дефектами и ценами.
 *
 * enum расширяется через change(), а не ALTER: на MySQL это ENUM, на SQLite (тесты) —
 * varchar с CHECK-констрейнтом; расширить нужно оба.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_defect_id')->nullable()->after('product_id')
                ->comment('Партия некондиции (product_defects.id); заполнено только при item_type = defect')
                ->constrained('product_defects')->cascadeOnDelete();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->enum('item_type', ['instock', 'preorder', 'defect'])
                ->default('instock')
                ->comment("Тип позиции: 'instock' — со склада, 'preorder' — предзаказ, 'defect' — уценка")
                ->change();
        });

        // В уникальный ключ добавляем партию: одного товара в корзине может быть
        // несколько уценённых позиций с разными дефектами.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_unique');
            $table->unique(
                ['cart_id', 'product_id', 'item_type', 'warehouse_id', 'product_defect_id'],
                'cart_items_unique'
            );
        });
    }

    public function down(): void
    {
        // Позиции уценки не переживают откат: без партии они бессмысленны,
        // и сужение enum на них упадёт.
        DB::table('cart_items')->where('item_type', 'defect')->delete();

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_unique');
            $table->unique(['cart_id', 'product_id', 'item_type', 'warehouse_id'], 'cart_items_unique');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_defect_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->enum('item_type', ['instock', 'preorder'])
                ->default('instock')
                ->comment("Тип позиции: 'instock' — со склада, 'preorder' — предзаказ")
                ->change();
        });
    }
};
