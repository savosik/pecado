<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Разовый бэкфил проекций оплаты из регистра (fin-11, волна 3).
 *
 * Прежний писатель колонок `shipments.paid_amount / payment_status /
 * payment_due_date` и `orders.prepaid_amount` (`PaymentAllocationService`)
 * снесён; накопленные им значения пересчитываются из плановых строк регистра
 * той же командой, что дальше держит их актуальными на каждом
 * `payment_schedule.updated`.
 *
 * Миграцией, а не рукой после деплоя: бэкфил обязан выполниться и на dev,
 * и на проде ровно один раз и до DROP-миграций старых таблиц. На свежей
 * базе (тесты, новый стенд) регистр пуст — мгновенный no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settlement_entries')) {
            return;
        }

        if (! DB::table('settlement_entries')->where('nature', 'plan')->exists()) {
            return;
        }

        Artisan::call('settlements:project-documents');
    }

    public function down(): void
    {
        // Пересчёт не откатывается: прежние значения принадлежали снесённому
        // писателю, и способа их восстановить, кроме бэкапа, нет.
    }
};
