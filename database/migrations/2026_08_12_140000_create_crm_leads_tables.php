<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лиды: сущность, настраиваемые стадии и журнал переходов (crm-26, crm-27).
 *
 * **Лид живёт вне `users`.** `users` — зона 1С: HandlePartnerCreated/Updated
 * перезаписывают там поля при каждом `partner.updated`, и всё, что менеджер
 * завёл бы руками, рисковало бы быть затёртым. Кроме того, лид — это «имя
 * и телефон на бумажке»: требовать от него быть зарегистрированным
 * пользователем с email значило бы не дать завести его вовсе.
 *
 * Унаследованная трактовка «клиент без менеджера — лид» (см. User::scopeVisibleInCrm)
 * остаётся как есть: это другая категория, и новый раздел её не мигрирует.
 *
 * **Стадии — пользовательские данные, а не enum.** Жизненный статус клиента
 * (ClientLifecycleStatus) зашит в коде семью значениями; воронку лидов РОП
 * перестраивает под себя без релиза. Отсюда таблица, а не enum.
 *
 * **Журнал переходов пишется с первого дня.** Без него метрики crm-28 считать
 * нечего: «сколько дней лид на этапе» задним числом не восстанавливается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_stages', function (Blueprint $table) {
            $table->comment('Стадии воронки лидов — настраиваются руководителем отдела (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->string('name')->comment('Название стадии, например «Квалификация»');
            $table->string('color', 32)->default('gray')
                ->comment('Палитра Chakra для бейджа: gray, blue, green, red, purple, orange, yellow');
            $table->unsignedInteger('position')->default(0)
                ->comment('Порядок в воронке слева направо');

            // Флаги отдельно от позиции: конверсия считается по ним, а не
            // по «последней колонке» — иначе добавление стадии в конец молча
            // сломало бы метрику.
            $table->boolean('is_won')->default(false)
                ->comment('Стадия означает выигрыш — по ней считается конверсия');
            $table->boolean('is_lost')->default(false)
                ->comment('Стадия означает проигрыш — лид выбывает из воронки');
            $table->boolean('is_active')->default(true)
                ->comment('Показывать ли стадию на доске; скрытая остаётся у старых лидов');

            $table->timestamp('created_at')->nullable()->comment('Когда стадия заведена');
            $table->timestamp('updated_at')->nullable()->comment('Когда стадия менялась');

            $table->index(['is_active', 'position'], 'crm_lead_stages_board_index');
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->comment('Лиды: потенциальные клиенты до появления партнёра в 1С (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->string('name')->comment('Как зовут или как называется организация — единственное обязательное поле');

            // Обязателен хотя бы один контакт, но не конкретный: лид с одним
            // телефоном должен заводиться в два поля и десять секунд. Правило
            // «любой из трёх» живёт в валидации формы, а не в схеме.
            $table->string('phone')->nullable()->comment('Телефон; NULL — контакт задан иначе');
            $table->string('email')->nullable()->comment('Email; NULL — контакт задан иначе');
            $table->string('messenger')->nullable()->comment('Мессенджер или соцсеть; NULL — контакт задан иначе');

            $table->string('company_name')->nullable()->comment('Название организации лида, если известно');
            $table->string('source')->nullable()->comment('Откуда пришёл: выставка, сайт, рекомендация, холодный звонок');

            $table->foreignId('manager_id')
                ->nullable()
                ->comment('Ответственный менеджер (personal_managers.id); NULL — лид ничей, его надо разобрать')
                ->constrained('personal_managers')
                ->nullOnDelete();

            $table->foreignId('stage_id')
                ->nullable()
                ->comment('Текущая стадия воронки (crm_lead_stages.id)')
                ->constrained('crm_lead_stages')
                ->nullOnDelete();

            $table->decimal('qualified_amount', 12, 2)->nullable()
                ->comment('Во сколько оценён лид, в валюте currency_code; NULL — ещё не квалифицирован');
            $table->string('currency_code', 3)->nullable()->comment('Валюта оценки (currencies.code)');
            $table->date('expected_close_at')->nullable()->comment('Ожидаемая дата закрытия сделки');

            // Те же смысловые поля, что в профиле клиента: лид должен
            // заполняться не хуже партнёра.
            $table->string('decision_maker')->nullable()->comment('ЛПР: кто принимает решение');
            $table->text('interests')->nullable()->comment('Что интересует: бренды, категории, объёмы');
            $table->text('notes')->nullable()->comment('Свободные заметки менеджера (Markdown)');

            $table->string('lost_reason')->nullable()->comment('Почему проиграли; заполняется при переводе в проигрышную стадию');

            $table->foreignId('converted_user_id')
                ->nullable()
                ->comment('Партнёр, в которого лид превратился (users.id). Партнёра создаёт 1С, поэтому привязка ручная')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('stage_changed_at')->nullable()
                ->comment('Когда лид попал на текущую стадию — «сколько дней на этапе» считается отсюда');

            $table->timestamp('created_at')->nullable()->comment('Когда лид заведён');
            $table->timestamp('updated_at')->nullable()->comment('Когда лид менялся');
            $table->softDeletes()->comment('Мягкое удаление лида');

            $table->index(['manager_id', 'stage_id'], 'crm_leads_board_index');
            $table->index('stage_id', 'crm_leads_stage_index');
        });

        Schema::create('crm_lead_stage_transitions', function (Blueprint $table) {
            $table->comment('Журнал переходов лидов по стадиям — источник метрик воронки (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('lead_id')
                ->comment('Лид (crm_leads.id)')
                ->constrained('crm_leads')
                ->cascadeOnDelete();

            $table->foreignId('from_stage_id')
                ->nullable()
                ->comment('Откуда перешёл (crm_lead_stages.id); NULL — первое попадание в воронку')
                ->constrained('crm_lead_stages')
                ->nullOnDelete();

            $table->foreignId('to_stage_id')
                ->nullable()
                ->comment('Куда перешёл (crm_lead_stages.id)')
                ->constrained('crm_lead_stages')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->comment('Кто передвинул (users.id); NULL — переход сделан системой')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('moved_at')->comment('Когда переход произошёл');

            // Длительность предыдущего этапа считается при переходе: искать
            // парную запись в истории каждый раз дороже, чем записать сразу.
            $table->unsignedInteger('previous_stage_hours')->nullable()
                ->comment('Сколько часов лид провёл на предыдущей стадии; NULL для первого перехода');

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись менялась');

            $table->index(['lead_id', 'moved_at'], 'crm_lead_transitions_lead_index');
            $table->index(['to_stage_id', 'moved_at'], 'crm_lead_transitions_stage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_stage_transitions');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_lead_stages');
    }
};
