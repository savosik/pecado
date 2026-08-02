<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Жизненный статус клиента — управляемый сайтом, в отличие от статуса лояльности.
 *
 * users.client_status_id (лояльность: базовый/VIP/золотой) перезаписывается
 * HandlePartnerUpdated при каждом сообщении partner.updated из 1С. Статус, выставленный
 * менеджером в той колонке, был бы затёрт молча и без следа — поэтому лояльность в CRM
 * только читается, а управляемая стадия работы живёт здесь, в зоне владения сайта.
 *
 * Дефолт 'active': на проде менеджер закреплён почти за всеми клиентами, и объявить
 * их всех лидами было бы неверно. Реальную раскладку менеджеры проставят сами,
 * опираясь на подсказки crm:lifecycle-hints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->string('lifecycle_status', 20)->default('active')
                ->comment("Жизненный статус: 'lead' — лид, 'in_work' — в работе, 'active' — активен, 'sleeping' — спящий, 'churned' — закрылся/ушёл");
            $table->timestamp('lifecycle_changed_at')->nullable()
                ->comment('Когда жизненный статус менялся в последний раз');
            $table->foreignId('lifecycle_changed_by')->nullable()
                ->comment('Кто менял жизненный статус последним (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            // Подсказка ночной команды. Статус ею не меняется: «договорились, что он
            // вернётся в марте» — управленческое решение менеджера, и молчаливая
            // ночная перезапись такого решения хуже, чем его отсутствие.
            $table->string('lifecycle_hint', 20)->nullable()
                ->comment('Предлагаемый системой статус (значения те же, что у lifecycle_status); NULL — предложений нет');
            $table->string('lifecycle_hint_reason')->nullable()
                ->comment('Обоснование подсказки словами: «нет отгрузок 90 дней»');
            $table->timestamp('lifecycle_hint_at')->nullable()
                ->comment('Когда подсказка посчитана');

            $table->index('lifecycle_status', 'crm_client_profiles_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_client_profiles', function (Blueprint $table) {
            $table->dropIndex('crm_client_profiles_lifecycle_idx');
            $table->dropConstrainedForeignId('lifecycle_changed_by');
            $table->dropColumn([
                'lifecycle_status',
                'lifecycle_changed_at',
                'lifecycle_hint',
                'lifecycle_hint_reason',
                'lifecycle_hint_at',
            ]);
        });
    }
};
