<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимки расчёта зарплаты (эпик pay-00, карточка pay-01).
 *
 * Черновик текущего месяца перезаписывается по событиям; утверждённый снимок
 * неизменяем — поздние оплаты задним числом на него не влияют. «Переоткрыть» =
 * новая версия черновика. В снимке лежит всё, чем считали: параметры, входы с
 * уликами и построчный разбор, — чтобы спор о цифре решался чтением, а не пересчётом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_calculations', function (Blueprint $table) {
            $table->comment('Снимки расчёта зарплаты менеджера за месяц: черновик перезаписывается, утверждённый неизменяем');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('personal_manager_id')->comment('Менеджер (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->date('period_month')->comment('Месяц расчёта (1-е число)');
            $table->unsignedSmallInteger('version')->default(1)->comment('Версия снимка в паре менеджер × месяц; растёт при переоткрытии утверждённого');
            $table->string('status', 10)->default('draft')->comment("Статус: 'draft' — черновик, пересчитывается; 'approved' — утверждён РОПом, заморожен; 'paid' — выплачен");
            $table->foreignId('scheme_id')->nullable()->comment('Версия схемы, по которой считали (payroll_schemes.id)')->constrained('payroll_schemes')->nullOnDelete();
            $table->json('params_effective')->comment('Действующие параметры по компонентам с пометкой источника слоя (схема / постоянные / месяц)');
            $table->json('inputs')->comment('Собранные входы расчёта с уликами: план, выручка, плановые клиенты, накладные со штрафом, позиции доп. дохода');
            $table->json('breakdown')->comment('Построчный разбор: компоненты, факторы, пояснения с числами, эффект каждого фактора в рублях');
            $table->decimal('total', 14, 2)->default(0)->comment('Итог к начислению, ₽');
            $table->json('forecast')->nullable()->comment('Прогноз на конец месяца (сценарии, кривая) и советы — только у черновика');
            $table->string('inputs_hash', 64)->nullable()->comment('sha256 входов: без изменений черновик не переписывается');
            $table->timestamp('computed_at')->nullable()->comment('Когда посчитано');
            $table->foreignId('approved_by_user_id')->nullable()->comment('Кто утвердил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->comment('Когда утверждено');
            $table->foreignId('paid_by_user_id')->nullable()->comment('Кто отметил «выплачено» (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->comment('Когда отмечено выплаченным');
            $table->string('comment')->nullable()->comment('Комментарий РОПа при утверждении или переоткрытии');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['personal_manager_id', 'period_month', 'version'], 'payroll_calculations_version_uniq');
            $table->index(['period_month', 'status'], 'payroll_calculations_month_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_calculations');
    }
};
