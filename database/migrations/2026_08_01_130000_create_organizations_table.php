<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник наших организаций (юрлиц компании), от имени которых 1С проводит документы.
 *
 * 1С справочник организаций по шине не присылает и присылать не будет — в своих
 * сообщениях она указывает только UUID организации. Организации заводятся в админке
 * вручную с проставлением `external_id`, ровно как склады (см. миграции
 * `set_defect_warehouse_external_id`, `set_promo_sample_warehouse_external_id`).
 *
 * `external_id` — string, а не uuid: так хранят UUID из 1С все остальные таблицы
 * (`warehouses.external_id`, `companies.erp_id`, `users.erp_id`). char(36) отбраковал бы
 * неканоническое значение, а 1С — источник истины, её значение надо сохранить как есть.
 *
 * Эпик: канбан-карточки `2026-08-01_org-00-epic.md` и `2026-08-01_org-01-directory.md`
 * в `docs/tasks/`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->comment('Наши организации (юрлица компании), от имени которых 1С проводит документы');

            $table->id()->comment('Первичный ключ');
            $table->string('external_id')->nullable()->unique()
                ->comment('UUID организации в 1С. Заводится вручную в админке, как у складов');
            $table->string('name')->comment('Краткое название для UI: «ООО Пекадо»');
            $table->string('legal_name')->nullable()->comment('Полное юридическое наименование');
            $table->string('tax_id')->nullable()->comment('ИНН организации (не уникален: при реорганизации бывает совпадение)');
            $table->string('tax_code')->nullable()->comment('КПП организации');
            $table->string('bank_name')->nullable()->comment('Банк для оплаты — клиент видит его в реквизитах долга');
            $table->string('bank_bik')->nullable()->comment('БИК банка');
            $table->string('correspondent_account')->nullable()->comment('Корреспондентский счёт');
            $table->string('account_number')->nullable()->comment('Расчётный счёт');
            $table->boolean('is_active')->default(true)
                ->comment('Активна — используется в текущих документах. Неактивную не предлагаем в фильтрах по умолчанию');
            $table->boolean('is_stub')->default(false)
                ->comment('Заглушка: UUID пришёл из 1С, в админке организация ещё не заведена. Админ дозаполняет название — флаг снимается');
            $table->unsignedInteger('sort_order')->default(0)
                ->comment('Порядок отображения в списках и фильтрах (меньше — выше)');
            $table->timestamp('created_at')->nullable()->comment('Дата создания');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения');
            $table->softDeletes()->comment('Дата мягкого удаления: организацию с историей документов физически не удаляем');

            $table->index(['is_active', 'sort_order']);
            $table->index('is_stub');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
