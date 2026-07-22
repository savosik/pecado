<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылка позиции заказа на партию некондиции.
 *
 * Нужна для двух вещей: резерв (партия считается занятой, пока есть неудалённый
 * заказ уценки на неё) и список «К отгрузке» в WMS, где кладовщик видит, какой
 * именно дефектный экземпляр отдать по заказу.
 *
 * defect_description — снапшот текста на момент заказа: партию могут отредактировать
 * позже, а в заказе должно остаться то, что видел клиент.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_defect_id')->nullable()->after('product_id')
                ->comment('Партия некондиции (product_defects.id); заполнено только у заказов type = defect')
                ->constrained('product_defects')->nullOnDelete();
            $table->text('defect_description')->nullable()->after('product_defect_id')
                ->comment('Описание дефекта на момент оформления заказа — снапшот, партия могла измениться позже');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_defect_id');
            $table->dropColumn('defect_description');
        });
    }
};
