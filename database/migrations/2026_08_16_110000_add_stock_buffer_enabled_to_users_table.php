<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галочка «страховой запас» на клиенте (эпик buf-00, карточка buf-02).
 *
 * Клиентам с включённым флагом показываются заниженные остатки по рисковым
 * товарам (product_stock_buffers). Включает только менеджер руками в CRM:
 * автоматики по анкете нет намеренно — business_type может быть неточным.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('stock_buffer_enabled')->default(false)
                ->comment('Страховой запас: клиенту показываются заниженные остатки по рисковым товарам (product_stock_buffers); включает менеджер в CRM, применяется при STOCK_BUFFER_ENABLED');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stock_buffer_enabled');
        });
    }
};
