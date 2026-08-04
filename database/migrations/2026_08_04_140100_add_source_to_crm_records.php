<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто создал запись — человек в интерфейсе или ИИ-агент от его имени.
 *
 * Автор у записи и так есть, но его недостаточно: и агент, и человек пишут
 * от одного и того же менеджера. Без отдельного признака разбор «кто это
 * написал клиенту» упирается в тупик — а именно этот вопрос и задают, когда
 * письмо оказалось неудачным.
 *
 * Значение по умолчанию 'web': всё, что уже лежит в базе, создано людьми.
 */
return new class extends Migration
{
    /** Таблицы, записи в которых создаются обоими каналами. */
    private const TABLES = ['crm_comments', 'crm_tasks', 'crm_emails', 'crm_calls'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('source', 16)->default('web')->after('id')
                    ->comment("Источник записи: 'web' — человек в интерфейсе, 'agent' — ИИ-агент от имени сотрудника");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
