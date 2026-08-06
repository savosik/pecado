<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расшифровка платежа: разнесение суммы по реализациям (US-17, протокол v15.11.0).
 *
 * Уникального ключа (payment_id, shipment_uuid) намеренно НЕТ: один платёж может
 * иметь две строки на одну реализацию — в 1С они различаются договором или статьёй
 * ДДС, которых сайт не знает. Идемпотентность обеспечивает delete-and-recreate
 * в PaymentAllocationService, а не констрейнт.
 *
 * Источник правды связи — shipment_uuid, а не shipment_id: платежи и реализации
 * идут разными очередями без гарантии порядка, и строка сохраняется даже когда
 * реализации на сайте ещё нет. Связь доклеивается при её получении.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->comment('Расшифровка платежа: разнесение суммы платёжного документа по реализациям (many-to-many «платёж ↔ реализация» с суммой)');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('payment_id')->comment('Платёж (payments.id). При физическом удалении платежа строки удаляются вместе с ним')
                ->constrained()->cascadeOnDelete();
            $table->char('shipment_uuid', 36)->index()->comment('UUID реализации в 1С — источник правды связи. Хранится всегда, даже если реализации на сайте ещё нет');
            $table->foreignId('shipment_id')->nullable()->comment('Реализация на сайте (shipments.id). NULL — реализация ещё не пришла из 1С, связь доклеится при её получении')
                ->constrained('shipments')->nullOnDelete();
            $table->char('order_uuid', 36)->nullable()->index()->comment('UUID заказа в 1С, если 1С указала его в строке расшифровки. Мягкая связь без FK — заказ мог не приехать');
            $table->decimal('amount', 14, 2)->default(0)->comment('Сумма, разнесённая на эту реализацию, в валюте платежа. Всегда положительная — знак задаёт direction платежа');
            $table->unsignedInteger('line_number')->nullable()->comment('Номер строки в табличной части «Расшифровка платежа» 1С — сохраняет порядок показа');
            $table->timestamp('created_at')->nullable()->comment('Дата и время создания строки на сайте');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения строки (например, доклейка shipment_id)');

            $table->index(['payment_id', 'shipment_id'], 'payment_allocations_payment_shipment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
