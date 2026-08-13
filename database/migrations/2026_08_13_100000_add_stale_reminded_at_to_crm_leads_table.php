<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка о напоминании по залежавшемуся лиду.
 *
 * Отдельная колонка, а не поиск ранее созданной задачи: задачу менеджер может
 * закрыть, переименовать или удалить, и тогда команда сочла бы лида
 * ненапомненным и завела бы вторую такую же на следующую ночь.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->timestamp('stale_reminded_at')
                ->nullable()
                ->after('stage_changed_at')
                ->comment('Когда по лиду в последний раз напоминали о простое; NULL — ещё не напоминали');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('stale_reminded_at');
        });
    }
};
