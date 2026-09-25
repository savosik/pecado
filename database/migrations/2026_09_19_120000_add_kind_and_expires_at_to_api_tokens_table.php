<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токен чата-помощника (assist-02): отдельный вид токена с коротким сроком жизни.
 *
 * Помощник в кабинете ходит в `/mcp/client` через MCP-коннектор Anthropic, и
 * токен клиента проходит транзитом через их сервер. Отдавать туда вечный
 * личный ключ нельзя — воркер выпускает токен вида `assistant` на тред с TTL.
 * Права те же (токен один и полный — решение capi-00), отличается только срок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('kind', 16)->default('personal')->after('name')
                ->comment("Вид токена: 'personal' — выдан клиентом в кабинете, 'assistant' — выпущен воркером чата-помощника на тред, клиенту не показывается");
            $table->timestamp('expires_at')->nullable()->after('is_active')
                ->comment('Срок действия; NULL — бессрочный (личные токены). Истёкший токен не проходит аутентификацию');
            $table->index(['user_id', 'kind'], 'api_tokens_user_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex('api_tokens_user_kind_index');
            $table->dropColumn(['kind', 'expires_at']);
        });
    }
};
