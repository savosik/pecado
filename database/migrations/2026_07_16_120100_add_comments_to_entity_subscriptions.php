<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Досыпает комментарии, не доехавшие до БД в 2026_07_14_100000_create_entity_subscriptions_table.
 *
 * ПОЧЕМУ ИХ НЕ БЫЛО. В исходной миграции комментарий у user_id написан:
 *
 *     $table->foreignId('user_id')->constrained()->cascadeOnDelete()
 *         ->comment('Владелец кабинета, создавший подписку (users.id)');
 *
 * но ->constrained() возвращает не столбец, а ForeignKeyDefinition
 * (ForeignIdColumnDefinition::references() → $blueprint->foreign(...)), поэтому
 * ->comment() после него садится на внешний ключ, где грамматика его игнорирует.
 * Комментарий теряется молча — миграция проходит, автор уверен, что всё на месте.
 * Правильный порядок: ->comment() ДО ->constrained().
 *
 * created_at/updated_at пусты по другой причине: $table->timestamps() комментарии
 * не принимает вовсе.
 *
 * Пробел не был виден, потому что db:comments:audit ходил только по дефолтному
 * коннекту и падал на первой же непрокомментированной колонке (обращался к
 * $row->table_name, тогда как MySQL отдаёт ключи information_schema в верхнем
 * регистре). И то и другое исправлено там же.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->comment('Владелец кабинета, создавший подписку (users.id)')
                ->change();
            $table->timestamp('created_at')->nullable()
                ->comment('Дата и время создания подписки')
                ->change();
            $table->timestamp('updated_at')->nullable()
                ->comment('Дата и время последнего изменения подписки')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('entity_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->change();
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }
};
