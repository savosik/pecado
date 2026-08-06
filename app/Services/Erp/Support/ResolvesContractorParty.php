<?php

namespace App\Services\Erp\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Резолв контрагента и партнёра-владельца документа по данным из 1С.
 *
 * Протокол един для всех документов (реализации, платежи) и менять его в одном
 * обработчике нельзя — иначе один и тот же контрагент резолвится по-разному
 * в зависимости от того, каким документом он приехал:
 *
 *  1. Приоритет — `contractor_uuid` (Company.erp_id). Глобально уникален,
 *     не ломается при правке ИНН в 1С.
 *  2. Fallback — `tax_id` **в паре с user_id** (резолв через partner_uuid).
 *     Без user_id fallback не выполняется: иначе нашлась бы чужая Company
 *     с тем же ИНН (security-fix v13.2).
 *  3. Найденной по ИНН компании доставляем erp_id — на следующем документе
 *     сработает быстрый путь.
 */
trait ResolvesContractorParty
{
    /**
     * @return array{0: ?int, 1: ?int} [company_id, user_id]
     */
    protected function resolveCompanyAndUser(?string $contractorUuid, ?string $taxId, ?string $partnerUuid): array
    {
        $userId = null;
        if ($partnerUuid) {
            $userId = User::where('erp_id', $partnerUuid)->value('id');
        }

        $company = null;

        if ($contractorUuid) {
            $company = Company::withoutGlobalScopes()
                ->where('erp_id', $contractorUuid)
                ->first();
        }

        if (! $company && $taxId && $userId) {
            $company = Company::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('tax_id', $taxId)
                ->first();

            if ($company && $contractorUuid && ! $company->erp_id) {
                Company::withoutEvents(function () use ($company, $contractorUuid) {
                    $company->update(['erp_id' => $contractorUuid]);
                });
            }
        }

        if ($company) {
            return [$company->id, $company->user_id ?? $userId];
        }

        return [null, $userId];
    }
}
