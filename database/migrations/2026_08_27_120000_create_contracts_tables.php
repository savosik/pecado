<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр договоров отдела продаж.
 *
 * До него договоры жили в Google-таблице с вкладками по нашим юрлицам
 * («ООО Пекадо», «ИП Елисеев П.А.», «ИП Кербер», «Пекадо Импорт»). Из таблицы
 * нельзя ни подшить скан, ни поставить задачу «дожать подпись», ни показать
 * партнёру в кабинете, ни увидеть, у кого была реализация без договора.
 *
 * Это сущность сайта, а не 1С. Договоры 1С в базе есть парой полей
 * `settlement_entries.contract_uuid/contract_name` — заполнены у 2 % документов,
 * и синхронизировать реестр с ними бессмысленно (см. Agreement::class).
 *
 * Категория — отдельная таблица, а не FK на `organizations`: вкладки таблицы
 * менеджеров не совпадают с нашими юрлицами из 1С («ИП Кербер (дистры)» —
 * это не организация, а вид договора), и РОП должен уметь завести новую
 * вкладку сам, не дожидаясь 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_categories', function (Blueprint $table) {
            $table->comment('Категории реестра договоров — аналог вкладок таблицы менеджеров: по нашему юрлицу и виду договора');

            $table->id()->comment('Первичный ключ');
            $table->string('name', 191)->comment('Название вкладки: «ООО Пекадо», «ИП Кербер (дистры)»');
            $table->string('description', 500)->nullable()->comment('Пояснение для менеджеров: какие договоры сюда заводить');
            $table->foreignId('organization_id')->nullable()
                ->comment('Наше юрлицо-сторона договора (organizations.id); NULL — юрлица нет в 1С или категория не по юрлицу')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Порядок вкладок');
            $table->boolean('is_active')->default(true)->comment('Показывать ли вкладку; неактивная скрывает старые договоры из основного списка, но не удаляет их');

            $table->timestamp('created_at')->nullable()->comment('Когда категория заведена');
            $table->timestamp('updated_at')->nullable()->comment('Когда категория изменена');

            $table->unique('name', 'contract_categories_name_unique');
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->comment('Реестр договоров с партнёрами: номер, стороны, подписание, срок, ответственный. Сканы — в media (crm-attachments), задачи и комментарии — полиморфно');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('category_id')
                ->comment('Категория-вкладка реестра (contract_categories.id)')
                ->constrained('contract_categories')
                ->restrictOnDelete();

            $table->foreignId('user_id')->nullable()
                ->comment('Партнёр (users.id) — чей это договор; NULL допустим для контрагента без партнёра')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->comment('Контрагент — юрлицо, с которым подписан договор (companies.id); NULL, если юрлица нет в базе')
                ->constrained('companies')
                ->nullOnDelete();
            $table->string('counterparty_name', 255)->nullable()
                ->comment('Название контрагента текстом — для сторон, которых нет в companies (иностранные поставщики), и как снимок на случай удаления юрлица');

            $table->string('number', 100)->comment('Номер договора как на бумаге: «№ 12-Т/2024»');
            $table->date('date')->nullable()->comment('Дата договора (с шапки документа)');
            $table->date('signed_at')->nullable()->comment('Дата подписания обеими сторонами; NULL — ещё не подписан');
            $table->date('valid_from')->nullable()->comment('Начало срока действия; NULL — с даты договора');
            $table->date('valid_until')->nullable()->comment('Окончание срока действия; NULL — бессрочный или с автопролонгацией');

            $table->string('status', 20)->default('draft')
                ->comment("Статус подписания: 'draft' — не отправлен, 'sent' — отправлен контрагенту, 'signed' — подписан, 'terminated' — расторгнут");
            $table->string('payment_terms', 20)->nullable()
                ->comment("Вариант оплаты: 'prepayment' — предоплата, 'deferral' — отсрочка, 'consignment' — реализация");
            $table->string('form', 20)->nullable()
                ->comment("Форма экземпляра: 'edo' — ЭДО, 'scan' — скан, 'original' — бумажный оригинал");

            $table->foreignId('responsible_manager_id')->nullable()
                ->comment('Ответственный менеджер (personal_managers.id)')
                ->constrained('personal_managers')
                ->nullOnDelete();

            $table->boolean('is_visible_in_cabinet')->default(true)
                ->comment('Показывать ли договор партнёру в личном кабинете');

            $table->text('comment')->nullable()->comment('Заметка менеджера: «не работает ЭДО», «организация закрыта»');

            $table->foreignId('created_by_user_id')->nullable()
                ->comment('Кто завёл запись (users.id)')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->comment('Кто правил запись последним (users.id)')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('Когда запись заведена');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись изменена');
            $table->softDeletes();

            $table->index(['category_id', 'status'], 'contracts_category_status_idx');
            $table->index('user_id', 'contracts_user_idx');
            $table->index('company_id', 'contracts_company_idx');
            $table->index('responsible_manager_id', 'contracts_manager_idx');
            $table->index('number', 'contracts_number_idx');
            $table->index('valid_until', 'contracts_valid_until_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `contracts` MODIFY `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление: сканы, задачи и комментарии договора не должны осиротеть молча'");
        }

        // Вкладки исходной таблицы. Организации подставляются по имени, если
        // 1С их уже прислала: на проде «ООО Пекадо» и «ИП Елисеев» есть,
        // «ИП Кербер» и «Пекадо Импорт» — нет, и это нормально.
        $now = now();
        $organizations = Schema::hasTable('organizations')
            ? DB::table('organizations')->whereNull('deleted_at')->pluck('id', 'name')
            : collect();

        $find = static function (string $needle) use ($organizations): ?int {
            foreach ($organizations as $name => $id) {
                if (mb_stripos((string) $name, $needle) !== false) {
                    return (int) $id;
                }
            }

            return null;
        };

        DB::table('contract_categories')->insert([
            ['name' => 'ООО Пекадо', 'description' => 'Договоры поставки с клиентами от ООО «Пекадо»', 'organization_id' => $find('Пекадо'), 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ИП Елисеев П.А. (клиенты)', 'description' => 'Договоры поставки с клиентами от ИП Елисеев П.А.', 'organization_id' => $find('Елисеев'), 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ИП Кербер (клиенты)', 'description' => 'Договоры поставки с клиентами от ИП Кербер', 'organization_id' => $find('Кербер'), 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ИП Кербер (дистры)', 'description' => 'Договоры с региональными дистрибьюторами от ИП Кербер', 'organization_id' => $find('Кербер'), 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ООО Пекадо Импорт', 'description' => 'Договоры с поставщиками и импортные контракты', 'organization_id' => $find('Пекадо Импорт'), 'sort_order' => 50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_categories');
    }
};
