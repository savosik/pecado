<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\SettlementEntry;
use Illuminate\Support\Facades\Log;

/**
 * Перепривязка осиротевших строк взаиморасчётов к карточке контрагента.
 *
 * Движения регистра (`settlement.posted`) могут приехать раньше карточки
 * (`contractor.created`): старые контрагенты 1С публиковала только по событию
 * создания, поэтому строки ложились с `company_id = NULL` и выпадали из сверки
 * пар «организация × контрагент» (круг 11: АВАНГАРД РУС, АТЛАС ТРЕЙД ПРО,
 * Потапенко). Резолв при записи строки чинит только будущее — этот трейт
 * доливает прошлое, как только карточка наконец приходит.
 */
trait RelinksOrphanSettlementEntries
{
    protected function relinkOrphanSettlementEntries(Company $company, string $event): void
    {
        if (! $company->erp_id) {
            return;
        }

        $linked = SettlementEntry::query()
            ->whereNull('company_id')
            ->where('contractor_uuid', $company->erp_id)
            ->update(['company_id' => $company->id]);

        if ($company->user_id !== null) {
            SettlementEntry::query()
                ->where('company_id', $company->id)
                ->whereNull('user_id')
                ->update(['user_id' => $company->user_id]);
        }

        if ($linked > 0) {
            Log::info("{$event}: осиротевшие строки взаиморасчётов привязаны к контрагенту", [
                'company_id' => $company->id,
                'erp_id' => $company->erp_id,
                'linked' => $linked,
            ]);
        }
    }
}
