<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разблокировка до даты — единственная ручка лестницы долга (карточка debt-05).
 *
 * «Клиент обещал оплатить до …»: пока запись действует, гейт снят, ступень
 * не ужесточается, но остаётся видна. Бессрочной нет: менеджер ≤ 14 дней,
 * РОП ≤ 30. Продление — новая запись, история остаётся в карточке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_pauses', function (Blueprint $table) {
            $table->comment('Разблокировки лестницы долга до даты (обещания оплаты)');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Партнёр (users.id)');
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->comment('Контрагент (companies.id); NULL — разблокировка на партнёра целиком');
            $table->date('until')->comment('Действует по эту дату включительно');
            $table->string('reason', 500)->comment('Причина — обязательна: что обещал клиент');
            $table->foreignId('created_by')->constrained('users')->comment('Кто поставил (users.id сотрудника)');
            $table->timestamp('released_at')->nullable()->comment('Когда снята досрочно или истекла; NULL — действует');
            $table->string('released_reason', 100)->nullable()->comment("Как снята: 'expired' — истекла, 'manual' — снята сотрудником, 'paid' — долг погашен");
            $table->timestamps();

            $table->index(['user_id', 'until'], 'debt_pauses_user_until_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_pauses');
    }
};
