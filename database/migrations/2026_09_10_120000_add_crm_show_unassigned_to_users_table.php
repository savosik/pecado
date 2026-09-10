<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галочка «Нераспределённые» у сотрудника CRM.
 *
 * С v16.10.0 персонального менеджера 1С не присылает: партнёр без менеджера —
 * лид отдела, которого РОП закрепляет из карточки. По умолчанию таких партнёров
 * никому не показываем (у менеджера свой список, лишний хвост ему ни к чему);
 * галочка рядом с «Только мои» включает их во все разделы CRM разом — списки,
 * счётчики, поиск, открытие карточки. Настройка серверная, а не в localStorage:
 * ей подчиняется граница видимости, а не только фокус экрана.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('crm_show_unassigned')->default(false)->after('personal_manager_id')
                ->comment('CRM, сотрудник: 1 — показывать партнёров без персонального менеджера (лидов) во всех разделах; действует только с правом crm-department.view');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('crm_show_unassigned');
        });
    }
};
