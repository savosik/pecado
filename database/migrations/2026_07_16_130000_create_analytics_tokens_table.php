<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены доступа к аналитическому MCP-серверу для ИИ-агентов менеджеров.
 *
 * Отдельная таблица, а не поле в api_tokens, сознательно: api_tokens обслуживает
 * client-api (интеграции клиентов, боевой трафик Гевеи и sex-opt), и смешивать
 * с ним доступ к ПДн через ИИ значило бы, что один резолвер по ошибке пустит
 * менеджерский токен в клиентский API или наоборот. Разные таблицы — разные
 * резолверы — граница, которую нельзя перешагнуть по недосмотру.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_tokens', function (Blueprint $table) {
            $table->comment('Токены доступа менеджеров к аналитическому MCP-серверу (/mcp/analytics) через ИИ-агентов');

            $table->id()->comment('Первичный ключ');
            $table->string('name')
                ->comment('Кому выдан: имя менеджера — попадает в лог каждого запроса, поэтому осмысленное');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique()
                ->comment('Секрет для Bearer-аутентификации; хранится как есть, отдаётся владельцу один раз при выпуске');
            $table->boolean('is_active')->default(true)
                ->comment('Активен ли токен: false — отозван, доступ закрыт без удаления записи (сохраняем для аудита)');
            $table->timestamp('last_used_at')->nullable()
                ->comment('Последнее обращение с этим токеном; обновляется не чаще раза в минуту');
            $table->timestamps();
        });

        // Комментарий к FK-колонке отдельно: ->constrained() возвращает объект
        // внешнего ключа, и ->comment() после него в БД не доезжает (см. миграцию
        // 2026_07_16_120100 — та же грабля).
        Schema::table('analytics_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()
                ->comment('Менеджер-владелец (users.id), если он есть в системе; null для внешнего доступа')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_tokens');
    }
};
