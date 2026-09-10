<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Мягкое удаление аккаунта: user_kind = 'deleted' + момент удаления.
 *
 * РОП чистит базу партнёров от спама, дублей и конкурентов, а «сотрудник»
 * и «служебный» для них не подходят: такие учётки продолжали маячить в
 * списке пользователей и могли входить в кабинет. Четвёртый тип прячет
 * аккаунт везде и закрывает вход, строка при этом остаётся — заказы,
 * документы и журнал смен статусов ссылаются на неё как раньше.
 *
 * Без трейта SoftDeletes намеренно: на users завязаны сотни belongsTo-связей,
 * и глобальный скоуп отдавал бы null в исторических записях. Колонка названа
 * стандартно — если когда-нибудь трейт всё же понадобится, данные уже готовы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')
                ->nullable()
                ->after('user_kind')
                ->comment("Момент мягкого удаления аккаунта (user_kind = 'deleted'); NULL — аккаунт действующий");
        });

        // Комментарий колонки — только там, где он есть; SQLite в тестах его не хранит.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE `users` MODIFY `user_kind` VARCHAR(20) NOT NULL DEFAULT 'client' "
                ."COMMENT \"Тип аккаунта: 'client' — клиент, 'staff' — сотрудник компании, "
                ."'service' — служебная/техническая учётка, 'deleted' — мягко удалён (скрыт везде, вход закрыт)\""
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
