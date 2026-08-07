<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ближайшая плановая дата платежа на реализации (US-18, протокол v15.12.0).
 *
 * Денормализация из `shipment_payment_schedules` по тем же причинам, что расписаны
 * в миграции агрегатов оплаты (2026_08_06_140200): фильтр «у кого горит оплата»
 * в журнале CRM работает по колонке таблицы, потоковый экспорт кабинета идёт через
 * cursor() и догрузить агрегат пачкой негде, а MCP-агент должен отвечать
 * «какие реализации просрочены» одним WHERE, а не JOIN ... GROUP BY ... HAVING.
 *
 * Условие приемлемости то же: единственный писатель — PaymentScheduleService,
 * и значение там всегда полная функция от состояния БД (первая строка графика,
 * не покрытая оплатой), никогда инкремент.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->date('payment_due_date')->nullable()->after('paid_at')
                ->comment('ДЕНОРМАЛИЗАЦИЯ: ближайшая непогашенная плановая дата платежа из графика оплаты. NULL — график не прислан из 1С или закрыт полностью. Пересчитывает только PaymentScheduleService');

            $table->index(['payment_due_date', 'payment_status'], 'shipments_payment_due_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_payment_due_status_index');
            $table->dropColumn('payment_due_date');
        });
    }
};
