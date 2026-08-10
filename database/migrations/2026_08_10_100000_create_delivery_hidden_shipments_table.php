<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реализации, убранные складом из списка кандидатов на отправку.
 *
 * Список кандидатов длинный и вечный: реализация из 1С никуда не девается, даже
 * если её увезли самовывозом, отдали курьером или она вообще не про доставку.
 * Без ручного «убрать» такие строки навсегда остаются перед глазами и мешают
 * найти нужное.
 *
 * Это признак интерфейса, а не документа: сама реализация не меняется, и скрытие
 * в любой момент снимается. Отсюда отдельная таблица, а не колонка в `shipments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_hidden_shipments', function (Blueprint $table) {
            $table->comment('Реализации, скрытые складом из списка кандидатов на отправку');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('shipment_id')
                ->comment('Скрытая реализация (shipments.id)')
                ->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('hidden_by')->nullable()
                ->comment('Кто скрыл (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable()
                ->comment('Почему скрыта — необязательная пометка для коллег');

            $table->timestamp('created_at')->nullable()->comment('Когда скрыли');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи');

            // Скрытие общее для всей смены: склад работает одним списком,
            // и «у меня скрыто, а у сменщика нет» разошлось бы с реальностью.
            $table->unique('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_hidden_shipments');
    }
};
