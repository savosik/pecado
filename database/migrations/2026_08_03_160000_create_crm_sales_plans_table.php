<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Месячные планы продаж отдела: по отделу, менеджеру и клиенту.
 *
 * period_month — дата с первым числом месяца, а не пара year/month и не строка
 * '2026-08': так период сравнивается и сортируется одним оператором, диапазоны
 * работают штатно, а unique-индекс защищает от дублей без составной логики.
 *
 * target_id указывает в две разные таблицы (personal_managers для 'manager',
 * users для 'client') — поэтому FK на него нет. Осознанный полиморфизм без morphs():
 * типов ровно три и один вообще без цели. Целостность проверяется в FormRequest
 * и сервисе.
 *
 * Для 'department' в target_id пишется 0, а не NULL: MySQL считает NULL-ы в unique
 * различными, и план отдела продублировался бы, несмотря на индекс.
 *
 * Валюта — рубли, отдельной колонки нет намеренно: план в одной валюте против
 * факта в другой (AnalyticsContext::forScope(..., null) считает в рублях) —
 * источник тихих ошибок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_plans', function (Blueprint $table) {
            $table->comment('Месячные планы продаж отдела: по отделу, менеджеру и клиенту (CRM)');

            $table->id()->comment('Первичный ключ');

            $table->date('period_month')
                ->comment('Месяц плана — всегда первое число месяца (2026-08-01)');

            $table->string('target_type', 20)
                ->comment("Кому план: 'department' — всему отделу, 'manager' — менеджеру, 'client' — клиенту");

            $table->unsignedBigInteger('target_id')->default(0)
                ->comment('Кому именно: personal_managers.id для manager, users.id для client, 0 для department');

            $table->decimal('amount', 15, 2)
                ->comment('План выручки за месяц в рублях — сравнивается с фактом по отгрузкам (erp_created_at)');

            $table->foreignId('author_id')
                ->nullable()
                ->comment('Кто поставил план — сотрудник (users.id); NULL — автор удалён')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('comment')->nullable()
                ->comment('Пояснение к плану: «сезон», «клиент обещал контракт»');

            $table->timestamp('created_at')->nullable()->comment('Когда план заведён');
            $table->timestamp('updated_at')->nullable()->comment('Когда последний раз изменён');

            // Повтор за тот же период должен обновлять план, а не заводить второй:
            // два плана на один месяц сделали бы любой отчёт неоднозначным.
            $table->unique(['period_month', 'target_type', 'target_id'], 'crm_sales_plans_period_target_uniq');
            $table->index('period_month', 'crm_sales_plans_period_idx');
            $table->index(['target_type', 'target_id'], 'crm_sales_plans_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_plans');
    }
};
