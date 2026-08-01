<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Взаиморасчёты в разрезе наших организаций (эпик org-00, карточка org-06).
 *
 * Структура становится трёхуровневой: партнёр → контрагент клиента → наша организация.
 * Клиент платит на разные расчётные счета, поэтому должен видеть, кому именно должен.
 *
 * `contractor_balances` остаётся **агрегатом-проекцией** и не меняется: детализация
 * добавляется рядом, а не внутрь. Существующие чтения (кабинет, админка) не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_organization_balances', function (Blueprint $table) {
            $table->comment('Взаиморасчёты клиента в разрезе его контрагента и нашей организации');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Партнёр-владелец (users.id)')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->comment('Контрагент клиента (companies.id)')
                ->constrained('companies')->cascadeOnDelete();
            $table->foreignId('organization_id')->comment('Наша организация (organizations.id)')
                ->constrained('organizations')->cascadeOnDelete();
            $table->decimal('current_balance', 15, 2)->default(0)
                ->comment('Текущий баланс перед организацией: отрицательное значение — долг клиента');
            $table->decimal('overdue_debt', 15, 2)->default(0)
                ->comment('Просроченная задолженность перед организацией');
            $table->timestamp('balance_erp_updated_at')->nullable()
                ->comment('Момент актуальности данных в 1С. Сообщение старше сохранённой метки игнорируется');
            // timestamps() не проставляет комментарии — колонки заводим явно
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            // Пара (контрагент клиента, наша организация) уникальна: user_id избыточен
            // в ключе, но нужен для выборок кабинета без join к companies.
            // Имена индексов заданы явно: автоимена длиннее 64 символов, MySQL их не примет.
            $table->unique(['company_id', 'organization_id'], 'cob_company_organization_unique');
            $table->index(['user_id', 'organization_id'], 'cob_user_organization_index');
        });

        Schema::table('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('contractor_balance_id')
                ->comment('Наша организация просроченной реализации (organizations.id). NULL — не определена')
                ->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('contractor_organization_balances');
    }
};
