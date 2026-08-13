<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Печатные формы документов из 1С (эпик doc-00, протокол v16.1.0).
 *
 * Сайт печатные формы не рисует — он принимает готовый PDF, сформированный 1С,
 * и отдаёт его клиенту в разделе «Документы». Сам файл едет не по шине, а через
 * обменный бакет S3: тот же приём, что у индивидуальных цен.
 *
 * Названа `printed_documents`, а не `documents`: в проекте «документ» уже занят
 * заказами, реализациями и платежами (Crm\DocumentController, SettlementDocument,
 * трейт FiltersClientDocuments). Совпадение имён в домене денег — прямая дорога
 * к перепутанной сущности.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printed_documents', function (Blueprint $table) {
            $table->comment('Печатные формы документов (PDF) из 1С для личного кабинета клиента: счета, счета-фактуры, УПД, акты сверки, договоры');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()
                ->comment('UUID печатной формы в 1С — ключ идемпотентности и перезаписи версии. Это НЕ uuid документа-основания: у одной реализации бывают и УПД, и счёт-фактура, у каждой формы свой идентификатор');

            // --- Вид формы ---
            $table->string('type', 40)->default('other')
                ->comment("Код вида формы (App\\Enums\\PrintedDocumentType): 'contract' — договор, 'invoice' — счёт на оплату, 'tax_invoice' — счёт-фактура, 'upd' — УПД, 'reconciliation_act' — акт сверки и др.; 'other' — фолбэк для неизвестного сайту кода");
            $table->string('erp_type_code', 100)->nullable()
                ->comment('Исходный код вида как прислала 1С. Хранится всегда, даже когда type = other: по нему видно, какой вид формы пора завести в справочник сайта');
            $table->string('erp_type_name', 255)->nullable()
                ->comment('Название вида формы как в 1С («Универсальный передаточный документ»). Показывается клиенту, когда type = other');

            // --- Реквизиты документа-основания ---
            $table->string('number')->nullable()
                ->comment('Номер документа-основания в 1С («29УТ-002488»). Не уникален: номера повторяются между организациями и годами');
            $table->date('date')->nullable()
                ->comment('Дата документа-основания. Ось фильтра и сортировки в кабинете');
            $table->string('title')->nullable()
                ->comment('Готовый заголовок для показа клиенту. Пусто — собирается на сайте из вида, номера и даты');

            // --- Стороны и основание. Связи nullable намеренно: печатная форма может
            //     приехать раньше контрагента, заказа или реализации, и терять её
            //     из-за порядка доставки нельзя. Сырые UUID рядом дают доклейку. ---
            $table->foreignId('user_id')->nullable()
                ->comment('Партнёр-владелец (users.id). Денормализация для журналов CRM. NULL — партнёр ещё не сопоставлен')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->comment('Контрагент клиента (companies.id) — ось видимости в кабинете. NULL — контрагента ещё нет на сайте, связь доклеит documents:relink')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()
                ->comment('Наше юрлицо (organizations.id). Заполняется OrganizationResolver: при незнакомом UUID создаётся заглушка')
                ->constrained('organizations')->nullOnDelete();
            $table->foreignId('order_id')->nullable()
                ->comment('Заказ-основание (orders.id). NULL — форма не привязана к заказу либо заказ ещё не приехал')
                ->constrained('orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()
                ->comment('Реализация-основание (shipments.id). NULL — форма не привязана к реализации либо реализация ещё не приехала')
                ->constrained('shipments')->nullOnDelete();

            $table->uuid('partner_uuid')->nullable()->comment('UUID партнёра в 1С — для доклейки user_id');
            $table->uuid('contractor_uuid')->nullable()->comment('UUID контрагента в 1С — для доклейки company_id. Хранится всегда, даже когда company_id не резолвится');
            $table->uuid('organization_uuid')->nullable()->comment('UUID нашей организации в 1С — для доклейки organization_id');
            $table->uuid('order_uuid')->nullable()->comment('UUID заказа-основания в 1С — для доклейки order_id');
            $table->uuid('shipment_uuid')->nullable()->comment('UUID реализации-основания в 1С — для доклейки shipment_id');
            $table->string('tax_id', 20)->nullable()->comment('ИНН контрагента на момент формирования — резервный способ сопоставления в паре с partner_uuid');
            $table->string('base_document_kind', 20)->nullable()
                ->comment("Вид документа-основания как его назвала 1С: 'order' — заказ, 'shipment' — реализация, NULL — форма без основания (договор, акт сверки)");

            // --- Файл ---
            $table->string('disk', 40)->nullable()
                ->comment('Имя диска Laravel, на котором лежит файл. Колонка, а не константа: переезд бакета не должен требовать миграции данных');
            $table->string('path', 512)->nullable()
                ->comment('Ключ объекта на диске («2026/08/3f2504e0-….pdf»). Детерминирован по uuid: перевыставление перезаписывает тот же ключ, мусор не копится');
            $table->string('source_url', 512)->nullable()
                ->comment('Исходный s3://-URL из сообщения 1С. Только для аудита: бакет для чтения берётся из конфигурации диска, а не отсюда');
            $table->string('original_filename', 255)->nullable()
                ->comment('Имя файла, как его назвала 1С. Основа человекочитаемого имени при скачивании');
            $table->string('mime_type', 100)->nullable()->comment('MIME-тип файла. Всё, кроме application/pdf, отклоняется на приёме');
            $table->unsignedBigInteger('size_bytes')->nullable()->comment('Размер файла в байтах');
            $table->string('checksum', 64)->nullable()
                ->comment('SHA-256 содержимого. Дедупликация при перевыставлении: хеш совпал — копирование пропускается, обновляются только реквизиты');

            $table->string('file_status', 20)->default('pending')
                ->comment("Состояние файла: 'pending' — запись создана, файл ещё не перенесён; 'stored' — лежит в хранилище сайта; 'missing' — 1С не выложила файл в обменный бакет; 'rejected' — не PDF или превышен лимит размера. Клиенту показываются только 'stored'");
            $table->timestamp('stored_at')->nullable()->comment('Когда файл перенесён в хранилище сайта');
            $table->unsignedInteger('version')->default(0)
                ->comment('Сколько раз файл печатной формы перезаписывался (перевыставления по тому же uuid). Только для диагностики');

            // --- Свежесть ---
            $table->unsignedInteger('revision')->nullable()->comment('Ревизия печатной формы в 1С из последнего применённого сообщения');
            $table->unsignedInteger('applied_revision')->nullable()
                ->comment('Наибольшая применённая ревизия. Сообщение с меньшей или равной отбрасывается ErpRevisionGuard как обогнавшее свежее');
            $table->timestamp('erp_created_at')->nullable()->comment('Дата-время формирования печатной формы в 1С');
            $table->timestamp('erp_updated_at')->nullable()->comment('Дата-время последнего переформирования печатной формы в 1С');

            // Явно, а не через timestamps(): тот не проставляет комментарии,
            // и колонки выпадают из покрытия db:comments:audit.
            $table->timestamp('created_at')->nullable()->comment('Когда запись создана на сайте — момент приёма сообщения из 1С');
            $table->timestamp('updated_at')->nullable()->comment('Когда запись последний раз изменена на сайте');
            $table->softDeletes()
                ->comment('Дата отзыва формы (printed_document.deleted). Файл при этом остаётся: снятие пометки удаления в 1С — обычная операция, а перезалить PDF заново неоткуда. Физически файл сносит documents:prune');

            // Имена индексов заданы явно: автоимена MySQL длиннее 64 символов не примет
            $table->index(['company_id', 'date'], 'pdoc_company_date_index');
            $table->index(['company_id', 'type', 'date'], 'pdoc_company_type_date_index');
            $table->index(['user_id', 'date'], 'pdoc_user_date_index');
            $table->index(['organization_id', 'date'], 'pdoc_organization_date_index');
            $table->index('order_id', 'pdoc_order_index');
            $table->index('shipment_id', 'pdoc_shipment_index');
            $table->index('contractor_uuid', 'pdoc_contractor_uuid_index');
            $table->index('partner_uuid', 'pdoc_partner_uuid_index');
            $table->index('order_uuid', 'pdoc_order_uuid_index');
            $table->index('shipment_uuid', 'pdoc_shipment_uuid_index');
            $table->index('organization_uuid', 'pdoc_organization_uuid_index');
            $table->index('number', 'pdoc_number_index');
            $table->index('file_status', 'pdoc_file_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printed_documents');
    }
};
