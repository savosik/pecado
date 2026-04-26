<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * US-07 v13.2: Обработка события contractor.deleted из 1С.
 *
 * Soft-delete Company. Для идентификации достаточно uuid или tax_id.
 * Если передан tax_id без uuid — поиск требует partner_uuid для резолва
 * user_id (иначе можно найти чужую Company).
 */
class HandleContractorDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $taxId = $payload['tax_id'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;

        if (! $uuid && ! $taxId) {
            Log::warning('contractor.deleted: отсутствуют uuid и tax_id', ['payload' => $payload]);

            return;
        }

        $company = null;

        if ($uuid) {
            $company = Company::withoutGlobalScopes()
                ->where('erp_id', $uuid)
                ->first();
        }

        if (! $company && $taxId) {
            $user = $partnerUuid ? User::where('erp_id', $partnerUuid)->first() : null;

            if ($user) {
                $company = Company::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->where('tax_id', $taxId)
                    ->first();
            }
        }

        if (! $company) {
            Log::warning('contractor.deleted: контрагент не найден', [
                'uuid' => $uuid,
                'tax_id' => $taxId,
                'partner_uuid' => $partnerUuid,
            ]);

            return;
        }

        // Маркер "пришло из 1С" — на случай, если какой-нибудь Observer всё же
        // среагирует на удаление, он увидит флаг и пропустит публикацию.
        $company->fromErp = true;

        Company::withoutEvents(function () use ($company) {
            $company->delete();
        });

        Log::info('contractor.deleted: контрагент удалён (soft-delete)', [
            'company_id' => $company->id,
            'erp_id' => $company->erp_id,
            'tax_id' => $company->tax_id,
        ]);
    }
}
