<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены ИИ-агентов закупщиков для работы с уценкой через /mcp/purchasing.
 *
 * Отдельная таблица, а не crm_agent_tokens — по тому же обоснованию, что и
 * граница CRM/аналитики: разные резолверы образуют границу, которую нельзя
 * перешагнуть по недосмотру. Токен закупщика управляет ценами и публикацией
 * витрины уценки и не должен даже теоретически открывать CRM, и наоборот.
 *
 * user_id обязателен: у токена есть право записи (цена, публикация), и
 * «кто назначил цену» фиксируется в product_defects.priced_by по владельцу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_agent_tokens', function (Blueprint $table) {
            $table->comment('Токены ИИ-агентов закупщиков для доступа к уценке (/mcp/purchasing)');

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
        Schema::table('purchasing_agent_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->comment('Закупщик, от имени которого работает агент (users.id); операции идут его правами')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchasing_agent_tokens');
    }
};
