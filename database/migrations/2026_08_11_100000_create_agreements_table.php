<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Соглашения с клиентами (эпик fin-00, карточка fin-03, протокол v16.0.0).
 *
 * Заменяет запланированную сущность «договор». Замер 1С: договоров в базе 75 штук
 * и 97,8 % реализаций идут без договора, тогда как соглашений 5 102 при полном покрытии.
 * Договор остался полями `contract_uuid` / `contract_name` прямо в движении регистра —
 * заводить справочник ради 2,2 % документов незачем.
 *
 * ⚠️ Соглашение НЕ является измерением регистра взаиморасчётов: 1С берёт его из
 * документа-регистратора, и при изменении задним числом группировка исторических
 * движений разъедется. Поэтому соглашение служит фильтром и группировкой,
 * а осью акта сверки остаётся контрагент × организация × валюта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->comment('Соглашения с клиентами из 1С: условия продаж и группировка взаиморасчётов');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('UUID соглашения в 1С — ключ идемпотентности');

            // Связи nullable намеренно: соглашение может приехать раньше контрагента.
            // Терять его из-за порядка доставки нельзя — сырые UUID рядом дают доклейку.
            $table->foreignId('user_id')->nullable()->comment('Партнёр (users.id). NULL — ещё не сопоставлен')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент клиента (companies.id). NULL — ещё не сопоставлен')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->comment('Наше юрлицо (organizations.id). NULL — соглашение вне разреза организаций')
                ->constrained('organizations')->nullOnDelete();

            $table->uuid('partner_uuid')->nullable()->comment('UUID партнёра в 1С — для доклейки user_id');
            $table->uuid('contractor_uuid')->nullable()->comment('UUID контрагента в 1С — для доклейки company_id');
            $table->uuid('organization_uuid')->nullable()->comment('UUID нашей организации в 1С — для доклейки organization_id');
            $table->string('tax_id', 20)->nullable()->comment('ИНН контрагента — резервный способ сопоставления');

            $table->string('number')->nullable()->comment('Номер соглашения («СГ-0042»)');
            $table->date('date')->nullable()->comment('Дата соглашения');
            $table->string('name')->nullable()->comment('Наименование соглашения как в 1С');
            $table->string('currency_code', 3)->nullable()->comment('Валюта расчётов по соглашению (ISO-4217)');
            $table->string('settlement_procedure', 40)->nullable()
                ->comment("Порядок расчётов: 'orders' — по заказам, 'advance_orders_debt_invoices' — по авансам-заказам и накладным, 'settlement_documents' — по расчётным документам, NULL — не заполнено в 1С");
            $table->decimal('credit_limit', 20, 2)->nullable()->comment('Кредитный лимит по соглашению. NULL — не ограничен');
            $table->unsignedSmallInteger('deferral_days')->nullable()->comment('Дней отсрочки платежа. NULL — не задана');
            $table->string('status', 20)->default('active')
                ->comment("Состояние: 'active' — действует, 'closed' — закрыто");

            $table->unsignedInteger('revision')->nullable()->comment('Ревизия соглашения в 1С из последнего применённого сообщения');
            $table->unsignedInteger('applied_revision')->nullable()->comment('Наибольшая применённая ревизия. Сообщение с меньшей или равной отбрасывается ErpRevisionGuard как обогнавшее свежее');
            $table->timestamp('erp_created_at')->nullable()->comment('Дата-время создания соглашения в 1С');
            $table->timestamp('erp_updated_at')->nullable()->comment('Дата-время последнего изменения соглашения в 1С');
            $table->softDeletes()->comment('Дата пометки удаления (agreement.deleted). Движения на неё не завязаны');

            // timestamps() комментарии не проставляет — колонки заводим явно
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            // Имена индексов заданы явно: автоимена MySQL длиннее 64 символов не примет
            $table->index(['company_id', 'organization_id'], 'agr_company_organization_index');
            $table->index(['user_id', 'status'], 'agr_user_status_index');
            $table->index('contractor_uuid', 'agr_contractor_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
