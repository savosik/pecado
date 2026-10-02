<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ожидаемые поступления товара из 1С (product.expected_arrivals.updated, v16.16.0).
 *
 * Две таблицы: шапка снимка по товару (момент расчёта в 1С — защита от перестановки
 * сообщений; живёт и тогда, когда ожиданий не осталось) и строки ожиданий, которые
 * каждый снимок заменяет целиком.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_expected_arrival_snapshots', function (Blueprint $table) {
            $table->comment('Шапка последнего снимка ожидаемых поступлений товара из 1С (product.expected_arrivals.updated): по ней отбрасываются опоздавшие снимки');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('product_id')->unique()
                ->comment('Товар (products.id), одна шапка на товар')
                ->constrained('products')->cascadeOnDelete();
            $table->dateTime('calculated_at')
                ->comment('Момент, на который 1С собрала применённый снимок (calculated_at из сообщения, во времени сайта). Снимок с более ранним моментом отбрасывается');
            $table->dateTime('received_at')
                ->comment('Когда сайт применил этот снимок');
            $table->string('message_id')->nullable()
                ->comment('message_id сообщения 1С, которым применён снимок');
            $table->unsignedInteger('rows_count')->default(0)
                ->comment('Сколько строк ожиданий сохранено из снимка (0 — ожиданий нет, снимок очистил товар)');
            $table->timestamp('created_at')->nullable()->comment('Когда по товару применён первый снимок');
            $table->timestamp('updated_at')->nullable()->comment('Когда шапка последний раз перезаписана');
        });

        Schema::create('product_expected_arrivals', function (Blueprint $table) {
            $table->comment('Ожидаемые поступления товара по складам сайта из 1С: дата и количество к поступлению. Каждый снимок по товару заменяет строки целиком; видят только сотрудники (CRM)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('product_id')
                ->comment('Товар (products.id)')
                ->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')
                ->comment('Склад сайта, на который ожидается поступление (warehouses.id)')
                ->constrained('warehouses')->cascadeOnDelete();
            $table->date('expected_date')->nullable()
                ->comment('Ожидаемая дата поступления. NULL — «дата уточняется»: дата в документе не заполнена либо прошла, а товар не принят');
            $table->decimal('quantity', 15, 3)
                ->comment('Количество к поступлению в базовой единице товара: непринятый остаток по документам поступления');
            $table->string('source', 20)
                ->comment("Источник ожидания: 'purchase' — поступление от поставщика, 'import' — импортный заказ (в первой версии 1С не шлёт)");
            $table->timestamp('created_at')->nullable()->comment('Когда строка записана — момент применения снимка');
            $table->timestamp('updated_at')->nullable()->comment('Совпадает с created_at: строки не правятся, снимок заменяет их целиком');

            $table->index(['product_id', 'warehouse_id'], 'pea_product_warehouse_idx');
            $table->index(['warehouse_id', 'expected_date'], 'pea_warehouse_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_expected_arrivals');
        Schema::dropIfExists('product_expected_arrival_snapshots');
    }
};
