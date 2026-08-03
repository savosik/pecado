<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цепочка задач: какая задача выросла из какой.
 *
 * Закрытие задачи — естественный момент спросить «а что дальше?». Без связи
 * follow-up превратился бы в обычную новую задачу, и по клиенту нельзя было бы
 * увидеть, что работа с ним ведётся непрерывно, а не рывками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->foreignId('follow_up_of_id')
                ->nullable()
                ->after('client_user_id')
                ->comment('Задача, при закрытии которой поставлена эта (crm_tasks.id); NULL — самостоятельная')
                ->constrained('crm_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropForeign(['follow_up_of_id']);
            $table->dropColumn('follow_up_of_id');
        });
    }
};
