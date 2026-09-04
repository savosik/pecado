<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Балл сортировки каталога «По умолчанию».
 *
 * Считается по расписанию (`catalog:rebuild-sort-scores`) из выручки и охвата
 * клиентов за скользящее окно. Наличие в балл НЕ зашивается: полка «в наличии
 * выше предзаказа выше остального» применяется в момент запроса по живым
 * остаткам — иначе товар, приехавший на склад после ночного пересчёта, до
 * следующей ночи висел бы внизу выдачи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sort_score', 9, 4)->default(0)->after('turnover')
                ->comment('Балл сортировки каталога «по умолчанию», 0–1000: выручка и охват клиентов за окно (catalog:rebuild-sort-scores). Чем выше, тем выше товар в списке');
            $table->timestamp('sort_score_updated_at')->nullable()->after('sort_score')
                ->comment('Когда балл сортировки пересчитан последний раз');

            $table->index('sort_score', 'products_sort_score_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_sort_score_index');
            $table->dropColumn(['sort_score', 'sort_score_updated_at']);
        });
    }
};
