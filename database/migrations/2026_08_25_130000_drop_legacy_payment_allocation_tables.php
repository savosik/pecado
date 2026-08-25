<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снос таблиц старого счётного ядра (fin-11, волна 4).
 *
 * Все три перестали наполняться после перехода 1С на регистр взаиморасчётов:
 *
 *  - `payment_allocations` — расшифровка платежа, удалена из контракта в v16.0.0;
 *  - `shipment_payment_schedules` — график оплаты, переехал в событие
 *    `payment_schedule.updated` и с 12.08.2026 живёт в `settlement_entries`;
 *  - `contractor_balance_overdue_details` — построчная просрочка из `balance.updated`,
 *    поле снято тем же релизом; просрочка выводится из непогашенных плановых строк.
 *
 * `down()` восстанавливает структуру с исходными комментариями, но не данные:
 * вернуть их можно только из бэкапа, и это осознанная цена сноса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('shipment_payment_schedules');
        Schema::dropIfExists('contractor_balance_overdue_details');
    }

    public function down(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->comment('Расшифровка платежа: какие документы он закрывает (снята в fin-11)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete()->comment('Платёж (payments.id)');
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete()->comment('Реализация (shipments.id), NULL — документ ещё не приехал из 1С');
            $table->string('shipment_uuid')->nullable()->comment('UUID реализации из 1С — связь доклеивается по нему');
            $table->string('target_type', 20)->default('shipment')->comment("Тип документа: 'shipment' — реализация, 'order' — заказ (предоплата), 'other' — прочий документ 1С");
            $table->string('target_uuid')->nullable()->comment('UUID документа расшифровки из 1С');
            $table->string('target_name')->nullable()->comment('Наименование документа как в 1С');
            $table->string('order_uuid')->nullable()->comment('UUID заказа (предоплата)');
            $table->decimal('amount', 14, 2)->comment('Сумма, разнесённая на документ');
            $table->unsignedInteger('line_number')->nullable()->comment('Номер строки расшифровки в документе 1С');
            $table->timestamps();

            $table->index(['payment_id', 'shipment_id']);
            $table->index('shipment_uuid');
            $table->index('order_uuid');
        });

        Schema::create('shipment_payment_schedules', function (Blueprint $table) {
            $table->comment('График оплаты реализации из 1С («Правила оплаты», снят в fin-11)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete()->comment('Реализация (shipments.id)');
            $table->unsignedInteger('line_number')->nullable()->comment('Номер строки графика');
            $table->date('due_date')->comment('Плановая дата платежа');
            $table->decimal('amount', 14, 2)->comment('Сумма к оплате по строке');
            $table->decimal('paid_amount', 14, 2)->default(0)->comment('ДЕНОРМАЛИЗАЦИЯ: закрыто платежами');
            $table->decimal('prepaid_amount', 14, 2)->default(0)->comment('ДЕНОРМАЛИЗАЦИЯ: закрыто авансом по заказу');
            $table->decimal('percent', 8, 2)->nullable()->comment('Доля суммы документа, %');
            $table->unsignedInteger('term_days')->nullable()->comment('Срок в днях от основания');
            $table->string('basis', 40)->nullable()->comment('Основание срока');
            $table->string('basis_name')->nullable()->comment('Основание срока словами, как в 1С');
            $table->string('stage', 40)->nullable()->comment('Этап оплаты');
            $table->string('stage_name')->nullable()->comment('Этап оплаты словами, как в 1С');
            $table->string('order_uuid')->nullable()->comment('UUID заказа, авансом по которому гасится строка');
            $table->timestamps();

            $table->index(['shipment_id', 'due_date']);
            $table->index('due_date');
            $table->index('order_uuid');
        });

        Schema::create('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->comment('Построчная просрочка контрагента из balance.updated (снята в fin-11)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('contractor_balance_id')->constrained()->cascadeOnDelete()->comment('Баланс контрагента (contractor_balances.id)');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete()->comment('Наша организация (organizations.id)');
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete()->comment('Реализация (shipments.id), NULL — документ ещё не приехал');
            $table->string('shipment_uuid')->comment('UUID реализации из 1С');
            $table->decimal('amount', 14, 2)->comment('Просроченная сумма по документу');
            $table->date('due_date')->comment('Плановая дата платежа, которая уже прошла');
            $table->timestamps();

            $table->index('shipment_uuid');
        });
    }
};
