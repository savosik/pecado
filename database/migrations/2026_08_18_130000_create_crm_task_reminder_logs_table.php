<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Единый лог напоминаний по задачам (task-08).
 *
 * Один контур на все каналы (тосты, письма, push): уникальный ключ даёт
 * идемпотентность — один повод порождает одно напоминание в канал, сколько бы
 * вкладок ни поллило и сколько бы раз ни перезапускался крон.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_reminder_logs', function (Blueprint $table) {
            $table->comment('Лог напоминаний по задачам CRM: защита от повторов на всех каналах');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('task_id')->comment('Задача (crm_tasks.id)')
                ->constrained('crm_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Получатель (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->string('kind', 30)
                ->comment("Повод: 'assigned' — назначена, 'due' — срок наступил, 'due_soon' — срок завтра (почта), 'overdue' — просрочена");
            $table->string('channel', 20)->comment("Канал: 'toast', 'mail', 'push'");
            $table->timestamp('sent_at')->comment('Когда отправлено');
            $table->unique(['task_id', 'user_id', 'kind', 'channel'], 'crm_task_reminder_once');
            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_reminder_logs');
    }
};
