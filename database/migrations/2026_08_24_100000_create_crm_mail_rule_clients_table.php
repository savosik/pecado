<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адресная привязка правила-фильтра к партнёрам.
 *
 * До этой таблицы правило было глобальным фильтром: «все письма с меткой X».
 * Подписать на повод троих партнёров из восьмисот можно было только условием
 * по ИНН — то есть тремя условиями или тремя правилами.
 *
 * Пустой список означает «все партнёры»: это делает таблицу необязательной
 * и не ломает уже заведённые правила.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_mail_rule_clients', function (Blueprint $table) {
            $table->comment('Партнёры, подписанные на правило-фильтр писем; пустой список = все');
            $table->id()->comment('Первичный ключ');
            // Комментарий ставится до constrained(): тот возвращает описание
            // внешнего ключа, и всё, что вызвано после него, до столбца не доходит.
            $table->foreignId('rule_id')
                ->comment('Правило-фильтр (crm_mail_rules.id)')
                ->constrained('crm_mail_rules')
                ->cascadeOnDelete();
            $table->foreignId('client_user_id')
                ->comment('Подписанный партнёр (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->comment('Кто подписал партнёра (users.id)')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда партнёра подписали');

            // Мягкого удаления нет намеренно: отписать — значит убрать строку.
            // Иначе уникальный индекс запретит подписать партнёра повторно.
            $table->unique(['rule_id', 'client_user_id'], 'crm_mail_rule_clients_unique');
            $table->index('client_user_id', 'crm_mail_rule_clients_client_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_rule_clients');
    }
};
