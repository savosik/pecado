<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал HTTP-вызовов ApiShip. Одна строка — одна попытка.
 *
 * Без него разбор «почему ТК отклонила заявку» превращается в гадание: ApiShip
 * возвращает ошибки валидации отдельным массивом с кодами перевозчика, и увидеть
 * их постфактум больше негде. Токен из тела и заголовков вырезается перед записью.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apiship_requests', function (Blueprint $table) {
            $table->comment('Журнал HTTP-вызовов агрегатора доставки ApiShip');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('delivery_shipment_id')->nullable()
                ->comment('Отправка, к которой относится вызов (delivery_shipments.id); NULL — справочные вызовы вроде списка ПВЗ')
                ->constrained('delivery_shipments')->nullOnDelete();

            $table->string('operation', 32)->index()
                ->comment("Операция: 'login', 'calculator', 'create_order', 'get_order', 'cancel_order', 'statuses_interval', 'points', 'document', 'courier', 'webhook_subscribe'");
            $table->string('method', 8)->comment('HTTP-метод запроса');
            $table->string('endpoint', 255)->comment('Путь запроса относительно базового URL ApiShip');

            $table->json('request_payload')->nullable()->comment('Тело запроса без токена авторизации');
            $table->json('response_payload')->nullable()->comment('Разобранный JSON-ответ ApiShip');
            $table->text('response_raw')->nullable()->comment('Сырой ответ, если это не JSON (HTML-заглушка балансировщика, PDF-этикетка)');
            $table->unsignedSmallInteger('http_status')->nullable()->comment('HTTP-код ответа; NULL — соединение не состоялось');
            $table->text('error_message')->nullable()->comment('Текст ошибки: транспорт, таймаут либо сообщение ApiShip');
            $table->unsignedInteger('duration_ms')->nullable()->comment('Длительность запроса, миллисекунды');

            $table->foreignId('triggered_by')->nullable()
                ->comment('Кто инициировал вызов (users.id); NULL — фоновая задача или планировщик')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Дата и время вызова');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время изменения записи');

            $table->index(['delivery_shipment_id', 'created_at'], 'apiship_requests_shipment_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apiship_requests');
    }
};
