<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал доставок пульта: одна строка на адресата одного сигнала.
 *
 * Показывает решение движка целиком, включая отрицательные: письмо не ушло,
 * потому что адрес в стоп-листе, сработал троттлинг или не совпало ни одно
 * правило. Именно это отвечает менеджеру на «почему клиенту не пришло»
 * без обращения к разработчику.
 *
 * Уникальный индекс (signal_uuid, channel, recipient) — дедупликация на уровне
 * БД, а не только в памяти: повторный запуск job после сбоя очереди не даст
 * второе письмо тому же адресату.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->comment('Журнал доставок пульта: одна строка на адресата одного сигнала — кто, по какому правилу, отправлено или пропущено и почему');

            $table->id()->comment('Первичный ключ');
            $table->uuid('signal_uuid')->comment('Сигнал, породивший доставку (notification_signals.uuid)');
            $table->string('event_key', 64)->comment('Событие реестра — дублируется для выборок без join');

            $table->foreignId('notification_rule_id')->nullable()
                ->comment('Правило, добавившее адресата (notification_rules.id)')
                ->constrained('notification_rules')->nullOnDelete();
            $table->string('rule_name', 191)->nullable()->comment('Название правила на момент отправки — журнал читается и после удаления правила');

            $table->foreignId('client_user_id')->nullable()
                ->comment('Партнёр события (users.id)')->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->comment('Контрагент события (companies.id)')->constrained('companies')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()
                ->comment('Контакт адресной книги, если адрес взят оттуда (client_contacts.id)')
                ->constrained('client_contacts')->nullOnDelete();

            $table->string('channel', 20)->default('email')->comment("Канал доставки: 'email'; 'telegram', 'push' — задел");
            $table->string('recipient', 255)->comment('Адрес получателя');
            $table->string('recipient_kind', 24)->comment('Вид адресата на момент раскрытия (см. notification_rule_recipients.kind)');

            $table->string('status', 20)->comment("Статус: 'queued' — поставлено в очередь, 'sent' — письмо сдано транспорту, 'skipped' — не отправлено по решению движка, 'failed' — отправка упала");
            $table->string('skip_reason', 32)->nullable()->comment("Причина пропуска: 'duplicate' — адрес уже получил это событие, 'throttled' — сработал лимит частоты, 'unsubscribed' — отписан, 'no_consent' — нет согласия на рассылки, 'suppressed' — адрес в стоп-листе, 'invalid_email' — некорректный адрес, 'shadow' — теневой режим, 'dry_run' — предпросмотр, 'rate_limited' — глобальный лимит отправки, 'too_old' — событие старше допустимого возраста, 'feature_off' — домен выключен");

            $table->string('subject', 512)->nullable()->comment('Тема письма — чтобы журнал читался без обращения к почтовому логу');
            $table->string('message_id', 512)->nullable()->comment('Message-ID письма — копия из журнала писем, переживает его ретенцию');
            $table->text('error')->nullable()->comment('Текст ошибки отправки');

            $table->timestamp('queued_at')->nullable()->comment('Когда письмо поставлено в очередь');
            $table->timestamp('sent_at')->nullable()->comment('Когда письмо фактически ушло');

            $table->timestamp('created_at')->nullable()->comment('Когда запись создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');

            $table->unique(['signal_uuid', 'channel', 'recipient'], 'notification_deliveries_dedup_uniq');
            $table->index(['client_user_id', 'created_at'], 'notification_deliveries_client_idx');
            $table->index(['recipient', 'created_at'], 'notification_deliveries_recipient_idx');
            $table->index(['notification_rule_id', 'recipient', 'created_at'], 'notification_deliveries_throttle_idx');
            $table->index(['event_key', 'status', 'created_at'], 'notification_deliveries_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
