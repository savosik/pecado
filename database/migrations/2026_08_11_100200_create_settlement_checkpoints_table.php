<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сверенные контрольные точки сальдо (эпик fin-00, карточка fin-03, протокол v16.0.0).
 *
 * Заменяет запланированную таблицу `contract_balances`. Четвёртый уровень балансов
 * оказался не нужен: ось сверки — контрагент × организация × валюта, а этот разрез
 * существует с v15.8.0 в `contractor_organization_balances`.
 *
 * Назначение другое. Ленту движений 1С отдаёт с 01.01.2026, но сверенной считает
 * только дату закрытия периода — 01.07.2026 (дата запрета изменения 30.06.2026).
 * Контрольная точка делает расхождение измеримым: «сальдо на 01.01 + движения
 * первого полугодия» обязано сойтись с checkpoint на 01.07. Сойдётся — история
 * достоверна; нет — увидим точную величину, а не будем гадать.
 *
 * ⚠️ Это контрольная сумма, а НЕ источник для расчётов. Читает её только
 * команда сверки; бизнес-логику на ней строить нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_checkpoints', function (Blueprint $table) {
            $table->comment('Контрольные точки сальдо взаиморасчётов из 1С — только для сверки, не источник расчётов');

            $table->id()->comment('Первичный ключ');

            $table->foreignId('user_id')->nullable()->comment('Партнёр (users.id). NULL — ещё не сопоставлен')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->comment('Контрагент клиента (companies.id). NULL — ещё не сопоставлен')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->comment('Наше юрлицо (organizations.id). NULL — ещё не сопоставлено')
                ->constrained('organizations')->nullOnDelete();

            $table->uuid('contractor_uuid')->comment('UUID контрагента в 1С — часть ключа уникальности');
            // Пустая строка вместо NULL намеренно: в MySQL уникальный индекс считает
            // NULL-ы различными, и точка без организации задвоилась бы при повторной
            // доставке — а задвоенная контрольная сумма хуже отсутствующей.
            $table->string('organization_uuid', 36)->default('')
                ->comment('UUID нашей организации в 1С. Пустая строка — точка на уровне контрагента, без разреза организаций');
            $table->string('tax_id', 20)->nullable()->comment('ИНН контрагента — резервный способ сопоставления');

            $table->date('as_of_date')->comment('Дата, на которую зафиксировано сальдо (дата закрытия периода в 1С)');
            $table->string('currency_code', 3)->default('RUB')->comment('Валюта взаиморасчётов (ISO-4217)');
            $table->decimal('amount', 20, 2)
                ->comment('Сальдо на дату со знаком: отрицательное — клиент должен нам. То же соглашение, что у settlement_entries.amount');
            $table->decimal('amount_rub', 20, 2)->nullable()->comment('Рублёвый эквивалент сальдо со знаком (СуммаРегл из 1С)');
            $table->boolean('is_verified')->default(false)
                ->comment('true — период закрыт и сальдо сверено бухгалтерией 1С; false — техническая точка (например, начало ленты 01.01.2026)');

            $table->timestamp('erp_updated_at')->nullable()->comment('Дата-время формирования точки в 1С');
            $table->timestamp('created_at')->nullable()->comment('Дата создания записи');
            $table->timestamp('updated_at')->nullable()->comment('Дата изменения записи');

            // Имена индексов заданы явно: автоимена MySQL длиннее 64 символов не примет
            $table->unique(
                ['contractor_uuid', 'organization_uuid', 'currency_code', 'as_of_date'],
                'sc_contractor_org_currency_date_unique',
            );
            $table->index(['company_id', 'as_of_date'], 'sc_company_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_checkpoints');
    }
};
