<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Период движения регистра взаиморасчётов (контракт 16.12.0, топик №10 Agent Hub).
 *
 * У движения две разные даты. `date` — дата хозяйственной операции, ею строка
 * подписана в акте сверки. `entry_date` — период движения регистра
 * (`РегистрНакопления.РасчетыСКлиентами.Период`), и именно им 1С отбирает движения
 * в контрольную точку. За июль–август 2026 оси разошлись у 9,4 % движений
 * (6 499 из 69 271), разрыв до 74 дней: у четырёх платежей дата документа 30.07,
 * а период движения 03.08.2026 23:59:59 — точка на 01.08 выглядела расхождением,
 * хотя по учёту была верной.
 *
 * Колонка nullable навсегда: исторические движения поля не получат, пока 1С не
 * сделает массовую досылку, и такие строки остаются на прежней оси.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_entries', function (Blueprint $table) {
            $table->timestamp('entry_date')->nullable()->after('date')
                ->comment('Период движения регистра взаиморасчётов из 1С (контракт 16.12.0), приведён к учётной зоне 1С. Ось сверки с контрольной точкой; NULL — движение приехало до включения поля, режется по date');

            // Инвариант точки — диапазонный отбор по паре и дате; company/organization
            // уже покрыты se_company_org_date_index, отдельный индекс нужен разбору
            // «сколько строк новой оси» и выборкам по периоду.
            $table->index('entry_date', 'se_entry_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_entries', function (Blueprint $table) {
            $table->dropIndex('se_entry_date_index');
            $table->dropColumn('entry_date');
        });
    }
};
