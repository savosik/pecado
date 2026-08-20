<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоп-лист адресов: отписки, жалобы и жёсткие отказы почтового сервера.
 *
 * Проверяется перед каждой отправкой. Мотив прикладной: адрес уволившегося
 * бухгалтера будет отбиваться сервером на каждом письме, а репутация
 * отправителя падает для всех писем домена, включая заказы.
 *
 * scope разделяет транзакционное и рекламное: отписка от рассылок не должна
 * отключать уведомления о заказах.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_suppressions', function (Blueprint $table) {
            $table->comment('Стоп-лист адресов: отписки по ссылке, жалобы на спам и жёсткие отказы почтового сервера');

            $table->id()->comment('Первичный ключ');
            $table->string('email', 191)->comment('Адрес, на который не отправляем');
            $table->string('scope', 64)->default('all')->comment("Область запрета: 'all' — вообще ничего, 'marketing' — только рассылки и кампании, либо ключ события ('finance.overdue_grew')");
            $table->string('reason', 32)->comment("Причина: 'unsubscribed' — отписался по ссылке, 'bounce' — почтовый сервер отверг адрес, 'complaint' — жалоба на спам, 'manual' — внёс сотрудник");

            $table->foreignId('contact_id')->nullable()
                ->comment('Контакт адресной книги, если адрес узнан (client_contacts.id)')
                ->constrained('client_contacts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()
                ->comment('Пользователь сайта с этим адресом (users.id)')
                ->constrained('users')->nullOnDelete();

            $table->text('note')->nullable()->comment('Пояснение сотрудника или текст отказа почтового сервера');
            $table->timestamp('expires_at')->nullable()->comment('До какого момента действует запрет; NULL — бессрочно');

            $table->timestamp('created_at')->nullable()->comment('Когда адрес попал в стоп-лист');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');

            $table->unique(['email', 'scope'], 'notification_suppressions_email_scope_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_suppressions');
    }
};
