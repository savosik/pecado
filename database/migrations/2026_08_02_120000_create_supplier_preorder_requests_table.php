<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Лог отправок предзаказов поставщику (Customer API sex-opt.ru, метод send_order).
     *
     * Одна строка = одна попытка отправки. Ответ поставщика хранится целиком:
     * менеджеру в админке важно видеть не только «успешно/ошибка», но и warnings
     * (нехватка остатка, неизвестные коды товаров).
     */
    public function up(): void
    {
        Schema::create('supplier_preorder_requests', function (Blueprint $table) {
            $table->comment('Лог отправок предзаказов поставщику (sex-opt Customer API)');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('order_id')
                ->comment('Предзаказ (orders.id)')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->unsignedInteger('attempt')->default(1)->comment('Номер попытки отправки по этому заказу');
            $table->string('status', 20)->comment("Итог: 'success' — заказ создан у поставщика, 'rollback' — запрос принят, но транзакция откачена, 'testmode' — тестовая отправка, 'failed' — ошибка");
            $table->string('stock', 20)->comment("Склад поставщика: 'tmn' — Тюмень, 'msk' — Москва");
            $table->boolean('testmode')->default(false)->comment('Отправлено в тестовом режиме (поставщик откатывает транзакцию)');
            $table->text('comment')->nullable()->comment('Комментарий, отправленный поставщику');
            $table->json('request_payload')->nullable()->comment('Тело запроса (без api_key)');
            $table->json('response_payload')->nullable()->comment('Разобранный JSON-ответ поставщика');
            $table->text('response_raw')->nullable()->comment('Сырой ответ, если это не JSON (например HTML-ошибка)');
            $table->unsignedSmallInteger('http_status')->nullable()->comment('HTTP-код ответа');
            $table->string('supplier_order_id', 50)->nullable()->comment('Идентификатор заказа на стороне поставщика (order.id из ответа)');
            $table->unsignedInteger('items_count')->default(0)->comment('Количество позиций, ушедших поставщику');
            $table->json('skipped_items')->nullable()->comment('Позиции, не отправленные из-за отсутствия кода товара (1С «Код»)');
            $table->text('error_message')->nullable()->comment('Текст ошибки (транспорт или result=error от поставщика)');
            $table->unsignedInteger('duration_ms')->nullable()->comment('Длительность запроса, мс');
            $table->foreignId('triggered_by')
                ->nullable()
                ->comment('Кто инициировал ручную переотправку (users.id); NULL — автоматическая отправка')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Дата попытки отправки');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            $table->index(['order_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_preorder_requests');
    }
};
