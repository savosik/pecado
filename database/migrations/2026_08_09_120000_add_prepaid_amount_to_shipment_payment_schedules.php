<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Зачёт аванса по заказу в строках графика оплаты.
 *
 * ЗАЧЕМ. 1С разносит поступление либо на реализацию, либо на ЗАКАЗ (предоплата).
 * Второй случай — почти половина денег: на 2026-08-09 из 82,8 млн ₽ поступлений
 * 39,7 млн (47,9 %) разнесены на заказы, и в январе–мае это была основная практика.
 * Такая оплата не увеличивает `shipments.paid_amount` (и не должна: это поле —
 * строгая проекция расшифровки платежа по реализациям), поэтому строка графика
 * оставалась непогашенной навсегда, а раздел «Финансы» показывал просрочку,
 * которой в учёте нет: у одного клиента 5,53 млн против 478 тыс по данным 1С.
 *
 * Отдельная колонка, а не примешивание к `paid_amount`: у них разные источники
 * и разные инварианты. `paid_amount` — раскладка `shipments.paid_amount` по строкам,
 * и сумма строк обязана сходиться с реализацией. `prepaid_amount` — раскладка
 * аванса заказа по строкам ВСЕХ его реализаций, и сходится она с заказом.
 * Смешать их значило бы потерять обе проверки.
 *
 * Остаток к оплате по строке = amount - paid_amount - prepaid_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_payment_schedules', function (Blueprint $table) {
            $table->decimal('prepaid_amount', 14, 2)->default(0)->after('paid_amount')
                ->comment('ДЕНОРМАЛИЗАЦИЯ: сколько из суммы строки закрыто авансом по заказу (payment_allocations с target_type = order). Раскладка FIFO по due_date в пределах заказа; пишет только PaymentScheduleService');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_payment_schedules', function (Blueprint $table) {
            $table->dropColumn('prepaid_amount');
        });
    }
};
