<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Участвует ли менеджер в расчёте зарплаты (эпик sal-00, карточка sal-14).
 *
 * За карточкой менеджера может числиться база отдела, а зарплату по схеме ОП
 * человек не получает: владелец, руководитель, «общая» карточка-приёмник
 * ничейных партнёров. Деактивировать такую карточку нельзя — на ней висят
 * клиенты, планы и отгрузки, и она нужна спискам CRM. Поэтому участие
 * в зарплате — отдельное свойство, а не побочный эффект `is_active`.
 *
 * Умолчание — участвует: миграция не меняет поведение, выключает РОП в UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->boolean('payroll_enabled')
                ->default(true)
                ->after('is_active')
                ->comment('Участвует в расчёте зарплаты: 1 — считается и попадает в сводку отдела, 0 — исключён (карточка остаётся рабочей в остальной CRM)');
        });
    }

    public function down(): void
    {
        Schema::table('personal_managers', function (Blueprint $table) {
            $table->dropColumn('payroll_enabled');
        });
    }
};
