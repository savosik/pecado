<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключи идемпотентности клиентского API v1 (эпик capi-00, карточка capi-03).
 *
 * Агент клиента повторяет запрос при обрыве соединения. Без ключа повтор
 * `orders.create` создаёт второй заказ, который уходит в 1С. Ключ хранится в
 * таблице, а не в кэше: потерянный на cache:clear ключ — это ровно тот дубль,
 * ради которого он заведён. Строка живёт сутки и чистится model:prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_api_idempotency_keys', function (Blueprint $table) {
            $table->comment('Ключи идемпотентности клиентского API v1: повтор запроса с тем же ключом возвращает сохранённый ответ, а не создаёт дубль');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Клиент — владелец токена (users.id)')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 64)->comment('Идентификатор операции реестра: orders.create, checkout.submit…');
            $table->string('key', 128)->comment('Ключ идемпотентности из заголовка Idempotency-Key, задаёт клиент');
            $table->char('request_hash', 64)->comment('SHA-256 нормализованных аргументов запроса: тот же ключ с другим телом отклоняется');
            $table->string('status', 16)->comment("Состояние: 'in_progress' — выполняется, 'done' — ответ сохранён");
            $table->unsignedSmallInteger('status_code')->nullable()->comment('HTTP-код сохранённого ответа');
            $table->json('response')->nullable()->comment('Сохранённый ответ операции (конверт data/meta), отдаётся при повторе');
            $table->timestamp('created_at')->nullable()->comment('Когда ключ принят');
            $table->timestamp('expires_at')->comment('Когда ключ протухает (сутки); после — удаляется model:prune');

            $table->unique(['user_id', 'operation', 'key'], 'client_api_idempotency_user_operation_key_unique');
            $table->index('expires_at', 'client_api_idempotency_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_api_idempotency_keys');
    }
};
