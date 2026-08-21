<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо становится общим потоком: и написанное менеджером, и собранное системой.
 *
 * До этой миграции `crm_emails` хранил только переписку менеджеров. Теперь сюда же
 * попадают письма, собранные по поводу (изменился заказ, выложен акт сверки, подошёл
 * срок оплаты), и различаются они колонкой `origin`, а не отдельной таблицей: для
 * менеджера это один список с одним самолётиком.
 *
 * `origin_key` — ключ склейки. 1С правит заказ построчно, и без склейки серия правок
 * одного заказа дала бы десяток писем вместо одного.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->string('origin', 16)->default('manual')->after('client_user_id')
                ->comment("Кто составил письмо: 'manual' — менеджер руками, 'system' — собрано по поводу");

            $table->string('origin_event')->nullable()->after('origin')
                ->comment('Ключ повода из реестра событий (orders.items_updated, documents.published); NULL — письмо менеджера');

            $table->string('origin_key')->nullable()->after('origin_event')
                ->comment('Ключ склейки: повторный повод с тем же ключом в окне склейки дописывается в то же письмо, а не создаёт новое');

            $table->json('origin_data')->nullable()->after('origin_key')
                ->comment('Числа повода: суммы, дни просрочки, количество позиций — по ним работают условия правил');

            $table->json('tags')->nullable()->after('origin_data')
                ->comment('Метки письма — то, за что цепляются правила-фильтры; сравниваются целиком, а не подстрокой');

            $table->unsignedBigInteger('auto_sent_rule_id')->nullable()->after('error')
                ->comment('Правило, отправившее письмо автоматически (crm_mail_rules.id); NULL — отправил человек');

            $table->string('skip_reason')->nullable()->after('auto_sent_rule_id')
                ->comment('Почему письмо не ушло автоматически: стоп-лист, частота, возраст, нет получателей');

            $table->index('origin_key', 'crm_emails_origin_key_idx');
            $table->index(['status', 'created_at'], 'crm_emails_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropIndex('crm_emails_origin_key_idx');
            $table->dropIndex('crm_emails_status_created_idx');
            $table->dropColumn([
                'origin',
                'origin_event',
                'origin_key',
                'origin_data',
                'tags',
                'auto_sent_rule_id',
                'skip_reason',
            ]);
        });
    }
};
