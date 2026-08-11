<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал исходящих писем.
 *
 * До него ответ на вопрос «кто получил письмо о заказе клиента» можно было
 * получить только чтением кода маршрутизации — то есть предположением, а не
 * фактом. Журнал пишется на событие фактической отправки, поэтому показывает,
 * что действительно ушло, а не что должно было уйти по задумке.
 *
 * Тело письма не хранится: журнал должен отвечать на вопрос об адресации,
 * а не быть вторым почтовым ящиком. Хранение переписки с клиентом — задача
 * `crm_emails`, где письмо пишет менеджер и сам решает, что в нём.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->comment('Журнал исходящих писем сайта: кому, что и по какому клиенту ушло');

            $table->id()->comment('Первичный ключ');

            $table->string('recipient')->comment('Email получателя письма');
            $table->string('subject', 512)->nullable()->comment('Тема письма');

            $table->string('source')->nullable()->comment(
                'Класс уведомления или Mailable, породивший письмо (App\\Notifications\\...)'
            );

            $table->foreignId('client_user_id')->nullable()
                ->comment('Клиент, к жизни которого относится письмо (users.id); NULL — письмо не про клиента')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('recipient_user_id')->nullable()
                ->comment('Получатель, если это известный пользователь сайта (users.id)')
                ->constrained('users')->nullOnDelete();

            $table->string('message_id', 512)->nullable()->comment(
                'Message-ID письма из SMTP-заголовка — по нему письмо ищется в логах почтового сервера'
            );

            $table->timestamp('sent_at')->comment('Момент фактической отправки');

            // timestamps() комментарии не проставляет — колонки заводим явно
            $table->timestamp('created_at')->nullable()->comment('Когда запись журнала создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись журнала изменена');

            // Лента карточки клиента: выборка идёт по клиенту и сортируется по дате
            $table->index(['client_user_id', 'sent_at'], 'sent_emails_client_sent_idx');
            // Аудит «что уходило на этот адрес» — второй по частоте вопрос после ленты
            $table->index(['recipient', 'sent_at'], 'sent_emails_recipient_sent_idx');
            $table->index('sent_at', 'sent_emails_sent_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
