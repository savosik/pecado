<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Колонки старого счётного ядра и честные комментарии у оставшихся (fin-11).
 *
 * Уходят:
 *  - `payments.allocated_amount` / `unallocated_amount` — агрегаты расшифровки,
 *    которой 1С не присылает с v16.0.0: у всех новых платежей они нулевые,
 *    и «аванс» в журнале показывался бы каждому платежу подряд;
 *  - `shipments.paid_at` — читателей нет ни в коде, ни во фронте, а в регистре
 *    нет даты последнего платежа: заполнять её было бы выдумкой.
 *
 * Остаются `shipments.paid_amount / payment_status / payment_due_date`
 * и `orders.prepaid_amount` — их читают кабинет, фильтры и внешний клиентский
 * API. Меняется только писатель, поэтому у них переписываются комментарии:
 * ссылка на снесённый сервис вводила бы в заблуждение при чтении схемы.
 */
return new class extends Migration
{
    /** Комментарии колонок-проекций: новое значение и прежнее (для отката). */
    private const PROJECTION_COMMENTS = [
        ['shipments', 'paid_amount', 'decimal(14,2) NOT NULL DEFAULT 0',
            'ДЕНОРМАЛИЗАЦИЯ: оплачено по реализации. Проекция плановых строк регистра взаиморасчётов; пишет только SettlementProjector',
            'ДЕНОРМАЛИЗАЦИЯ: оплачено по реализации — сумма разнесений входящих платежей минус возвраты. Пересчитывает только PaymentAllocationService'],
        ['shipments', 'payment_status', "varchar(20) NOT NULL DEFAULT 'unpaid'",
            "Статус оплаты: 'unpaid' — не оплачена, 'partial' — частично, 'paid' — оплачена, 'overpaid' — переплата. Проекция регистра; пишет только SettlementProjector",
            "Статус оплаты: 'unpaid' — не оплачена, 'partial' — частично, 'paid' — оплачена, 'overpaid' — переплата. Пересчитывает только PaymentAllocationService"],
        ['shipments', 'payment_due_date', 'date NULL',
            'ДЕНОРМАЛИЗАЦИЯ: ближайшая непогашенная плановая дата платежа. Проекция плановых строк регистра; пишет только SettlementProjector',
            'ДЕНОРМАЛИЗАЦИЯ: ближайшая непогашенная плановая дата платежа из графика оплаты. NULL — график не прислан из 1С или закрыт полностью. Пересчитывает только PaymentScheduleService'],
        ['orders', 'prepaid_amount', 'decimal(14,2) NOT NULL DEFAULT 0',
            'ДЕНОРМАЛИЗАЦИЯ: предоплата по заказу. Проекция плановых строк регистра; пишет только SettlementProjector',
            'ДЕНОРМАЛИЗАЦИЯ: предоплата по заказу — сумма строк расшифровки платежей с target_type = order. Пересчитывает только PaymentAllocationService'],
    ];

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['allocated_amount', 'unallocated_amount']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });

        $this->recomment(3);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('allocated_amount', 14, 2)->default(0)->after('currency_code')
                ->comment('ДЕНОРМАЛИЗАЦИЯ: сумма строк payment_allocations. Пересчитывает только PaymentAllocationService');
            $table->decimal('unallocated_amount', 14, 2)->default(0)->after('allocated_amount')
                ->comment('ДЕНОРМАЛИЗАЦИЯ: нераспределённый остаток (аванс) = amount - allocated_amount. Пересчитывает только PaymentAllocationService');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->comment('Момент полной оплаты реализации');
        });

        $this->recomment(4);
    }

    /**
     * Переписать комментарии колонок-проекций.
     *
     * Только MySQL: SQLite комментариев не хранит, а MODIFY там нет вовсе.
     * Значение колонки не трогается — меняется лишь текст в схеме.
     */
    private function recomment(int $index): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::PROJECTION_COMMENTS as $row) {
            [$table, $column, $definition] = $row;

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s COMMENT %s',
                $table,
                $column,
                $definition,
                DB::getPdo()->quote($row[$index]),
            ));
        }
    }
};
