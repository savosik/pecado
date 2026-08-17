<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чек-листы задач CRM (task-02): плоские todo-пункты внутри задачи.
 *
 * Не подзадачи — без исполнителей, сроков и рекурсии: «обзвонить 5 партнёров»
 * превращается в 5 галочек, а не в 5 задач.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_checklist_items', function (Blueprint $table) {
            $table->comment('Пункты чек-листа задачи CRM');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('task_id')->comment('Задача (crm_tasks.id)')
                ->constrained('crm_tasks')->cascadeOnDelete();
            $table->string('title', 500)->comment('Текст пункта');
            $table->unsignedSmallInteger('position')->comment('Порядок в списке');
            $table->boolean('is_done')->default(false)->comment('Пункт выполнен');
            $table->foreignId('done_by_id')->nullable()->comment('Кто отметил (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable()->comment('Когда отмечен');
            $table->timestamps();
            $table->index(['task_id', 'position']);
        });

        Schema::table('crm_task_recurrences', function (Blueprint $table) {
            $table->json('checklist')->nullable()->after('estimate_minutes')
                ->comment('Шаблон чек-листа (массив строк) — копируется в каждую порождённую задачу');
        });
    }

    public function down(): void
    {
        Schema::table('crm_task_recurrences', function (Blueprint $table) {
            $table->dropColumn('checklist');
        });

        Schema::dropIfExists('crm_task_checklist_items');
    }
};
