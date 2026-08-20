<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Адресная книга партнёра: контактные лица его контрагентов.
 *
 * До неё машиночитаемых адресов контактных лиц в системе не было вовсе. Бухгалтер
 * и собственник живут в `crm_client_profiles` свободным текстом («Иванов Пётр,
 * +7 912 …, buh@romashka.ru»), откуда адрес без парсинга не достать, а единственный
 * реестр валидированных доп. адресов — `entity_subscriptions` — заполняет сам клиент
 * из кабинета, чего почти никто не сделал.
 *
 * Имя `client_contacts`, а не `crm_contacts`: контакт — данные партнёра, а не
 * CRM-артефакт. Их ведёт менеджер, но те же записи потом правит клиент в кабинете,
 * как это уже устроено с `companies`.
 *
 * Правило маршрутизации ссылается на карточку контакта, а не на строку адреса:
 * уволился бухгалтер — правится одна карточка, и все правила разом начинают писать
 * новому. Иначе пришлось бы обходить каждое правило руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->comment('Адресная книга партнёра: контактные лица контрагентов (ЛПР, бухгалтер, закупщик, логист) для адресной рассылки уведомлений');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')
                ->comment('Партнёр — владелец адресной книги (users.id)')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('company_id')->nullable()
                ->comment('Контрагент — юрлицо партнёра (companies.id); NULL — контакт партнёра в целом, годится для любого его юрлица')
                ->constrained('companies')
                ->nullOnDelete();

            $table->string('full_name', 191)->comment('ФИО контактного лица');
            $table->string('role', 30)->comment("Роль: 'director' — директор, 'accountant' — бухгалтер, 'buyer' — закупщик, 'logist' — логист, 'manager' — контактное лицо, 'owner' — собственник, 'other' — прочее");
            $table->string('position', 191)->nullable()->comment('Должность свободным текстом, как в подписи писем контрагента');

            $table->string('email', 191)->nullable()->comment('Email — основной адрес доставки уведомлений; NULL — контакт только для звонков');
            $table->string('phone', 50)->nullable()->comment('Телефон контактного лица');

            $table->boolean('is_primary')->default(false)->comment('Основной контакт своей роли у контрагента: подставляется первым в пресетах правил');
            $table->boolean('is_active')->default(true)->comment('Активен ли контакт: неактивный не получает писем и не подставляется в правила');

            $table->boolean('marketing_consent')->default(false)->comment('Согласие на рекламные рассылки и кампании; транзакционные уведомления его не требуют');
            $table->timestamp('marketing_consent_at')->nullable()->comment('Когда получено согласие на рассылки');
            $table->timestamp('unsubscribed_at')->nullable()->comment('Когда контакт отписался по ссылке из письма — глобальный отказ от всех уведомлений');
            $table->char('unsubscribe_token', 64)->unique()->comment('Токен для публичной ссылки отписки в письме');

            $table->string('source', 20)->default('manual')->comment("Откуда контакт: 'manual' — завёл менеджер, 'profile_import' — распознан из текстовых полей профиля CRM, 'self' — указал клиент в кабинете, 'erp' — приехал из 1С");
            $table->text('notes')->nullable()->comment('Заметка менеджера о контакте');
            $table->string('erp_uuid', 36)->nullable()->comment('UUID контактного лица в 1С — задел на случай выгрузки контактов по шине; сейчас всегда NULL');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Сотрудник, создавший контакт (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            // timestamps() и softDeletes() комментарии не принимают — колонки заводим явно
            $table->timestamp('created_at')->nullable()->comment('Когда контакт заведён');
            $table->timestamp('updated_at')->nullable()->comment('Когда контакт последний раз менялся');
            $table->softDeletes();

            $table->index(['user_id', 'is_active'], 'client_contacts_user_active_idx');
            $table->index(['company_id', 'role'], 'client_contacts_company_role_idx');
            $table->index('email', 'client_contacts_email_idx');
        });

        // Комментарий к deleted_at: softDeletes() его не принимает, а аудит
        // db:comments:audit --strict требует покрытия каждого столбца.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `client_contacts` MODIFY `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление: правила, ссылающиеся на контакт, не должны осиротеть молча'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
