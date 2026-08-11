<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Регистр взаиморасчётов (эпик fin-00, карточка fin-03, протокол v16.0.0).
 *
 * Плоская лента движений вместо графа связей «платёж → реализация → заказ → зачёт».
 * Любой денежный ответ получается суммированием одной колонки:
 *
 *   баланс          = SUM(amount) WHERE nature='fact' AND date <= :on_date
 *   долг сейчас     = SUM(amount - settled_amount) WHERE nature='plan' AND date <= today
 *   просрочка       = то же при date < today
 *   поток по дням   = SUM(amount - settled_amount) WHERE nature='plan' AND date > today GROUP BY date
 *
 * Прежняя модель этого не умела: из 156 754 061,98 ₽ разнесённых денег 111 872 793,21 ₽
 * (71,4 %) в неё не помещались, а у одного клиента отчёт показывал 5,53 млн ₽ просрочки
 * против 478 тыс ₽ по данным учёта.
 *
 * Таблица аддитивна: ни одно существующее чтение денег на неё пока не переключено.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GREATEST — функция MySQL; в SQLite ту же роль играет двухаргументный MAX.
        // Тесты идут на SQLite :memory:, поэтому выражение выбирается по драйверу,
        // иначе generated-колонки не создались бы вовсе.
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        Schema::create('settlement_entries', function (Blueprint $table) use ($greatest) {
            $table->comment('Регистр взаиморасчётов из 1С: движения (nature=fact) и плановые платежи (nature=plan)');

            $table->id()->comment('Первичный ключ');
            $table->uuid('uuid')->unique()->comment('UUID строки в 1С — ключ идемпотентности');
            $table->unsignedInteger('revision')->nullable()->comment('Ревизия документа-регистратора в 1С: сообщение с меньшей ревизией отбрасывается');
            $table->string('source', 10)->default('erp')
                ->comment("Источник строки: 'erp' — из 1С, 'crm' — заведена на сайте");

            $table->string('nature', 10)
                ->comment("Природа строки: 'fact' — свершившаяся операция, влияет на баланс; 'plan' — строка графика оплаты, даёт прогноз и просрочку");
            $table->string('type', 30)
                ->comment("Тип: 'opening_balance' — начальное сальдо, 'shipment' — реализация, 'payment_in' — поступление от клиента, 'payment_out' — возврат денег клиенту, 'goods_return' — возврат товара (зачёт), 'commission_sale' — продажа через комиссионера, 'adjustment' — взаимозачёт или корректировка, 'payment_due' — плановый платёж по графику");

            $table->date('date')->comment('Дата операции; для nature=plan — плановая дата платежа');
            $table->date('valid_until')->nullable()->comment('Действительно до этой даты. NULL — бессрочно');

            $table->foreignId('user_id')->nullable()->comment('Партнёр (users.id). NULL — ещё не сопоставлен')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент клиента (companies.id). NULL — ещё не сопоставлен')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->comment('Наше юрлицо (organizations.id). NULL — ещё не сопоставлено')
                ->constrained('organizations')->nullOnDelete();
            $table->foreignId('agreement_id')->nullable()->comment('Соглашение (agreements.id). NULL — движение без соглашения, это норма')
                ->constrained('agreements')->nullOnDelete();

            $table->uuid('partner_uuid')->nullable()->comment('UUID партнёра в 1С — для доклейки user_id');
            $table->uuid('contractor_uuid')->nullable()->comment('UUID контрагента в 1С — для доклейки company_id');
            $table->uuid('organization_uuid')->nullable()->comment('UUID нашей организации в 1С — для доклейки organization_id');
            $table->uuid('agreement_uuid')->nullable()->comment('UUID соглашения в 1С — для доклейки agreement_id');
            $table->string('agreement_name')->nullable()->comment('Наименование соглашения как в 1С — для показа без справочника');
            $table->uuid('contract_uuid')->nullable()->comment('UUID договора в 1С. Отдельной сущности нет: заполнен у 2,2 % документов');
            $table->string('contract_name')->nullable()->comment('Наименование договора как в 1С — для показа');

            $table->string('settlement_object_kind', 40)->nullable()
                ->comment("Вид объекта расчётов (измерение ОбъектРасчетов): 'order' — заказ клиента, 'shipment' — реализация, 'commission_report' — отчёт комиссионера, 'commission_contract' — договор комиссии, 'other' — прочее. Перечень открытый");
            $table->uuid('settlement_object_uuid')->nullable()
                ->comment('UUID объекта расчётов. Не путать с документом-регистратором: регистратор породил движение, объект расчётов — то, по чему ведётся расчёт');
            $table->string('settlement_object_name')->nullable()
                ->comment('Представление объекта расчётов строкой («Заказ клиента A2УТ-000417 от 01.07.2026»)');

            $table->decimal('amount', 20, 2)
                ->comment("Сумма движения со знаком: '+' — в пользу клиента (оплата, возврат товара), '−' — в нашу пользу (реализация, возврат денег). SUM(amount) равна балансу контрагента; отрицательный баланс означает долг клиента");
            $table->string('currency_code', 3)->default('RUB')->comment('Валюта взаиморасчётов (ISO-4217)');
            $table->decimal('amount_rub', 20, 2)->nullable()
                ->comment('Рублёвый эквивалент со знаком — ресурс СуммаРегл из 1С, зафиксированный учётом на дату операции. Курс не храним: два источника истины для одной величины разъезжаются');
            $table->decimal('settled_amount', 20, 2)->default(0)
                ->comment('Погашенная часть строки по данным 1С. Осмысленна только при nature=plan; сайт её НЕ вычисляет');
            $table->boolean('is_settled_derived')->default(false)
                ->comment('true — settled_amount получен разнесением document_settled_amount по этапам заказа. Величина производная: годится для календаря, но не для баланса и сверки');
            $table->decimal('document_settled_amount', 20, 2)->nullable()
                ->comment('Оплачено по документу целиком (ресурс Оплачивается). Авторитетная величина для заказов, где построчного остатка в 1С нет');

            // Дебет и кредит акта сверки — generated-колонки, а не хранимые: SUM(amount)
            // обязан оставаться единственным источником, иначе каждый SQL-запрос
            // ИИ-агента превращается в CASE. Комментарии им не задаются (правило проекта).
            $table->decimal('debit', 20, 2)->storedAs($greatest.'(-amount, 0)');
            $table->decimal('credit', 20, 2)->storedAs($greatest.'(amount, 0)');

            $table->string('movement_kind', 10)->nullable()
                ->comment("Вид движения в терминах 1С: 'income' — Приход, 'expense' — Расход. Диагностическое поле, в арифметике не участвует: знак уже применён к amount");

            $table->string('document_type')->nullable()->comment('Класс документа-регистратора на сайте (полиморфная связь). NULL — документа на сайте нет');
            $table->unsignedBigInteger('document_id')->nullable()->comment('ID документа-регистратора на сайте (полиморфная связь)');
            $table->uuid('document_uuid')->nullable()->comment('UUID документа-регистратора в 1С — ключ замены движений при перепроведении');
            $table->string('document_kind', 40)->nullable()
                ->comment("Вид документа-регистратора: 'shipment', 'order', 'payment', 'goods_return', 'netting', 'debt_adjustment', 'sale_adjustment', 'commission_report', 'other'. Перечень открытый");
            $table->string('document_number')->nullable()->comment('Номер документа-регистратора. Дублируется, чтобы строка читалась без самого документа');
            $table->date('document_date')->nullable()->comment('Дата документа-регистратора. Дублируется по той же причине');
            $table->unsignedInteger('line_number')->nullable()->comment('Номер строки в документе 1С — справочно, логика на нём не строится');

            $table->text('comment')->nullable()->comment('Комментарий к движению из 1С');
            $table->json('meta')->nullable()->comment('Прочие реквизиты строки графика (percent, term_days, basis, stage) — только для показа');

            $table->timestamp('erp_created_at')->nullable()->comment('Дата-время создания движения в 1С');
            $table->timestamp('erp_updated_at')->nullable()->comment('Дата-время последнего изменения движения в 1С');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            // Имена индексов заданы явно: автоимена MySQL длиннее 64 символов не примет
            $table->index(['company_id', 'organization_id', 'date'], 'se_company_org_date_index');
            $table->index(['agreement_id', 'date'], 'se_agreement_date_index');
            $table->index(['settlement_object_kind', 'settlement_object_uuid'], 'se_settlement_object_index');
            $table->index(['nature', 'date'], 'se_nature_date_index');
            $table->index(['document_type', 'document_id'], 'se_document_morph_index');
            $table->index('document_uuid', 'se_document_uuid_index');
            $table->index(['user_id', 'nature', 'date'], 'se_user_nature_date_index');
            $table->index(['organization_id', 'nature', 'date'], 'se_org_nature_date_index');
            $table->index('contractor_uuid', 'se_contractor_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_entries');
    }
};
