<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Возражения работника по расчёту (эпик mot-00, карточка mot-31; п. 11.3 Положения).
 *
 * Работник вправе заявить возражение в срок после утверждения расчёта. Возражение
 * не меняет сумму: оно адресуется руководителю, который либо переоткрывает месяц
 * новой версией, либо отвечает отказом с обоснованием. И то, и другое остаётся
 * в истории — иначе спор о цифре повторяется каждый месяц заново.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_objections', function (Blueprint $table) {
            $table->comment('Возражения работников по расчёту оплаты труда: причина, ответ руководителя, статус');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('calculation_id')->comment('Снимок расчёта, по которому заявлено возражение (payroll_calculations.id)')->constrained('payroll_calculations')->cascadeOnDelete();
            $table->foreignId('personal_manager_id')->comment('Работник (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->comment('Кто подал (users.id)')->constrained('users')->nullOnDelete();
            $table->text('reason')->comment('Причина возражения словами работника');
            $table->string('status', 20)->default('open')->comment("Статус: 'open' — ждёт ответа, 'accepted' — принято, месяц переоткрыт новой версией, 'rejected' — отклонено с обоснованием");
            $table->text('response')->nullable()->comment('Ответ руководителя');
            $table->foreignId('responded_by')->nullable()->comment('Кто ответил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable()->comment('Когда дан ответ');
            $table->timestamp('created_at')->nullable()->comment('Когда подано');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->index(['personal_manager_id', 'status'], 'motivation_objections_manager_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_objections');
    }
};
