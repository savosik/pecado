<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник людей: карточка человека и его роли при сущностях.
 *
 * До него контактных лиц в системе не было как данных. Они лежали свободным
 * текстом в анкете партнёра («Иванов Пётр, +7 912 …, buh@romashka.ru»), почтой
 * юрлица в `companies` и строкой «с кем говорили» в звонках. Из такого нельзя
 * ни собрать .vcf, ни подшить письмо к карточке человека, ни вспомнить про
 * день рождения.
 *
 * Две таблицы, а не одна, — это главное решение. Карточка человека одна, а ролей
 * у него сколько угодно: Мария Афонина, бухгалтер двух юрлиц одного партнёра, —
 * одна запись в `contacts` и две в `contact_links`. Сложи их в одну таблицу,
 * и смена телефона превратится в две правки, одна из которых обязательно
 * забудется.
 *
 * Схема наследует продуманную `client_contacts` (миграция 2026_08_20_100000,
 * таблица снесена вместе с пультом уведомлений) и дополняет её тем, чего там
 * не было: «как обращаться», аватар, соцсети, день рождения, канал связи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->comment('Справочник людей: контактные лица партнёров и контрагентов — одна карточка человека на все его роли');

            $table->id()->comment('Первичный ключ');

            $table->string('full_name', 191)->comment('ФИО контактного лица — основное поле поиска');
            $table->string('greeting_name', 100)->nullable()
                ->comment('Как обращаться: «Мария Петровна», «Маша»; подставляется в приветствие письма');
            $table->string('position', 191)->nullable()
                ->comment('Должность свободным текстом, как в подписи писем контрагента');

            $table->string('email', 191)->nullable()
                ->comment('Основной адрес; NULL — контакт только для звонков');
            $table->string('phone', 50)->nullable()
                ->comment('Основной телефон в том виде, как его ввели');
            $table->string('phone_digits', 20)->nullable()
                ->comment('Тот же телефон только цифрами: поиск и сверка дублей идут по нему, потому что LIKE по отформатированной строке не берёт индекс');
            $table->string('phone_extra', 50)->nullable()
                ->comment('Дополнительный телефон: второй мобильный, городской, добавочный');

            $table->string('telegram', 100)->nullable()->comment('Telegram: @username или ссылка');
            $table->string('whatsapp', 50)->nullable()
                ->comment('WhatsApp, если номер отличается от основного телефона');
            $table->string('instagram', 100)->nullable()->comment('Instagram: @username или ссылка на профиль');
            $table->string('website', 191)->nullable()->comment('Личный сайт или страница');

            $table->string('preferred_channel', 20)->nullable()
                ->comment("Предпочитаемый способ связи: 'phone' — звонок, 'email' — почта, 'whatsapp', 'telegram', 'personal' — только лично");

            $table->date('birthday')->nullable()->comment('День рождения контакта');
            $table->boolean('birthday_has_year')->default(true)
                ->comment('Известен ли год рождения; false — значимы только день и месяц, возраст не считаем');

            $table->foreignId('client_user_id')->nullable()
                ->comment('Партнёр, в чью адресную книгу входит контакт (users.id); NULL — человек вне партнёра, например водитель перевозчика')
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true)
                ->comment('Активен ли контакт: неактивный не подставляется в выборы и не получает писем');

            $table->boolean('marketing_consent')->default(false)
                ->comment('Согласие на рекламные рассылки; транзакционные письма его не требуют');
            $table->timestamp('marketing_consent_at')->nullable()
                ->comment('Когда получено согласие на рассылки');
            $table->timestamp('unsubscribed_at')->nullable()
                ->comment('Когда человек отписался по ссылке из письма — глобальный отказ от всего');
            $table->char('unsubscribe_token', 64)->unique()
                ->comment('Токен публичной ссылки отписки в письме');

            $table->string('source', 20)->default('manual')
                ->comment("Откуда карточка: 'manual' — завёл менеджер, 'self' — завёл партнёр в кабинете, 'profile_import' — перенесено из анкеты CRM, 'directory_import' — собрано мастером из данных сайта, 'vcf' — импорт из телефона, 'erp' — из 1С");
            $table->timestamp('partner_touched_at')->nullable()
                ->comment('Когда партнёр последний раз правил карточку из кабинета; NULL — не трогал');

            $table->foreignId('merged_into_id')->nullable()
                ->comment('Карточка-победитель, если этот контакт признан дублем и слит в неё (contacts.id)')
                ->constrained('contacts')
                ->nullOnDelete();

            $table->text('notes')->nullable()
                ->comment('Заметка менеджера о контакте; в кабинет партнёру не отдаётся');
            $table->string('erp_uuid', 36)->nullable()
                ->comment('UUID контактного лица в 1С — задел под выгрузку по шине; сейчас всегда NULL');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Кто завёл карточку (users.id)')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->comment('Кто правил карточку последним (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            // timestamps() комментарии не проставляет — колонки заводим явно
            $table->timestamp('created_at')->nullable()->comment('Когда карточка заведена');
            $table->timestamp('updated_at')->nullable()->comment('Когда карточка последний раз изменена');
            $table->softDeletes();

            $table->index(['client_user_id', 'is_active'], 'contacts_client_active_idx');
            $table->index('email', 'contacts_email_idx');
            $table->index('phone_digits', 'contacts_phone_idx');
            $table->index('full_name', 'contacts_name_idx');
            $table->index('birthday', 'contacts_birthday_idx');
        });

        Schema::create('contact_links', function (Blueprint $table) {
            $table->comment('Привязка контакта к сущности с ролью: «бухгалтер контрагента Ромашка», «водитель по реализации №123»');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('contact_id')
                ->comment('Контакт (contacts.id)')
                ->constrained('contacts')
                ->cascadeOnDelete();

            $table->string('subject_type', 191)
                ->comment('Класс сущности, к которой привязан контакт (App\\Models\\User|Company|Order|Shipment|CrmLead)');
            $table->unsignedBigInteger('subject_id')->comment('ID сущности в её таблице');

            $table->string('role', 30)
                ->comment("Роль: 'director' — директор, 'accountant' — бухгалтер, 'buyer' — закупщик, 'logist' — логист, 'manager' — контактное лицо, 'owner' — собственник, 'driver' — водитель, 'courier' — курьер, 'other' — прочее");
            $table->string('role_note', 191)->nullable()
                ->comment('Уточнение роли свободным текстом, если списка не хватает');

            $table->boolean('is_primary')->default(false)
                ->comment('Основной контакт своей роли у этой сущности: подставляется первым');

            $table->foreignId('client_user_id')->nullable()
                ->comment('Партнёр, к чьей ленте сводится сущность привязки (users.id) — денормализация ради выборки одним запросом')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('source', 20)->default('manual')
                ->comment('Кто завёл привязку: те же значения, что у contacts.source');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Кто завёл привязку (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда привязка создана');
            $table->timestamp('updated_at')->nullable()->comment('Когда привязка изменена');

            // Один человек в одной роли у одной сущности заводится один раз.
            // Мягкого удаления у привязок намеренно нет: отвязать — значит удалить
            // строку, иначе удалённая привязка навсегда заблокировала бы повторную.
            $table->unique(['contact_id', 'subject_type', 'subject_id', 'role'], 'contact_links_unique');
            $table->index(['subject_type', 'subject_id'], 'contact_links_subject_idx');
            $table->index(['client_user_id', 'role'], 'contact_links_client_role_idx');
        });

        // softDeletes() комментарий не принимает так же, как timestamps().
        // Без этого краснеет db:comments:audit --strict.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `contacts` MODIFY `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление: письма и звонки, ссылающиеся на человека, не должны осиротеть молча'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_links');
        Schema::dropIfExists('contacts');
    }
};
