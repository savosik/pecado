<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рассылки и кампании.
 *
 * Отличие от остального пульта принципиальное: всё предыдущее реактивно
 * и транзакционно — произошло событие, ушло письмо тому, кого оно касается.
 * Кампания инициируется человеком и является рекламой. Отсюда другие
 * требования: согласие получателя, заголовок List-Unsubscribe, отправка
 * порциями.
 *
 * Прецедент в проекте есть: кампания черновиков OTOUCH (2026-08-18) — 207
 * персональных писем через агентское API. Механика оказалась переиспользуемой,
 * но каждый раз собиралась вручную.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->comment('Рассылки и кампании: письмо по сегменту клиентов, инициированное сотрудником');

            $table->id()->comment('Первичный ключ');
            $table->string('name', 191)->comment('Название кампании для списка');
            $table->text('description')->nullable()->comment('Пояснение: зачем кампания и что в ней');

            $table->json('segment')->nullable()->comment('Отбор аудитории: те же фильтры, что в списке партнёров, плюс роли контактов');

            $table->string('subject', 512)->comment('Тема письма');
            $table->longText('body_html')->comment('Тело письма в HTML с плейсхолдерами {{client_name}}, {{contact_name}}');
            $table->foreignId('crm_email_template_id')->nullable()
                ->comment('Шаблон, из которого собрано письмо (crm_email_templates.id)')
                ->constrained('crm_email_templates')->nullOnDelete();

            $table->string('status', 20)->default('draft')->comment("Статус: 'draft' — черновик, 'scheduled' — запланирована, 'sending' — отправляется, 'sent' — отправлена, 'cancelled' — отменена");
            $table->timestamp('scheduled_at')->nullable()->comment('Когда запустить отправку');
            $table->timestamp('started_at')->nullable()->comment('Когда отправка началась');
            $table->timestamp('finished_at')->nullable()->comment('Когда отправка завершилась');

            $table->unsignedInteger('recipients_total')->default(0)->comment('Сколько адресатов в аудитории');
            $table->unsignedInteger('recipients_sent')->default(0)->comment('Скольким письмо отправлено');
            $table->unsignedInteger('recipients_skipped')->default(0)->comment('Сколько пропущено: нет согласия, стоп-лист, некорректный адрес');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Кто создал кампанию (users.id)')->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда кампания создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда кампания изменена');

            $table->index(['status', 'scheduled_at'], 'notification_campaigns_status_idx');
        });

        Schema::create('notification_campaign_recipients', function (Blueprint $table) {
            $table->comment('Адресаты кампании: кому именно ушло письмо и что с ним стало');

            $table->id()->comment('Первичный ключ');
            // Имена внешних ключей задаются явно: автогенерируемое
            // notification_campaign_recipients_notification_campaign_id_foreign
            // не влезает в 64 символа, которыми MySQL ограничивает идентификатор.
            $table->foreignId('notification_campaign_id')
                ->comment('Кампания (notification_campaigns.id)')
                ->constrained('notification_campaigns', indexName: 'ncr_campaign_fk')->cascadeOnDelete();

            $table->foreignId('client_user_id')->nullable()
                ->comment('Партнёр, к которому относится адресат (users.id)')
                ->constrained('users', indexName: 'ncr_client_fk')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()
                ->comment('Контакт адресной книги (client_contacts.id)')
                ->constrained('client_contacts', indexName: 'ncr_contact_fk')->nullOnDelete();

            $table->string('email', 191)->comment('Адрес получателя');
            $table->string('status', 20)->default('pending')->comment("Статус: 'pending' — в очереди, 'sent' — отправлено, 'skipped' — пропущено, 'failed' — ошибка");
            $table->string('skip_reason', 32)->nullable()->comment('Причина пропуска — те же значения, что в журнале доставок пульта');

            $table->foreignId('notification_delivery_id')->nullable()
                ->comment('Решение пульта по этому адресату (notification_deliveries.id)')
                ->constrained('notification_deliveries', indexName: 'ncr_delivery_fk')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда адресат добавлен');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');

            // Один адрес в кампании один раз: аудитория может пересечься
            // по контактам, и дважды одному человеку реклама не уходит
            $table->unique(['notification_campaign_id', 'email'], 'notification_campaign_recipients_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_recipients');
        Schema::dropIfExists('notification_campaigns');
    }
};
