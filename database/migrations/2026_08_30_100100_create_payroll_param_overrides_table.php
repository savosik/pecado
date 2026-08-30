<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отклонения параметров зарплаты по менеджеру (эпик pay-00, карточка pay-01).
 *
 * Строка = отклонение от нижнего слоя, и только отличающиеся ключи: схема →
 * постоянные параметры менеджера → параметры на месяц. Совпадение с нижним слоем
 * удаляет строку (как notification_preferences), поэтому «вернуть к умолчанию» —
 * это не запись, а отсутствие записи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_param_overrides', function (Blueprint $table) {
            $table->comment('Отклонения параметров зарплаты по менеджеру: постоянные (period_month = 1970-01-01) и на месяц; только отличающиеся ключи');
            $table->id()->comment('Первичный ключ');
            $table->foreignId('personal_manager_id')->comment('Менеджер (personal_managers.id) — карточка, а не учётка сотрудника')->constrained('personal_managers')->cascadeOnDelete();
            $table->date('period_month')->comment("Месяц отклонения (1-е число); '1970-01-01' — постоянное отклонение менеджера (NULL сломал бы unique в MySQL)");
            $table->string('component_key', 40)->comment("Ключ компонента из config/payroll.php: 'salary', 'kpi_bonus', …");
            $table->json('params')->comment('Только отличающиеся от нижнего слоя параметры; вложенные объекты (лестницы, ступени) заменяются целиком');
            $table->foreignId('updated_by_user_id')->nullable()->comment('Кто менял (users.id)')->constrained('users')->nullOnDelete();
            $table->string('comment')->nullable()->comment('Пояснение к отклонению: «испытательный срок», «сезонная база»');
            $table->timestamp('created_at')->nullable()->comment('Когда создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда менялась');

            $table->unique(['personal_manager_id', 'period_month', 'component_key'], 'payroll_param_overrides_scope_uniq');
            $table->index('period_month', 'payroll_param_overrides_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_param_overrides');
    }
};
