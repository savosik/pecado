<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал смены статусов отправки у перевозчика.
 *
 * Статус приходит двумя путями сразу — вебхуком ORDER_STATUS и периодической
 * сверкой через GET /orders/statuses/interval, — то есть дубли гарантированы
 * по построению. Строка пишется только при фактической смене ключа, иначе журнал
 * забился бы повторами того же «В пути» каждые полчаса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_shipment_status_histories', function (Blueprint $table) {
            $table->comment('Журнал смены статусов отправок у перевозчика');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('delivery_shipment_id')
                ->comment('Отправка (delivery_shipments.id)')
                ->constrained('delivery_shipments')->cascadeOnDelete();

            $table->string('from_status_key', 32)->nullable()
                ->comment('Ключ статуса ApiShip до перехода. Пусто у первой записи — заявка только создана');
            $table->string('to_status_key', 32)
                ->comment('Ключ статуса ApiShip после перехода (значения — см. delivery_shipments.apiship_status_key)');
            $table->string('status_name', 255)->nullable()->comment('Название статуса, как его прислал ApiShip');
            $table->string('provider_code', 50)->nullable()->comment('Код статуса в системе самого перевозчика');
            $table->string('source', 16)->default('webhook')
                ->comment("Источник: 'webhook' — вебхук ApiShip, 'poll' — периодическая сверка, 'manual' — действие сотрудника склада");
            $table->dateTime('occurred_at')->index()->comment('Когда перевозчик зафиксировал переход');

            $table->timestamp('created_at')->nullable()->comment('Когда сайт записал переход');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи журнала');

            $table->index(['delivery_shipment_id', 'occurred_at'], 'delivery_status_histories_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_shipment_status_histories');
    }
};
