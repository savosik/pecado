<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Архив журнала недоборов: строка выведена из работы без разметки.
 *
 * Нужен для «обнуления» журнала: на момент запуска в нём лежала вся история
 * отмен, разбирать которую задним числом никто не будет. Удалять такие строки
 * нельзя — это позиции заказов, клиент видит их отменёнными; обнулять
 * `cancelled_at` тоже нельзя, иначе пропадут сводки по повторяющимся товарам.
 *
 * Поэтому третье состояние: отмена есть, метки нет, но и в работе она не числится.
 * Журнал и счётчик такие строки по умолчанию не показывают, фильтр «Архив» —
 * показывает; сводки по товарам и партнёрам их учитывают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('cancel_archived_at')->nullable()->after('cancel_note')
                ->comment('Отмена выведена из работы без разметки (массовая архивация журнала). NULL — строка в работе либо размечена');

            $table->index(['cancel_archived_at'], 'order_items_cancel_archived_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_cancel_archived_index');
            $table->dropColumn('cancel_archived_at');
        });
    }
};
