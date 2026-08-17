<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исходы закрытия и переносы (task-03).
 *
 * Перенос — не закрытие: задача остаётся открытой, сдвигается due_at и растёт
 * счётчик. Закрытие терминально и различает «успешно» и «с проблемой».
 * Существующие закрытые задачи остаются с outcome = null — «закрыто без исхода»,
 * задним числом ничего не красим.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->string('outcome', 20)->nullable()->after('status')
                ->comment("Исход закрытия: 'success' — успешно, 'problem' — с проблемой; null — не закрыта, отменена или закрыта до введения исходов");
            $table->unsignedSmallInteger('postponed_count')->default(0)->after('due_at')
                ->comment('Сколько раз переносился срок задачи');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'postponed_count']);
        });
    }
};
