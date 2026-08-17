<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Соисполнители, личный контроль и трудоёмкость задач CRM (task-01).
 *
 * `assignee_id` остаётся единственным ответственным за срок — на нём индексы,
 * каунтеры и покрытие партнёров. Соисполнители и контролёры — pivot-таблицы поверх.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_assignees', function (Blueprint $table) {
            $table->comment('Соисполнители задач CRM (помимо ответственного crm_tasks.assignee_id)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('task_id')->comment('Задача (crm_tasks.id)')
                ->constrained('crm_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Соисполнитель (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            // Обратный обход «задачи, где я соисполнитель» — для пресета «Мне».
            $table->index(['user_id', 'task_id']);
        });

        Schema::create('crm_task_watchers', function (Blueprint $table) {
            $table->comment('Личный контроль: кто наблюдает за задачей, не будучи исполнителем');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('task_id')->comment('Задача (crm_tasks.id)')
                ->constrained('crm_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('Контролёр (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'task_id']);
        });

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimate_minutes')->nullable()->after('due_at')
                ->comment('Плановая трудоёмкость в минутах; null — не оценена');
        });

        Schema::table('crm_task_recurrences', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimate_minutes')->nullable()->after('priority')
                ->comment('Плановая трудоёмкость порождаемых задач в минутах; null — не оценена');
        });
    }

    public function down(): void
    {
        Schema::table('crm_task_recurrences', function (Blueprint $table) {
            $table->dropColumn('estimate_minutes');
        });

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropColumn('estimate_minutes');
        });

        Schema::dropIfExists('crm_task_watchers');
        Schema::dropIfExists('crm_task_assignees');
    }
};
