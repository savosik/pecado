<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Состав отправки: какие реализации (документы 1С) уехали одной посылкой.
 *
 * Уникальность здесь только внутри отправки. Глобального «реализация может быть
 * в одной отправке» индексом не выразить: после отмены заявки груз собирают заново,
 * и та же реализация обязана попасть в новую отправку. Запрет на попадание в две
 * ОДНОВРЕМЕННО активные отправки живёт в DeliveryShipmentBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_shipment_documents', function (Blueprint $table) {
            $table->comment('Состав отправки — реализации из 1С, вошедшие в груз');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('delivery_shipment_id')
                ->comment('Отправка (delivery_shipments.id)')
                ->constrained('delivery_shipments')->cascadeOnDelete();
            $table->foreignId('shipment_id')
                ->comment('Реализация из 1С (shipments.id)')
                ->constrained('shipments')->cascadeOnDelete();

            // Снапшоты: реализацию 1С может переписать после отгрузки, а в отправку
            // она вошла с теми весом и суммой, по которым считался тариф.
            $table->decimal('amount', 12, 2)->default(0)->comment('Сумма реализации на момент включения в отправку, рубли');
            $table->unsignedInteger('weight')->default(0)->comment('Расчётный вес позиций реализации на момент включения, граммы');

            $table->timestamp('created_at')->nullable()->comment('Дата и время включения реализации в отправку');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения строки');

            $table->unique(['delivery_shipment_id', 'shipment_id'], 'delivery_shipment_documents_unique');
            $table->index('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_shipment_documents');
    }
};
