<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены подписных ICS-фидов задач (task-07).
 *
 * Внешние календари (Google, Яндекс) ходят за фидом без сессии — доступ
 * только по личному токену. Перевыпуск токена отзывает утёкшую ссылку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_calendar_tokens', function (Blueprint $table) {
            $table->comment('Токены ICS-фидов задач CRM для подписки внешних календарей');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Владелец фида (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique()->comment('Секрет фида — часть URL');
            $table->string('scope', 20)->default('mine')
                ->comment("Скоуп фида: 'mine' — задачи владельца, 'department' — весь отдел (только для РОПа)");
            $table->timestamp('last_fetched_at')->nullable()
                ->comment('Когда календарь последний раз забирал фид — видно, живая ли подписка');
            $table->timestamps();
            $table->unique(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_calendar_tokens');
    }
};
