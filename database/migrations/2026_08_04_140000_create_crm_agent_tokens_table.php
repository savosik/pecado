<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены ИИ-агентов менеджеров для пишущего доступа в CRM.
 *
 * Отдельная таблица, а не analytics_tokens и не api_tokens — по тому же
 * обоснованию, что записано в шапке миграции analytics_tokens: разные резолверы
 * образуют границу, которую нельзя перешагнуть по недосмотру. Здесь граница
 * существеннее: у этих токенов есть право записи, а у аналитических нет его
 * даже на уровне СУБД.
 *
 * user_id обязателен, в отличие от analytics_tokens: запись без установленного
 * автора недопустима — «кто это сделал» должно быть в каждой строке ленты,
 * а не восстанавливаться догадками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_agent_tokens', function (Blueprint $table) {
            $table->comment('Токены ИИ-агентов менеджеров для пишущего доступа в CRM (/mcp/crm, /api/crm)');

            $table->id()->comment('Первичный ключ');
            $table->string('name')
                ->comment('Кому выдан — попадает в аудит каждой операции, поэтому осмысленное');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique()
                ->comment('Секрет для Bearer-аутентификации; хранится как есть — его нужно уметь показать владельцу');
            $table->boolean('is_active')->default(true)
                ->comment('false — отозван, доступ закрыт без удаления записи (сохраняем для аудита)');
            $table->timestamp('last_used_at')->nullable()
                ->comment('Последнее обращение; обновляется не чаще раза в минуту');
            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');
        });

        // Комментарий к FK-колонке отдельно: ->constrained() возвращает объект
        // внешнего ключа, и ->comment() после него в БД не доезжает.
        Schema::table('crm_agent_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->comment('Сотрудник, от имени которого работает агент (users.id); операции идут его правами')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_agent_tokens');
    }
};
