<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Жёсткое удаление всех Company с указанным ИНН (включая soft-deleted)
 * и всех связанных Order. Используется для cleanup демо-данных или
 * подготовки таблицы перед накатыванием UNIQUE(tax_id, tax_code, deleted_at).
 *
 * По умолчанию dry-run. Реальное удаление требует флага --force.
 */
class CleanupContractorByTaxId extends Command
{
    protected $signature = 'erp:cleanup-contractor-by-tax-id
        {tax_id : ИНН контрагента}
        {--dry-run : Только показать что будет удалено}
        {--force : Подтверждение реального удаления}';

    protected $description = 'Удалить все Company с указанным ИНН и связанные Order (для cleanup демо-данных)';

    public function handle(): int
    {
        $taxId = (string) $this->argument('tax_id');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Нужен флаг --dry-run или --force.');

            return self::FAILURE;
        }

        $companies = Company::withoutGlobalScopes()
            ->withTrashed()
            ->where('tax_id', $taxId)
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->info("Нет Company с ИНН {$taxId}.");

            return self::SUCCESS;
        }

        $companyIds = $companies->pluck('id')->all();

        $orders = Order::withoutGlobalScopes()
            ->withTrashed()
            ->whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get();

        $this->info("Найдено Company: {$companies->count()} (ИНН {$taxId}). Связанных Order: {$orders->count()}.");

        $this->table(
            ['id', 'name', 'tax_id', 'tax_code', 'erp_id', 'user_id', 'created_at', 'deleted_at'],
            $companies->map(fn ($c) => [
                $c->id,
                $c->name,
                $c->tax_id,
                $c->tax_code,
                $c->erp_id,
                $c->user_id,
                (string) $c->created_at,
                (string) $c->deleted_at,
            ])
        );

        if ($dryRun) {
            $this->warn('--dry-run: ничего не удалено.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($orders, $companies) {
            foreach ($orders as $order) {
                $order->forceDelete();
            }

            foreach ($companies as $company) {
                $company->forceDelete();
            }
        });

        $this->info("Удалено: {$companies->count()} Company и {$orders->count()} Order.");

        return self::SUCCESS;
    }
}
