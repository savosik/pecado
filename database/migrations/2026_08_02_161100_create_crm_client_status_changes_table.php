<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал смен статусов клиента.
 *
 * Колонка `field` заведена сразу, хотя значение пока одно: если лояльность когда-нибудь
 * станет управляться сайтом (это отдельная задача со spec-first порядком и договорённостью
 * на стороне 1С), журнал не придётся мигрировать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_status_changes', function (Blueprint $table) {
            $table->comment('Журнал смен статусов клиента в CRM: кто, когда, с чего на что и почему');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('client_user_id')
                ->comment('Клиент, у которого менялся статус (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('field', 30)
                ->comment("Что менялось: 'lifecycle' — жизненный статус (лояльностью владеет 1С)");

            $table->string('from_value', 30)->nullable()
                ->comment('Значение до смены; NULL — статус ставился впервые');
            $table->string('to_value', 30)
                ->comment('Значение после смены');

            $table->foreignId('user_id')->nullable()
                ->comment('Кто сменил статус (users.id); NULL — сотрудник удалён или смена системная')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason')->nullable()
                ->comment('Причина словами: «не отвечает третий месяц»');

            $table->timestamp('created_at')->nullable()->comment('Когда статус сменили');
            $table->timestamp('updated_at')->nullable()->comment('Служебное поле Eloquent, записи журнала не редактируются');

            $table->index(['client_user_id', 'created_at'], 'crm_status_changes_client_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_status_changes');
    }
};
