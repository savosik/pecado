<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ступень долга по паре партнёр × контрагент (эпик debt-00 v2, карточка debt-02).
 *
 * Строка с company_id = NULL — сводная по партнёру: худшая из его контрагентов
 * плюс проверка «просрочка почти весь долг» для стоп-отгрузки. Гейт чекаута
 * читает строку контрагента, кабинет и CRM — строку партнёра.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_states', function (Blueprint $table) {
            $table->comment('Ступень лестницы долга по паре партнёр × контрагент; company_id NULL — сводка по партнёру');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->comment('Партнёр (users.id)')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент клиента (companies.id); NULL — сводная строка партнёра')->constrained('companies')->cascadeOnDelete();
            $table->string('level', 20)->default('clean')->comment("Ступень: 'clean' — чисто, 'overdue' — просрочка, 'no_preorders' — предзаказы закрыты, 'no_orders' — заказы контрагента закрыты, 'hold' — стоп всех заказов партнёра");
            $table->string('previous_level', 20)->nullable()->comment('Ступень до последнего перехода — для истории и писем');
            $table->date('since')->nullable()->comment('С какой даты действует текущая ступень (начало эпизода просрочки у ступени overdue)');
            $table->timestamp('level_changed_at')->nullable()->comment('Когда ступень изменилась последний раз');
            $table->decimal('overdue_amount', 14, 2)->default(0)->comment('Значимая просрочка, ₽: непогашенные строки старше льготного периода, без заказов');
            $table->decimal('overdue_total', 14, 2)->default(0)->comment('Вся просрочка, ₽, включая строки внутри льготного периода');
            $table->decimal('debt_amount', 14, 2)->default(0)->comment('Весь долг по регистру, ₽ (только у сводной строки партнёра)');
            $table->date('oldest_due_date')->nullable()->comment('Самый старый срок оплаты среди значимой просрочки');
            $table->unsignedSmallInteger('age_days')->default(0)->comment('Возраст самой старой просроченной строки, дней');
            $table->unsignedSmallInteger('lines_count')->default(0)->comment('Сколько просроченных строк регистра');
            $table->string('reason', 255)->nullable()->comment('Почему такая ступень — одной строкой, для менеджера и клиента');
            $table->boolean('is_stale')->default(false)->comment('Баланс 1С устарел — ужесточение запрещено');
            $table->boolean('dry_run')->default(true)->comment('Теневой расчёт: ступень посчитана, действий не было');
            $table->timestamp('computed_at')->nullable()->comment('Когда посчитано');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась последний раз');

            $table->unique(['user_id', 'company_id'], 'debt_states_pair_unique');
            $table->index(['level', 'dry_run'], 'debt_states_level_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_states');
    }
};
