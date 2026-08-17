<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личные закрепления задач (task-04): закреплённые висят сверху раздела.
 *
 * Закрепление личное — у каждого своё: то, что горит у одного менеджера,
 * для другого фон.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_pins', function (Blueprint $table) {
            $table->comment('Личные закрепления задач CRM: закреплённые показываются сверху раздела');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Кто закрепил (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('task_id')->comment('Задача (crm_tasks.id)')
                ->constrained('crm_tasks')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_pins');
    }
};
