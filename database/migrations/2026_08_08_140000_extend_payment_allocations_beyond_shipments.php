<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расшифровка платежа за пределами реализаций (протокол v15.16.0).
 *
 * До этой версии строка разнесения умела указывать только на реализацию.
 * Замер 1С на боевой базе за 2026 год: из 156 754 061,98 ₽ разнесения на
 * реализации ложится 44 881 268,77 ₽, а 111 872 793,21 ₽ (71,4%) не помещалось —
 * это предоплаты по заказам клиентов, первичные документы и отчёты комиссионера.
 * Такие строки сайт пропускал с записью в лог, то есть деньги исчезали молча.
 *
 * `shipment_uuid` перестаёт быть NOT NULL: у строк по заказу и прочим документам
 * реализации нет. Ключ связи теперь зависит от `target_type`.
 *
 * `order_id` не заводим: связь с заказом остаётся мягкой, по `order_uuid`, — ровно
 * как связь с реализацией живёт по `shipment_uuid`. Заказ может не приехать на сайт
 * вовсе (документ 1С без сайтового происхождения), а FK превратил бы это в ошибку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->string('target_type', 16)->default('shipment')->after('payment_id')
                ->comment("Тип документа расшифровки: 'shipment' — реализация, 'order' — заказ клиента (предоплата), 'other' — прочий документ (первичный документ, отчёт комиссионера)");
            $table->char('target_uuid', 36)->nullable()->after('order_uuid')
                ->comment("UUID документа для target_type = 'other'. Сайт такие строки ни с чем не связывает — поле нужно, чтобы строку можно было найти в 1С");
            $table->string('target_name')->nullable()->after('target_uuid')
                ->comment("Представление документа строкой, как в 1С («Отчёт комиссионера 29УТ-000112 от 14.03.2026»). Для target_type = 'other' — единственное, что видит клиент в расшифровке");

            $table->index(['target_type', 'order_uuid'], 'payment_allocations_target_order_index');
        });

        // shipment_uuid становится nullable. Комментарий сохраняем и уточняем:
        // change() без ->comment() стёр бы его (.claude/rules/db-comments.md).
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->char('shipment_uuid', 36)->nullable()
                ->comment("UUID реализации в 1С — источник правды связи при target_type = 'shipment'. Хранится всегда, даже если реализации на сайте ещё нет. NULL у строк по заказу и прочим документам")
                ->change();
        });

        // Существующие строки заведомо относятся к реализациям: другого типа
        // контракт до v15.16.0 не допускал.
        DB::table('payment_allocations')->update(['target_type' => 'shipment']);

        // Денормализованная предоплата по заказу — зеркало shipments.paid_amount.
        // Считается полной функцией от строк разнесения, никогда инкрементом,
        // поэтому повторная доставка сообщения её не задваивает.
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('prepaid_amount', 14, 2)->default(0)->after('total_amount')
                ->comment('Предоплата по заказу: сумма строк расшифровки платежей с target_type = order, в валюте заказа. Накладную не гасит — оплату реализаций закрывают только строки по реализациям');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'prepaid_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('prepaid_amount');
            });
        }

        // Строки, которых не было в старой модели, при откате теряются: колонки
        // под них исчезают, а держать их без типа бессмысленно.
        DB::table('payment_allocations')->where('target_type', '!=', 'shipment')->delete();
        DB::table('payment_allocations')->whereNull('shipment_uuid')->delete();

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->char('shipment_uuid', 36)->nullable(false)
                ->comment('UUID реализации в 1С — источник правды связи. Хранится всегда, даже если реализации на сайте ещё нет')
                ->change();
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropIndex('payment_allocations_target_order_index');
            $table->dropColumn(['target_type', 'target_uuid', 'target_name']);
        });
    }
};
