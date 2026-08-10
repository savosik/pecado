<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Грузовые места отправки (коробки), которые задаёт кладовщик.
 *
 * Единицы измерения продиктованы ApiShip и менять их нельзя: вес в ГРАММАХ,
 * габариты в САНТИМЕТРАХ. Ошибка в единицах не вызывает ошибки API — перевозчик
 * молча считает тариф по другому объёмному весу и выставляет другой счёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_shipment_places', function (Blueprint $table) {
            $table->comment('Грузовые места (коробки) отправки');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('delivery_shipment_id')
                ->comment('Отправка (delivery_shipments.id)')
                ->constrained('delivery_shipments')->cascadeOnDelete();

            $table->unsignedSmallInteger('number')->comment('Порядковый номер места в отправке, начиная с 1');
            $table->unsignedInteger('weight')->default(0)->comment('Вес места, ГРАММЫ (требование ApiShip)');
            $table->unsignedSmallInteger('length')->nullable()->comment('Длина места, САНТИМЕТРЫ');
            $table->unsignedSmallInteger('width')->nullable()->comment('Ширина места, САНТИМЕТРЫ');
            $table->unsignedSmallInteger('height')->nullable()->comment('Высота места, САНТИМЕТРЫ');
            $table->string('barcode', 100)->nullable()->comment('Штрихкод места, присвоенный перевозчиком');

            $table->timestamp('created_at')->nullable()->comment('Дата и время создания записи о месте');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи');

            $table->unique(['delivery_shipment_id', 'number'], 'delivery_shipment_places_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_shipment_places');
    }
};
