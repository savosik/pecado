<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платёжные документы из 1С (US-17, протокол v15.11.0).
 *
 * Три отличия от `shipments`, которые легко принять за недосмотр:
 *
 * 1. `date` — dateTime, а не date. Порядок платежей внутри дня определяет,
 *    каким из них закрыта реализация, и 1С присылает документ со временем.
 * 2. Нет `erp_number`. У реализаций два номера, потому что сайт умеет заводить
 *    свои документы; платежи сайт не создаёт никогда — второго номера не будет.
 * 3. `contractor_uuid` хранится всегда, даже когда контрагент не резолвится.
 *    У реализаций его нет, и приехавшая раньше контрагента реализация навсегда
 *    остаётся с company_id = NULL. Для платежа это означало бы, что клиент
 *    не увидит свою оплату — поэтому uuid остаётся в строке и позволяет
 *    доклеить связь позже.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->comment('Платёжные документы из 1С (банк и касса): поступления оплаты от клиентов и возвраты оплаты клиентам. Онлайн-эквайринг сайта сюда не пишется');

            $table->id()->comment('Первичный ключ');
            $table->char('uuid', 36)->unique()->comment('UUID документа в 1С — источник правды и ключ идемпотентности');
            $table->string('number')->index()->comment('Номер документа в 1С (например «29УТ-002488»). Не уникален: номера повторяются между организациями и годами');
            $table->dateTime('date')->comment('Дата и время документа в 1С. Бизнес-дата платежа, не аудит-метка. Время значимо: определяет порядок платежей внутри дня');

            $table->string('direction', 3)->default('in')->comment("Направление движения денег: 'in' — поступление оплаты от клиента, 'out' — возврат оплаты клиенту");
            $table->string('operation_code')->nullable()->comment("Машинный код операции 1С: 'customer_payment' — поступление оплаты от клиента, 'customer_refund' — возврат оплаты клиенту");
            $table->string('operation_name')->nullable()->comment('Наименование операции как в 1С («Поступление оплаты от клиента»). Только для показа, в логике не участвует');
            $table->string('document_type')->nullable()->comment('Тип документа 1С («Платежное поручение», «Приходный кассовый ордер»). Только для показа');

            $table->foreignId('user_id')->nullable()->comment('Партнёр-владелец платежа (users.id). NULL — 1С не прислала partner_uuid или партнёра ещё нет на сайте')
                ->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент-плательщик (companies.id). NULL — контрагент ещё не приехал из 1С, связь доклеится по contractor_uuid')
                ->constrained()->nullOnDelete();
            $table->char('contractor_uuid', 36)->nullable()->index()->comment('UUID контрагента в 1С. Хранится всегда, даже когда company_id не резолвится — по нему связь доклеивается позже');
            $table->string('tax_id')->nullable()->index()->comment('ИНН/УНП/БИН плательщика на момент проведения документа');

            $table->foreignId('organization_id')->nullable()->comment('Наша организация — получатель платежа (organizations.id). NULL — не указана')
                ->constrained('organizations')->nullOnDelete();
            $table->string('organization_account')->nullable()->comment('Номер расчётного счёта нашей организации («2693»). Справка, связей по нему нет');
            $table->string('organization_bank_name')->nullable()->comment('Банк нашей организации («ПАО СБЕРБАНК»). Справка');
            $table->string('payer_account')->nullable()->comment('Номер счёта плательщика. Справка');
            $table->string('payer_bank_name')->nullable()->comment('Банк плательщика («ООО Банк Точка»). Справка');

            $table->string('bank_number')->nullable()->index()->comment('Номер документа по банку («9202»). Отличается от номера в 1С — по нему клиент ищет платёж в своей выписке');
            $table->date('bank_date')->nullable()->comment('Дата документа по банку');
            $table->boolean('bank_confirmed')->default(false)->comment('Флаг «Проведено банком» из 1С');
            $table->dateTime('bank_confirmed_at')->nullable()->comment('Дата проведения банком');
            $table->string('uip', 40)->nullable()->comment('УИП — уникальный идентификатор платежа. Значение «0» означает, что УИП не присвоен');
            $table->text('purpose')->nullable()->comment('Назначение платежа из платёжного поручения');

            $table->decimal('amount', 14, 2)->default(0)->comment('Сумма документа в валюте документа. Всегда положительная — знак задаёт direction');
            $table->char('currency_code', 3)->nullable()->comment('Валюта платежа (ISO-4217): RUB, KZT, BYN. Оплата засчитывается реализации только при совпадении валют');
            $table->decimal('allocated_amount', 14, 2)->default(0)->comment('ДЕНОРМАЛИЗАЦИЯ: сумма строк payment_allocations. Пересчитывает только PaymentAllocationService');
            $table->decimal('unallocated_amount', 14, 2)->default(0)->comment('ДЕНОРМАЛИЗАЦИЯ: нераспределённый остаток (аванс) = amount - allocated_amount. Пересчитывает только PaymentAllocationService');

            $table->text('comment')->nullable()->comment('Комментарий сотрудника сайта. Локальное поле: в 1С не уходит и из 1С не перезаписывается');

            $table->dateTime('erp_created_at')->nullable()->comment('Аудит-метка 1С: когда документ создан в учётной системе');
            $table->dateTime('erp_updated_at')->nullable()->comment('Аудит-метка 1С: когда документ последний раз изменён в учётной системе');
            $table->timestamp('created_at')->nullable()->comment('Дата и время создания записи на сайте (момент приёма сообщения из 1С)');
            $table->timestamp('updated_at')->nullable()->comment('Дата и время последнего изменения записи на сайте');
            $table->softDeletes()->comment('Мягкое удаление: 1С отменила проведение документа. Строки расшифровки при этом сохраняются — повторное проведение вернёт разнесение');

            $table->index(['user_id', 'date'], 'payments_user_date_index');
            $table->index(['user_id', 'erp_created_at'], 'payments_user_erp_created_index');
            $table->index(['company_id', 'date'], 'payments_company_date_index');
            $table->index(['direction', 'date'], 'payments_direction_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
