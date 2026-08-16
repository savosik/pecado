<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отсутствия менеджеров отдела продаж (эпик abs-00).
 *
 * Одна запись = один непрерывный период отсутствия (отпуск, отгул, больничный,
 * прогул). Замещающий указывается здесь же: на время отсутствия кабинеты клиентов
 * показывают его контакты, а письма о заказах уходят на его email (резолв —
 * `ManagerAbsenceResolver`). `users.personal_manager_id` при этом не меняется —
 * подмена только на чтении.
 *
 * Табель рабочего времени — производная этой таблицы и производственного
 * календаря (`config/production_calendar.php`): «работал» = нет записи об
 * отсутствии, отдельных дневных отметок нет.
 *
 * После прод-миграции — `bi:sync-grants`, иначе ИИ-агент менеджеров таблицу не увидит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_absences', function (Blueprint $table) {
            $table->comment('Отсутствия менеджеров отдела продаж: отпуска, отгулы, больничные, прогулы; источник данных для замещений в кабинетах клиентов и табеля');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('personal_manager_id')
                ->comment('Отсутствующий менеджер — карточка (personal_managers.id), не учётка')
                ->constrained('personal_managers')->cascadeOnDelete();

            $table->foreignId('substitute_manager_id')->nullable()
                ->comment('Замещающий менеджер (personal_managers.id); NULL — без замещения: кабинеты клиентов и письма о заказах не переключаются')
                ->constrained('personal_managers')->nullOnDelete();

            $table->string('type', 20)
                ->comment("Тип: 'vacation' — отпуск, 'day_off' — отгул, 'sick_leave' — больничный, 'truancy' — прогул");

            $table->date('starts_on')->comment('Первый день отсутствия, включительно');
            $table->date('ends_on')->comment('Последний день отсутствия, включительно; досрочный выход = сдвиг этой даты назад');

            $table->string('comment')->nullable()->comment('Комментарий РОПа: причина, номер приказа и т.п.');

            $table->foreignId('created_by')->nullable()
                ->comment('Кто внёс запись (users.id, учётка CRM)')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Дата создания записи; для прогула задним числом фиксирует момент отметки');
            $table->timestamp('updated_at')->nullable()->comment('Дата последнего изменения');

            $table->index(['personal_manager_id', 'starts_on', 'ends_on'], 'manager_absences_manager_period_idx');
            $table->index(['substitute_manager_id', 'starts_on', 'ends_on'], 'manager_absences_substitute_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_absences');
    }
};
