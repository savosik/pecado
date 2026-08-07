<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * График оплаты реализации (US-18, протокол v15.12.0).
 *
 * Табличная часть «Правила оплаты» документа 1С: плановые даты и суммы платежей.
 * Приезжает массивом `payment_schedule` внутри shipment.created / shipment.updated —
 * отдельной очереди намеренно нет, график является частью самого документа.
 *
 * Связь через `shipment_id` с настоящим FK, а не через `shipment_uuid`, как
 * в `payment_allocations`. Там uuid был источником правды, потому что платежи
 * и реализации идут разными очередями без гарантии порядка и строка могла
 * приехать раньше документа. Здесь график приезжает внутри документа — строк-сирот
 * не бывает by design.
 *
 * Пары «код + наименование» (basis / basis_name, stage / stage_name) разведены
 * намеренно: логика опирается только на латинский код, потому что русское
 * наименование в 1С меняется вместе с настройками учёта. Тот же мотив, что
 * у `payments.operation_code` в US-17.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_payment_schedules', function (Blueprint $table) {
            $table->comment('График оплаты реализации: плановые даты и суммы платежей из табличной части «Правила оплаты» 1С');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('shipment_id')->comment('Реализация (shipments.id). При удалении реализации график удаляется вместе с ней')
                ->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number')->nullable()->comment('Номер строки в табличной части «Правила оплаты» 1С — сохраняет порядок показа и разрешает ничью при одинаковых датах');
            $table->date('due_date')->comment('Плановая дата платежа. Обязательна: строка без даты в календарь не попадает и сайтом не сохраняется');
            $table->decimal('amount', 14, 2)->default(0)->comment('Сумма платежа по строке в валюте реализации');
            $table->decimal('paid_amount', 14, 2)->default(0)->comment('ДЕНОРМАЛИЗАЦИЯ: сколько из суммы строки уже закрыто платежами. Раскладка FIFO по due_date; пишет только PaymentScheduleService');
            $table->decimal('percent', 8, 4)->nullable()->comment('Доля платежа от суммы документа («% платежа»). Справочно — расчёт идёт по amount');
            $table->unsignedSmallInteger('term_days')->nullable()->comment('Отсрочка в днях («Срок (дни)»). Справочно — плановая дата уже посчитана в 1С');
            $table->string('basis', 32)->nullable()->comment("Код варианта отсчёта отсрочки: 'shipment_date' — от даты отгрузки, 'order_date' — от даты заказа, 'invoice_date' — от даты счёта");
            $table->string('basis_name')->nullable()->comment('Наименование варианта отсчёта как в 1С («от даты отгрузки»). Только для показа, в логике не участвует');
            $table->string('stage', 32)->nullable()->comment("Код этапа оплаты: 'prepayment' — предоплата до отгрузки, 'advance' — аванс, 'credit' — оплата после отгрузки");
            $table->string('stage_name')->nullable()->comment('Наименование этапа оплаты как в 1С («Оплата после отгрузки»). Только для показа');
            $table->char('order_uuid', 36)->nullable()->index()->comment('UUID заказа клиента из строки графика (1С). Мягкая связь без FK — заказ мог не приехать на сайт');
            $table->timestamp('created_at')->nullable()->comment('Дата и время создания строки на сайте');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения строки (например, пересчёт погашенной суммы)');

            // Календарь всегда выбирает по диапазону дат и джойнит реализации.
            $table->index(['due_date', 'shipment_id'], 'shipment_payment_schedules_due_shipment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_payment_schedules');
    }
};
