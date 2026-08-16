<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автор ручной пометки страхового буфера (buf-06).
 *
 * «Кто и когда придержал 2 шт» через полгода спросят обязательно —
 * WMS-консоль показывает автора рядом с пометкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_buffers', function (Blueprint $table) {
            $table->foreignId('manual_set_by')->nullable()
                ->comment('Кто поставил ручную пометку manual_qty (users.id); NULL — пометки нет')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('manual_set_at')->nullable()
                ->comment('Когда поставлена ручная пометка manual_qty; NULL — пометки нет');
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_buffers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_set_by');
            $table->dropColumn('manual_set_at');
        });
    }
};
