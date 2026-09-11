<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Квартальная премия отдела, поклиентный зачёт и распределение (эпик mot-00, карточка mot-20).
 *
 * Премия живёт рядом с месячным расчётом, а не внутри него: пункты 3.4 и 7.6 требуют, чтобы
 * она не влияла на месячный доход, и это должно быть видно в данных, а не только в коде.
 * payroll_calculations к тому же уникален по (менеджер, месяц, версия), а премия — на отдел
 * и за квартал.
 *
 * Квалификация хранится построчно по каждому партнёру (п. 7.3): зачёт проверяется отдельно
 * по каждому, а не делением совокупного объёма на количество. На 08.09.2026 за квартал
 * июнь–август отдел дал 12 новых партнёров, из них порог 100 000 ₽ набрали четверо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_quarterly_bonuses', function (Blueprint $table) {
            $table->comment('Квартальная премия отдела продаж: достигнутая ступень, сумма и снимок расчёта');
            $table->id()->comment('Первичный ключ');
            $table->date('quarter_start')->unique()->comment('Квартал: 1-е число первого месяца квартала');
            $table->unsignedInteger('qualified_count')->default(0)->comment('Сколько Новых партнёров прошли порог квалификации');
            $table->unsignedSmallInteger('step_reached')->default(0)->comment('Достигнутая ступень: 0 — ни одной, далее 1, 2, 3 по ступеням приказа');
            $table->decimal('amount', 15, 2)->default(0)->comment('Сумма премии отдела, ₽');
            $table->string('status', 10)->default('draft')->comment("Статус: 'draft' — черновик, 'approved' — утверждена, 'paid' — выплачена");
            $table->json('snapshot')->nullable()->comment('Чем считали: состав квалифицированных партнёров и параметры приказа на момент расчёта');
            $table->foreignId('approved_by')->nullable()->comment('Кто утвердил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->comment('Когда утверждена');
            $table->foreignId('paid_by')->nullable()->comment('Кто отметил выплаченной (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->comment('Когда отмечена выплаченной');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');
        });

        Schema::create('motivation_quarterly_qualifications', function (Blueprint $table) {
            $table->comment('Поклиентный зачёт для квартальной премии (п. 7.3): по каждому Новому партнёру отдельно');
            $table->id()->comment('Первичный ключ');
            $table->date('quarter_start')->comment('Квартал: 1-е число первого месяца квартала');
            $table->foreignId('user_id')->comment('Партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_manager_id')->nullable()->comment('Кто вёл партнёра в квартале (personal_managers.id)')->constrained('personal_managers')->nullOnDelete();
            $table->decimal('shipments_amount', 15, 2)->default(0)->comment('Отгрузки партнёру за квартал, ₽');
            $table->decimal('returns_amount', 15, 2)->default(0)->comment('Возвраты партнёра за квартал, ₽ (вычитаются из зачёта)');
            $table->boolean('qualified')->default(false)->comment('Прошёл ли партнёр порог квалификации приказа');
            $table->timestamp('computed_at')->nullable()->comment('Когда посчитано');

            $table->unique(['quarter_start', 'user_id'], 'motivation_quarterly_qualifications_uniq');
            $table->index(['quarter_start', 'qualified'], 'motivation_quarterly_qualifications_step_idx');
        });

        Schema::create('motivation_quarterly_shares', function (Blueprint $table) {
            $table->comment('Распределение квартальной премии по работникам (п. 7.5): доля каждого с основанием');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('bonus_id')->comment('Премия квартала (motivation_quarterly_bonuses.id)')->constrained('motivation_quarterly_bonuses')->cascadeOnDelete();
            $table->foreignId('personal_manager_id')->comment('Кому причитается (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->comment('Доля работника, ₽. Сумма долей обязана равняться сумме премии');
            $table->string('reason', 500)->nullable()->comment('Основание распределения именно такой доли');
            $table->foreignId('author_id')->nullable()->comment('Кто распределил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['bonus_id', 'personal_manager_id'], 'motivation_quarterly_shares_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_quarterly_shares');
        Schema::dropIfExists('motivation_quarterly_qualifications');
        Schema::dropIfExists('motivation_quarterly_bonuses');
    }
};
