<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снос движка правил-фильтров.
 *
 * У правил был ровно один job — решать, кому уйдёт уведомление. С переходом
 * на матрицу настроек партнёра этот вопрос стал строкой таблицы, и движок
 * остался без работы: каждое письмо теперь либо уведомление (решает матрица),
 * либо письмо менеджера (решает менеджер).
 *
 * Данные не переносим: правил на проде ноль.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Сначала ссылка из письма. Внешнего ключа на ней нет — колонку
        // заводили без него, — поэтому просто столбец.
        if (Schema::hasColumn('crm_emails', 'auto_sent_rule_id')) {
            Schema::table('crm_emails', function (Blueprint $table) {
                $table->dropColumn('auto_sent_rule_id');
            });
        }

        Schema::dropIfExists('crm_mail_rule_hits');
        Schema::dropIfExists('crm_mail_rule_clients');
        Schema::dropIfExists('crm_mail_rules');
    }

    public function down(): void
    {
        // Обратного хода нет намеренно: воскрешать движок, признанный
        // непонятным дважды, незачем. Схема лежит в истории git.
        throw new RuntimeException(
            'Откат сноса правил не поддерживается: восстанавливайте из git, если действительно нужно.',
        );
    }
};
