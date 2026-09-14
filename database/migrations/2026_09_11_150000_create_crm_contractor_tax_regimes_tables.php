<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Налоговый режим юрлиц партнёров: сейчас, план на следующий год и насколько
 * клиенту важен НДС в товаре.
 *
 * Ответ приходит двумя путями: менеджер со слов партнёра в CRM или сам клиент
 * в коротком опросе на сайте (`source`).
 *
 * Отдельная таблица, а не колонки в `companies`: юрлицами владеет 1С, и
 * сохранение модели Company публикует контрагента обратно в шину. Ответ
 * менеджера 1С не нужен и уезжать туда не должен.
 */
return new class extends Migration
{
    private const REGIMES = "'osno' — ОСНО, НДС 22 % с вычетами; 'usn_vat_22' — УСН с НДС 22 % с вычетами; "
        ."'usn_vat_7' — УСН с НДС 7 % без вычетов; 'usn_vat_5' — УСН с НДС 5 % без вычетов; "
        ."'usn_exempt' — УСН без НДС; 'patent' — патент без НДС; 'ausn' — АУСН без НДС; 'foreign' — нерезидент РФ";

    private const PREFERENCES = "Насколько клиенту важен НДС в товаре: 'required' — нужен, берёт к вычету; "
        ."'preferred' — желателен; 'indifferent' — без разницы; 'without' — лучше без НДС; NULL — не выяснено";

    private const SOURCES = "Чей ответ: 'manager' — менеджер со слов партнёра, 'client' — сам клиент в опросе на сайте";

    public function up(): void
    {
        Schema::create('crm_contractor_tax_regimes', function (Blueprint $table) {
            $table->comment('Налоговый режим юрлица партнёра: сейчас, план на следующий год и важность НДС — для оценки рисков перехода клиентов на НДС');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('company_id')->unique()
                ->comment('Контрагент — юрлицо партнёра (companies.id)')
                ->constrained('companies')->cascadeOnDelete();
            $table->string('current_regime', 20)->nullable()
                ->comment('Режим сейчас: '.self::REGIMES);
            $table->string('planned_regime', 20)->nullable()
                ->comment('План на planned_year: те же значения, что у current_regime, плюс \'undecided\' — ещё не решили');
            $table->unsignedSmallInteger('planned_year')->nullable()
                ->comment('Год, к которому относится план; 1 января этого года ответ устаревает');
            $table->string('vat_preference', 12)->nullable()->comment(self::PREFERENCES);
            $table->string('source', 10)->default('manager')->comment(self::SOURCES);
            $table->string('note', 500)->nullable()
                ->comment('Комментарий менеджера: откуда сведения, от чего зависит решение');
            $table->timestamp('confirmed_at')->nullable()
                ->comment('Когда ответ последний раз заполнен или подтверждён без изменений; от этой даты считается срок актуальности (config/crm_tax_regime.php)');
            $table->foreignId('confirmed_by')->nullable()
                ->comment('Кто заполнил или подтвердил — менеджер или сам клиент (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда юрлицу впервые записан ответ');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась последний раз');
        });

        Schema::create('crm_contractor_tax_regime_history', function (Blueprint $table) {
            $table->comment('Журнал ответов о налоговом режиме юрлиц: каждое заполнение и подтверждение с автором');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('company_id')
                ->comment('Контрагент (companies.id)')
                ->constrained('companies')->cascadeOnDelete();
            $table->string('action', 10)
                ->comment("Действие: 'saved' — заполнено или изменено, 'confirmed' — подтверждено без изменений");
            $table->string('current_regime', 20)->nullable()
                ->comment('Режим сейчас на момент записи (значения — как в crm_contractor_tax_regimes.current_regime)');
            $table->string('planned_regime', 20)->nullable()
                ->comment('План на момент записи (значения — как в crm_contractor_tax_regimes.planned_regime)');
            $table->unsignedSmallInteger('planned_year')->nullable()
                ->comment('Год, к которому относился план');
            $table->string('vat_preference', 12)->nullable()->comment(self::PREFERENCES);
            $table->string('source', 10)->default('manager')->comment(self::SOURCES);
            $table->string('note', 500)->nullable()
                ->comment('Комментарий менеджера на момент записи');
            $table->foreignId('user_id')->nullable()
                ->comment('Автор записи — менеджер или клиент (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда сделана запись');

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('crm_tax_survey_prompts', function (Blueprint $table) {
            $table->comment('Приглашения клиенту пройти опрос о налогах и НДС на сайте: отложил ли он и сколько раз');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->unique()
                ->comment('Клиент — партнёр (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->timestamp('snoozed_until')->nullable()
                ->comment('До какого момента не показывать приглашение («Не сейчас»)');
            $table->unsignedTinyInteger('snooze_count')->default(0)
                ->comment('Сколько раз клиент нажал «Не сейчас»; после предела приглашения не показываются, остаётся ярлычок');
            $table->timestamp('created_at')->nullable()->comment('Когда клиент впервые отложил опрос');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась последний раз');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tax_survey_prompts');
        Schema::dropIfExists('crm_contractor_tax_regime_history');
        Schema::dropIfExists('crm_contractor_tax_regimes');
    }
};
