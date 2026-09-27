<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал вызовов клиентского API v1 и MCP `/mcp/client` (эпик capi-00, карточка capi-17).
 *
 * Отвечает на вопрос «пользуются ли клиенты своим ИИ-агентом, кто, как часто и
 * для чего». Одна строка — один вызов инструмента MCP, одно подключение агента
 * или один REST-запрос. Аргументы и ответы намеренно не хранятся: для оценки
 * пользы нужны операция, исход и время, а не состав заказа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_agent_calls', function (Blueprint $table) {
            $table->comment('Журнал вызовов клиентского API v1 и MCP-сервера клиента: кто, когда, какой инструмент и операцию, с каким исходом');
            $table->id()->comment('Первичный ключ');
            $table->string('kind', 16)->comment("Вид вызова: 'mcp_connect' — подключение агента (initialize), 'mcp_tool' — вызов инструмента MCP, 'rest' — запрос REST v1");
            $table->foreignId('user_id')->comment('Клиент — владелец токена (users.id)')->constrained('users')->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->comment('Токен, которым выполнен вызов (api_tokens.id); NULL после удаления токена')->constrained('api_tokens')->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable()->comment('Юрлицо, от имени которого шла операция (companies.id), если операция его требовала');
            $table->string('session_id', 64)->nullable()->comment('Сессия MCP (заголовок MCP-Session-Id): группирует вызовы одного разговора агента');
            $table->string('agent', 120)->nullable()->comment('ИИ-клиент по clientInfo из initialize: «claude-code 2.1.0», «cursor 1.5»; NULL — не представился или REST');
            $table->string('tool', 64)->nullable()->comment('Инструмент MCP: client-call, client-prices…; NULL для подключения и REST');
            $table->string('operation', 64)->nullable()->comment('Операция реестра клиентского API: orders.create, catalog.prices…; для REST /me — «me»; NULL, если до операции не дошло');
            $table->boolean('mutating')->default(false)->comment('Операция меняла данные (создание заказа, вопрос менеджеру)');
            $table->boolean('ok')->default(true)->comment('Вызов завершился успехом (без ошибки инструмента и без HTTP 4xx/5xx)');
            $table->string('error_code', 64)->nullable()->comment('Код отказа: gate/validation/not_found/company_required/idempotency_key_required…; NULL при успехе');
            $table->unsignedInteger('duration_ms')->default(0)->comment('Длительность обработки, мс');
            $table->timestamp('created_at')->nullable()->comment('Когда выполнен вызов');

            $table->index(['user_id', 'created_at'], 'client_agent_calls_user_created_index');
            $table->index('created_at', 'client_agent_calls_created_index');
            $table->index(['kind', 'created_at'], 'client_agent_calls_kind_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_agent_calls');
    }
};
