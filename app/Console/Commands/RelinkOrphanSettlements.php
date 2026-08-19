<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use App\Services\Erp\Handlers\RelinksOrphanSettlementEntries;
use Illuminate\Console\Command;

/**
 * Разовая доливка строк взаиморасчётов, оставшихся без карточки контрагента.
 *
 * Движения приезжали раньше карточек, а перепривязка появилась только в обработчиках
 * `contractor.created`/`updated` (круг 11) — то есть чинит будущее. Эта команда
 * закрывает накопленное прошлое, не требуя от 1С трогать карточки ради события.
 */
class RelinkOrphanSettlements extends Command
{
    use RelinksOrphanSettlementEntries;

    protected $signature = 'erp:relink-orphan-settlements {--dry-run : только показать, что будет привязано}';

    protected $description = 'Привязать движения и контрольные точки взаиморасчётов к контрагентам по contractor_uuid';

    public function handle(): int
    {
        $orphanUuids = SettlementEntry::query()
            ->whereNull('company_id')
            ->whereNotNull('contractor_uuid')
            ->distinct()
            ->pluck('contractor_uuid')
            ->merge(
                SettlementCheckpoint::query()
                    ->whereNull('company_id')
                    ->whereNotNull('contractor_uuid')
                    ->distinct()
                    ->pluck('contractor_uuid')
            )
            ->unique();

        if ($orphanUuids->isEmpty()) {
            $this->info('Осиротевших строк нет.');

            return self::SUCCESS;
        }

        $companies = Company::withoutGlobalScopes()
            ->whereIn('erp_id', $orphanUuids)
            ->get()
            ->keyBy('erp_id');

        $this->line(sprintf(
            'Осиротевших контрагентов: %d, из них карточка есть у %d.',
            $orphanUuids->count(),
            $companies->count(),
        ));

        $linked = 0;

        foreach ($companies as $company) {
            $entries = SettlementEntry::query()
                ->whereNull('company_id')
                ->where('contractor_uuid', $company->erp_id)
                ->count();

            $checkpoints = SettlementCheckpoint::query()
                ->whereNull('company_id')
                ->where('contractor_uuid', $company->erp_id)
                ->count();

            if ($entries === 0 && $checkpoints === 0) {
                continue;
            }

            $this->line(sprintf(
                '  %s (%s): движений %d, точек %d',
                $company->name,
                $company->tax_id ?: 'без ИНН',
                $entries,
                $checkpoints,
            ));

            if (! $this->option('dry-run')) {
                $this->relinkOrphanSettlementEntries($company, 'erp:relink-orphan-settlements');
            }

            $linked += $entries + $checkpoints;
        }

        $this->info($this->option('dry-run')
            ? "Пробный прогон: будет привязано строк — {$linked}."
            : "Привязано строк: {$linked}.");

        return self::SUCCESS;
    }
}
