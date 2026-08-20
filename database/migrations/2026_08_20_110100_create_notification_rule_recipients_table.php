<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Получатели правила маршрутизации.
 *
 * Разные виды адресатов покрыты одной таблицей и одним резолвером. Ключевые
 * два: 'contact' — ссылка на карточку адресной книги (сменился бухгалтер —
 * правится одна запись, а не десять правил) и 'contact_role' — «все бухгалтеры
 * этого контрагента», где нового сотрудника правило подхватывает само.
 *
 * 'config_list' читает адреса из настроек по ключу из белого списка: аварийные
 * резервные адреса остаются в ENV и не размазываются по базе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rule_recipients', function (Blueprint $table) {
            $table->comment('Получатели правила маршрутизации: ссылка на контакт адресной книги, роль, произвольный адрес или вычисляемый адресат (клиент, персональный менеджер, резервный список)');

            $table->id()->comment('Первичный ключ');
            $table->foreignId('notification_rule_id')
                ->comment('Правило (notification_rules.id)')
                ->constrained('notification_rules')->cascadeOnDelete();

            $table->string('kind', 24)->comment("Вид адресата: 'contact' — конкретный контакт адресной книги (contact_id); 'contact_role' — все активные контакты роли у контрагента события (value = роль); 'email' — произвольный адрес (value); 'client_user' — email аккаунта партнёра, которому принадлежит событие; 'company_email' — email контрагента из карточки (companies.email); 'personal_manager' — персональный менеджер партнёра с учётом замещения на время отсутствия; 'config_list' — список адресов из настроек (value = ключ конфига из белого списка); 'suppress' — исключить адресата, найденного правилами с большим приоритетом (value = email или роль)");

            $table->foreignId('contact_id')->nullable()
                ->comment('Контакт адресной книги (client_contacts.id), если kind = contact')
                ->constrained('client_contacts')->cascadeOnDelete();
            $table->string('value', 255)->nullable()->comment('Значение адресата: email, ключ роли или ключ конфига — смысл зависит от kind');

            $table->string('copy_type', 10)->default('to')->comment("Тип копии: 'to' — основной получатель (одно письмо на адрес), 'cc' — копия, 'bcc' — скрытая копия");
            $table->boolean('is_fallback')->default(false)->comment('Резервный адресат: подставляется, только если основные получатели правила не найдены');

            $table->char('unsubscribe_token', 64)->nullable()->unique()->comment('Токен персональной отписки этого адресата от этого правила; создаётся при первой отправке');

            $table->timestamp('created_at')->nullable()->comment('Когда получатель добавлен в правило');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');

            $table->index('notification_rule_id', 'notification_rule_recipients_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rule_recipients');
    }
};
