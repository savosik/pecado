<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v13.3: уникальный индекс (tax_id, tax_code, deleted_at) на companies.
 *
 * Защита от дублей контрагентов на уровне БД (регрессия инцидента 2026-04-25).
 * Бизнес-правило: ИНН+КПП юридически уникальны, разные КПП = разные подразделения.
 *
 * MySQL особенность: NULL в составном UNIQUE рассматривается как distinct,
 * поэтому tax_code приводим к NOT NULL DEFAULT '' (пустая строка для ИП).
 * deleted_at в индексе оставляет soft-deleted записи нерестриктивными.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill: NULL → '' для tax_code (ИП юридически без КПП).
        DB::table('companies')->whereNull('tax_code')->update(['tax_code' => '']);

        // 2. Pre-flight check: уникальность (tax_id, tax_code) среди активных записей.
        // Если есть дубли — упасть с подсказкой запустить cleanup-команду.
        $duplicates = DB::table('companies')
            ->select('tax_id', 'tax_code', DB::raw('COUNT(*) as cnt'))
            ->whereNull('deleted_at')
            ->whereNotNull('tax_id')
            ->groupBy('tax_id', 'tax_code')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $samples = $duplicates->take(5)->map(fn ($d) => "ИНН={$d->tax_id} КПП={$d->tax_code} (×{$d->cnt})")->implode(', ');
            $total = $duplicates->count();

            throw new RuntimeException(
                "Миграция отклонена: найдено {$total} групп дублей контрагентов. ".
                "Примеры: {$samples}. ".
                'Запустите: php artisan erp:cleanup-contractor-by-tax-id <ИНН> --dry-run и --force.'
            );
        }

        // 3. Сделать tax_code NOT NULL DEFAULT ''.
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_code')->nullable(false)->default('')->change();
        });

        // 4. Уникальный индекс с deleted_at для совместимости с soft-delete.
        Schema::table('companies', function (Blueprint $table) {
            $table->unique(
                ['tax_id', 'tax_code', 'deleted_at'],
                'companies_tax_id_tax_code_deleted_at_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_tax_id_tax_code_deleted_at_unique');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_code')->nullable()->default(null)->change();
        });
    }
};
