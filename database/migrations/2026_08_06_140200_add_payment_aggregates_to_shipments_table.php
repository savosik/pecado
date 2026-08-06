<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Агрегаты оплаты на реализации (US-17, протокол v15.11.0).
 *
 * Денормализация, а не вычисление на лету — по четырём причинам:
 *
 * 1. Фильтр «покажи неоплаченные» в CRM работает по колонкам таблицы; whereHas
 *    с агрегатом на журнале РОПа (десятки тысяч реализаций) — seq scan на каждый
 *    заход в раздел.
 * 2. Экспорт кабинета идёт потоково через cursor(), догрузить агрегаты пачкой негде.
 * 3. MCP-агент: «сколько неоплаченных отгрузок у клиента X» должно быть одним WHERE.
 *    Заставлять LLM писать JOIN ... GROUP BY ... HAVING — гарантированные
 *    правдоподобно-неверные отчёты.
 * 4. Прецедент рядом: shipments.total_amount — уже денормализация из shipment_items.
 *
 * Условие приемлемости: единственный писатель — PaymentAllocationService, и пересчёт
 * там всегда полная функция от состояния БД (SELECT SUM ... GROUP BY), никогда
 * инкремент. Поэтому повторная доставка сообщения даёт тот же результат.
 * Страховочная сеть — команда `php artisan payments:recalculate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('paid_amount', 14, 2)->default(0)->after('total_amount')
                ->comment('ДЕНОРМАЛИЗАЦИЯ: оплачено по реализации — сумма разнесений входящих платежей минус возвраты. Пересчитывает только PaymentAllocationService');

            $table->string('payment_status', 16)->default('unpaid')->after('paid_amount')
                ->comment("Статус оплаты: 'unpaid' — не оплачена, 'partial' — оплачена частично, 'paid' — оплачена полностью, 'overpaid' — переплата. Производное от total_amount и paid_amount");

            $table->dateTime('paid_at')->nullable()->after('payment_status')
                ->comment('Дата платежа, которым реализация закрылась полностью. NULL — ещё не закрыта');

            $table->index(['user_id', 'payment_status'], 'shipments_user_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_user_payment_status_index');
            $table->dropColumn(['paid_amount', 'payment_status', 'paid_at']);
        });
    }
};
