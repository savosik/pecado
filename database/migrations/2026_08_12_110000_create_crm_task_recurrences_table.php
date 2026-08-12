<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автоповторяемые задачи: правило и журнал уже созданных вхождений.
 *
 * Правило хранит шаблон задачи, а не саму задачу: «каждый будний день в 13:30
 * обзвонить спящих» — это не задача, а станок, который их производит. Отсюда
 * две таблицы вместо колонок в crm_tasks: у правила своя жизнь (его ставят
 * на паузу, правят и отменяют), и она не должна волочить за собой историю
 * уже выполненных задач.
 *
 * `occurrence_date` в уникальном ключе — единственная защита от задвоения.
 * Планировщик может отработать дважды (ручной прогон при отладке, повтор
 * после сбоя, два воркера), и без ключа менеджер получил бы два одинаковых
 * поручения на один день. Это самая вероятная ошибка такой фичи и самая
 * заметная для пользователя.
 *
 * Отдельный `follow_up_of` в crm_tasks — про другое: следующий шаг, порождённый
 * закрытием конкретной задачи. Повтор идёт по календарю независимо от того,
 * закрыл ли менеджер предыдущую.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_recurrences', function (Blueprint $table) {
            $table->comment('Правила автоповторяемых задач: расписание и шаблон (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('author_id')
                ->comment('Кто завёл правило — сотрудник (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assignee_id')
                ->comment('Кто будет выполнять порождённые задачи (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('client_user_id')
                ->nullable()
                ->comment('Клиент, в ленту которого попадут задачи (users.id); NULL — правило не сводится к клиенту')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title')->comment('Заголовок порождаемых задач');
            $table->text('description')->nullable()->comment('Описание порождаемых задач');
            $table->string('priority', 10)->default('normal')
                ->comment("Приоритет порождаемых задач: 'low' — низкий, 'normal' — обычный, 'high' — высокий");

            $table->string('related_type')->nullable()
                ->comment('Класс привязанной сущности (App\Models\User|Order|Shipment); NULL — без привязки');
            $table->unsignedBigInteger('related_id')->nullable()
                ->comment('ID привязанной сущности в её таблице');

            // Маска дней недели вместо RRULE: «каждый третий четверг месяца,
            // кроме праздников» отделу не нужно, а разбор и интерфейс под такое
            // стоят дороже пользы. Формат оставлен расширяемым — при переходе
            // на RRULE колонка weekdays просто перестанет заполняться.
            $table->json('weekdays')
                ->comment('Дни недели по ISO-8601 (1 — понедельник … 7 — воскресенье), например [1,2,3,4,5]');
            $table->time('due_time')
                ->comment('Время дедлайна порождаемой задачи в зоне приложения, например 13:30');

            $table->date('starts_on')->comment('С какой даты правило действует');
            $table->date('ends_on')->nullable()
                ->comment('По какую дату действует; NULL — бессрочно, пока не отменят');

            $table->date('last_generated_for')->nullable()
                ->comment('Дата последнего порождённого вхождения — чтобы не перебирать историю на каждом прогоне');

            $table->boolean('is_active')->default(true)
                ->comment('Активно ли правило. Отмена цепочки — false, а не удаление: уже созданные задачи остаются в истории');

            $table->timestamp('created_at')->nullable()->comment('Когда правило заведено');
            $table->timestamp('updated_at')->nullable()->comment('Когда правило последний раз менялось');
            $table->softDeletes()->comment('Мягкое удаление правила');

            $table->index(['is_active', 'starts_on'], 'crm_task_recurrences_active_index');
            $table->index('assignee_id', 'crm_task_recurrences_assignee_index');
        });

        Schema::create('crm_task_occurrences', function (Blueprint $table) {
            $table->comment('Журнал порождённых вхождений автоповторяемых задач — защита от задвоения (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('recurrence_id')
                ->comment('Правило, породившее задачу (crm_task_recurrences.id)')
                ->constrained('crm_task_recurrences')
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->comment('Порождённая задача (crm_tasks.id)')
                ->constrained('crm_tasks')
                ->cascadeOnDelete();

            $table->date('occurrence_date')
                ->comment('Дата вхождения по расписанию — вместе с правилом образует уникальный ключ');

            $table->timestamp('created_at')->nullable()->comment('Когда вхождение порождено');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись вхождения менялась');

            // Ключ, ради которого таблица и заведена: повторный прогон
            // планировщика обязан упереться в него, а не создать дубль.
            $table->unique(['recurrence_id', 'occurrence_date'], 'crm_task_occurrences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_occurrences');
        Schema::dropIfExists('crm_task_recurrences');
    }
};
