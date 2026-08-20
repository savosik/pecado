<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал недоборов: когда строка отменилась и кто её отменил.
 *
 * Отдельной таблицы-лога нет намеренно: строка заказа и есть запись журнала.
 * 1С присылает состав документа целиком при каждом `order.updated`, и копия
 * строки в отдельной таблице разъезжалась бы с оригиналом при первой же
 * правке количества.
 *
 * `cancelled_at` — момент, когда сайт увидел отмену (приём сообщения шины),
 * а не время операции в 1С: в протоколе времени отмены строки нет.
 *
 * `cancel_source` заполняет менеджер: причину 1С не передаёт, а косвенный
 * признак (расходный ордер по заказу) даёт только подсказку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('cancelled')
                ->comment('Когда сайт принял отмену строки из 1С. NULL у неотменённых строк и у отмен, случившихся до ведения журнала');
            $table->string('cancel_source', 20)->nullable()->after('cancelled_at')
                ->comment("Кто отменил строку: 'warehouse' — склад при сборке (закрытие расходного ордера), 'client' — отказ клиента. NULL — менеджер ещё не разметил");
            $table->foreignId('cancel_source_user_id')->nullable()->after('cancel_source')
                ->comment('Сотрудник, поставивший метку источника отмены (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_source_at')->nullable()->after('cancel_source_user_id')
                ->comment('Когда поставлена метка источника отмены');
            $table->string('cancel_note', 500)->nullable()->after('cancel_source_at')
                ->comment('Комментарий менеджера к отмене: подробности со склада или причина отказа клиента');

            // Журнал листается по дате отмены и фильтруется по метке.
            $table->index(['cancelled', 'cancelled_at'], 'order_items_cancelled_at_index');
            $table->index('cancel_source', 'order_items_cancel_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_cancelled_at_index');
            $table->dropIndex('order_items_cancel_source_index');
            $table->dropConstrainedForeignId('cancel_source_user_id');
            $table->dropColumn(['cancelled_at', 'cancel_source', 'cancel_source_at', 'cancel_note']);
        });
    }
};
