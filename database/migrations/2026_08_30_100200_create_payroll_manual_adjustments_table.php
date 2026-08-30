<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручные строки дохода менеджера за месяц (эпик pay-00, карточка pay-01).
 *
 * Два потребителя: компонент «Доп. доход» (ТГ-каналы, рассылки — позиции с
 * количеством и ценой) и «Корректировка РОПа» (плюс/минус с основанием).
 * Сумма хранится явно: итог зарплаты не должен зависеть от округления на чтении.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_manual_adjustments', function (Blueprint $table) {
            $table->comment('Ручные строки дохода менеджера за месяц: позиции доп. дохода и корректировки РОПа');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('personal_manager_id')->comment('Менеджер (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->date('period_month')->comment('Месяц начисления (1-е число)');
            $table->string('component_key', 40)->comment("Кто потребляет строку: 'extra_income' — доп. доход, 'manual_correction' — корректировка РОПа");
            $table->string('label')->comment('Название позиции: «ТГ-канал», «Рассылка», «Удержание за …»');
            $table->decimal('qty', 10, 2)->default(1)->comment('Количество');
            $table->decimal('price', 12, 2)->comment('Цена за единицу, ₽; у корректировок может быть отрицательной');
            $table->decimal('amount', 14, 2)->comment('Сумма = qty × price, ₽; хранится явно');
            $table->string('comment')->nullable()->comment('Основание');
            $table->foreignId('author_id')->nullable()->comment('Кто добавил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('Когда создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда менялась');

            $table->index(['personal_manager_id', 'period_month'], 'payroll_adjustments_manager_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_manual_adjustments');
    }
};
