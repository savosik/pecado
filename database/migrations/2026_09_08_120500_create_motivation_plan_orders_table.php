<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приказы об установлении Личных планов на квартал (эпик mot-00, карточка mot-20; п. 5.2).
 *
 * Хранит обоснование плана, а не сам план: утверждение приказа записывает значения
 * в crm_sales_plans (target_type = 'manager') — единственное место, откуда план читают
 * все экраны CRM. Второй правды о плане не заводим.
 *
 * Новая методика считает снизу вверх — медиана отгрузок за отработанный рабочий день ×
 * рабочие дни × сезон × прирост, — тогда как действующие планы поставлены сверху вниз
 * от цифры компании и расходятся с формульными до двукратного. Поэтому в приказе хранится
 * previous_values: руководитель утверждает план, видя, чем он отличается от прежнего,
 * а не вместо него.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivation_plan_orders', function (Blueprint $table) {
            $table->comment('Приказы о Личных планах на квартал: обоснование расчёта, сравнение с прежней методикой, вето и ограничитель снижения');
            $table->id()->comment('Первичный ключ');
            $table->date('quarter_start')->comment('Квартал: 1-е число первого месяца квартала');
            $table->foreignId('personal_manager_id')->comment('Работник (personal_managers.id)')->constrained('personal_managers')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1)->comment('Версия расчёта в паре работник × квартал');
            $table->decimal('median_per_day', 15, 2)->default(0)->comment('Медиана отгрузок за отработанный рабочий день, ₽ (п. 10.5: дни отсутствия исключены)');
            $table->json('working_days')->comment('Рабочие дни по каждому месяцу квартала с учётом отсутствий');
            $table->json('seasonal')->comment('Сезонные коэффициенты по месяцам квартала');
            $table->decimal('growth_rate', 8, 5)->default(0)->comment('Целевой прирост, доля от 1');
            $table->decimal('overperformance_carry', 15, 2)->nullable()->comment('Учтённая доля перевыполнения прошлого квартала (п. 5.4), ₽');
            $table->decimal('base_change', 15, 2)->nullable()->comment('Изменение плана по составу базы (п. 5.5), ₽');
            $table->decimal('previous_quarter_total', 15, 2)->nullable()->comment('План предыдущего квартала, ₽ — база для ограничителя снижения');
            $table->boolean('decline_limited')->default(false)->comment('Применён ли предел снижения плана 20 % за квартал (Приложение № 2, п. 1)');
            $table->string('decline_limit_waived_reason', 255)->nullable()->comment('Основание снятия предела: документально оформленная передача партнёров или прекращение их деятельности');
            $table->json('values')->comment('Итоговые значения плана по каждому месяцу квартала, ₽');
            $table->json('previous_values')->nullable()->comment('Что было по прежней методике — для сравнения при утверждении');
            $table->string('status', 10)->default('draft')->comment("Статус: 'draft' — черновик мастера, 'approved' — утверждён и записан в crm_sales_plans");
            $table->string('comment', 500)->nullable()->comment('Обоснование расхождения с прежним планом');
            $table->foreignId('author_id')->nullable()->comment('Кто считал (users.id)')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->comment('Кто утвердил (users.id)')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->comment('Когда утверждён');
            $table->timestamp('created_at')->nullable()->comment('Когда строка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда строка менялась');

            $table->unique(['quarter_start', 'personal_manager_id', 'version'], 'motivation_plan_orders_version_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivation_plan_orders');
    }
};
