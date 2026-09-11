<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Параллельный расчёт переходного периода (эпик mot-00, карточка mot-39; п. 12.2 Положения).
 *
 * Два периода до введения Положения считаются по обеим системам: выплата — по
 * действующей, результат по новой доводится до работника. Снимок по «другой» схеме
 * справочный: в ведомость не попадает, уникальность payroll_calculations не трогает,
 * поэтому живёт в своей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_shadow_calculations', function (Blueprint $table) {
            $table->comment('Справочные снимки расчёта по «другой» схеме в переходный период (п. 12.2): в ведомость не попадают');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('personal_manager_id')->comment('Работник (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->date('period_month')->comment('Расчётный период (1-е число месяца)');
            $table->foreignId('scheme_id')->comment('Схема, по которой посчитан справочный снимок (payroll_schemes.id)')->constrained('payroll_schemes')->cascadeOnDelete();
            $table->json('params_effective')->comment('Действующие параметры по компонентам этой схемы с пометкой слоя');
            $table->json('inputs')->comment('Собранные входы расчёта — те же, что у оплачиваемого снимка, плюс показатели новой схемы при необходимости');
            $table->json('breakdown')->comment('Построчный разбор по компонентам схемы');
            $table->decimal('total', 14, 2)->default(0)->comment('Итог по этой схеме, ₽');
            $table->string('inputs_hash', 64)->nullable()->comment('sha256 входов: без изменений снимок не переписывается');
            $table->timestamp('computed_at')->nullable()->comment('Когда посчитано');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['personal_manager_id', 'period_month', 'scheme_id'], 'motivation_shadow_calc_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_shadow_calculations');
    }
};
