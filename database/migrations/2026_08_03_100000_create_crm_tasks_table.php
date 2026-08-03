<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задачи менеджеров отдела продаж: себе и коллегам.
 *
 * Существующий KanbanTask не подошёл: он про баг-репорты с сайта (page_url, browser,
 * scope), исполнителя у него нет вовсе (только строковое имя), а его API публичный
 * и без авторизации. Это другая сущность с другой моделью доступа.
 *
 * Привязка полиморфна и может отсутствовать: «позвонить в пятницу» живёт в списке
 * задач само по себе. Денормализованный client_user_id — по тому же обоснованию,
 * что и в crm_comments: лента клиента собирается одним индексированным запросом,
 * а не обходом заказов и отгрузок.
 *
 * done_at отдельно от status: «выполнена вовремя или с опозданием» — это про факт,
 * а updated_at меняется от любой правки записи и для этого не годится.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->comment('Задачи менеджеров отдела продаж: себе и коллегам, с дедлайнами (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->string('title')->comment('Что сделать — коротко, видно в списках');
            $table->text('description')->nullable()->comment('Подробности задачи');

            $table->foreignId('author_id')
                ->comment('Кто поставил задачу — сотрудник (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assignee_id')
                ->comment('Кто выполняет — сотрудник с доступом в CRM (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('client_user_id')
                ->nullable()
                ->comment('Клиент, в ленту которого попадёт задача (users.id); NULL — задача не сводится к клиенту')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('related_type')->nullable()
                ->comment('Класс привязанной сущности (App\Models\User|Order|Shipment); NULL — задача без привязки');
            $table->unsignedBigInteger('related_id')->nullable()
                ->comment('ID привязанной сущности в её таблице');

            $table->string('status', 20)->default('open')
                ->comment("Статус: 'open' — открыта, 'in_progress' — в работе, 'done' — выполнена, 'canceled' — отменена");
            $table->string('priority', 10)->default('normal')
                ->comment("Приоритет: 'low' — низкий, 'normal' — обычный, 'high' — высокий");

            $table->timestamp('due_at')->nullable()->comment('Дедлайн; NULL — задача без срока');
            $table->timestamp('done_at')->nullable()->comment('Когда фактически выполнена — для отчёта о соблюдении сроков');

            $table->timestamp('created_at')->nullable()->comment('Когда задача поставлена');
            $table->timestamp('updated_at')->nullable()->comment('Когда последний раз изменена');
            $table->softDeletes()->comment('Мягкое удаление: задача — рабочая запись, восстановимость важнее чистоты');

            $table->index(['related_type', 'related_id'], 'crm_tasks_related_idx');
            $table->index(['assignee_id', 'status', 'due_at'], 'crm_tasks_assignee_status_due_idx');
            $table->index(['client_user_id', 'created_at'], 'crm_tasks_client_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
    }
};
