<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Демонтаж пульта уведомлений (карточка mail-10).
 *
 * Подход заменён потоком писем: письмо — одна сущность, правила фильтруют письма,
 * а не события. Пульт до последнего оставался в теневом режиме как страховка
 * и за всё время не отправил ни одного письма, поэтому терять здесь нечего.
 *
 * `notification_suppressions` **остаётся**: стоп-лист адресов нужен и новому
 * потоку — на него смотрит проверка перед автоотправкой, и в него попадают
 * адреса, отвергнутые почтовым сервером.
 *
 * `down()` восстанавливает только структуру, не данные. Возврат к пульту
 * означал бы возврат кода, которого больше нет, поэтому обратная миграция —
 * страховка от «не туда накатили», а не способ отката решения.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropDeliveryColumn();

        // Стоп-лист остаётся, но ссылку на карточку контакта теряет:
        // адресной книги больше нет, а запрет на адрес от этого не слабеет.
        $this->dropSuppressionContactColumn();

        // Порядок важен: сначала зависимые таблицы, потом те, на которые
        // они ссылаются.
        foreach ([
            'notification_campaign_recipients',
            'notification_campaigns',
            'notification_deliveries',
            'notification_signals',
            'notification_rule_recipients',
            'notification_rules',
            'client_contacts',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        // Права пульта: снимаем с ролей и удаляем сами разрешения.
        // Оставленное право без раздела — это строка в матрице ролей,
        // на которую администратор жмёт и ничего не происходит.
        $prefixes = ['crm-notifications.', 'crm-notifications-all.', 'crm-notification-contacts.'];

        $ids = DB::table('permissions')
            ->where(function ($query) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('name', 'like', $prefix.'%');
                }
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    /**
     * Убрать из стоп-листа ссылку на карточку контакта.
     *
     * Та же история, что и с журналом писем: SQLite не даёт удалить столбец,
     * упомянутый во внешнем ключе, и не умеет удалять сам внешний ключ.
     */
    private function dropSuppressionContactColumn(): void
    {
        if (! Schema::hasColumn('notification_suppressions', 'contact_id')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('notification_suppressions', function (Blueprint $table) {
                $table->dropForeign(['contact_id']);
                $table->dropColumn('contact_id');
            });

            return;
        }

        Schema::create('notification_suppressions_rebuilt', function (Blueprint $table) {
            $table->comment('Стоп-лист адресов: отписки по ссылке, жалобы на спам и жёсткие отказы почтового сервера');

            $table->id()->comment('Первичный ключ');
            $table->string('email', 191)->comment('Адрес, на который не отправляем');
            $table->string('scope', 64)->default('all')
                ->comment("Область запрета: 'all' — вообще ничего, 'marketing' — только рассылки, либо ключ повода");
            $table->string('reason', 32)
                ->comment("Причина: 'unsubscribed' — отписался по ссылке, 'bounce' — почтовый сервер отверг адрес, 'complaint' — жалоба на спам, 'manual' — внёс сотрудник");
            $table->foreignId('user_id')->nullable()
                ->comment('Пользователь сайта с этим адресом (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->text('note')->nullable()->comment('Пояснение сотрудника или текст отказа почтового сервера');
            $table->timestamp('expires_at')->nullable()->comment('До какого момента действует запрет; NULL — бессрочно');
            $table->timestamp('created_at')->nullable()->comment('Когда адрес попал в стоп-лист');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');
        });

        DB::statement('insert into notification_suppressions_rebuilt (id, email, scope, reason, user_id, note, expires_at, created_at, updated_at)
            select id, email, scope, reason, user_id, note, expires_at, created_at, updated_at from notification_suppressions');

        Schema::drop('notification_suppressions');
        Schema::rename('notification_suppressions_rebuilt', 'notification_suppressions');

        Schema::table('notification_suppressions', function (Blueprint $table) {
            $table->unique(['email', 'scope'], 'notification_suppressions_email_scope_uniq');
        });
    }

    /**
     * Убрать из журнала писем ссылку на решение пульта.
     *
     * SQLite отдельной веткой не из вредности: он запрещает удалять столбец,
     * упомянутый во внешнем ключе, а удалить сам внешний ключ не умеет вовсе.
     * Единственный законный способ — пересоздать таблицу; в тестах она на этот
     * момент пуста, но данные всё равно переносим, чтобы миграция оставалась
     * честной на любой базе.
     */
    private function dropDeliveryColumn(): void
    {
        if (! Schema::hasColumn('sent_emails', 'notification_delivery_id')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sent_emails', function (Blueprint $table) {
                $table->dropForeign(['notification_delivery_id']);
                $table->dropIndex('sent_emails_delivery_idx');
                $table->dropColumn('notification_delivery_id');
            });

            return;
        }

        Schema::create('sent_emails_rebuilt', function (Blueprint $table) {
            $table->comment('Журнал исходящих писем сайта: кому, что и по какому клиенту ушло');

            $table->id()->comment('Первичный ключ');
            $table->string('recipient')->comment('Email получателя письма');
            $table->string('subject', 512)->nullable()->comment('Тема письма');
            $table->string('source')->nullable()
                ->comment('Класс уведомления или Mailable, породивший письмо');
            $table->foreignId('client_user_id')->nullable()
                ->comment('Клиент, к жизни которого относится письмо (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()
                ->comment('Получатель, если это известный пользователь сайта (users.id)')
                ->constrained('users')->nullOnDelete();
            $table->string('message_id', 512)->nullable()
                ->comment('Message-ID письма из SMTP-заголовка');
            $table->timestamp('sent_at')->comment('Момент фактической отправки');
            $table->timestamp('created_at')->nullable()->comment('Когда запись журнала создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись журнала изменена');
        });

        DB::statement('insert into sent_emails_rebuilt (id, recipient, subject, source, client_user_id, recipient_user_id, message_id, sent_at, created_at, updated_at)
            select id, recipient, subject, source, client_user_id, recipient_user_id, message_id, sent_at, created_at, updated_at from sent_emails');

        Schema::drop('sent_emails');
        Schema::rename('sent_emails_rebuilt', 'sent_emails');

        Schema::table('sent_emails', function (Blueprint $table) {
            $table->index(['client_user_id', 'sent_at'], 'sent_emails_client_sent_idx');
            $table->index(['recipient', 'sent_at'], 'sent_emails_recipient_sent_idx');
            $table->index('sent_at', 'sent_emails_sent_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            if (Schema::hasColumn('sent_emails', 'notification_delivery_id')) {
                return;
            }

            $table->unsignedBigInteger('notification_delivery_id')->nullable()->after('source')
                ->comment('Решение пульта уведомлений, породившее письмо (историческое поле)');
            $table->index('notification_delivery_id', 'sent_emails_delivery_idx');
        });
    }
};
