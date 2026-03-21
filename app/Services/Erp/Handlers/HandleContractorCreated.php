<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * US-06 v4: Обработка события contractor.created из 1С.
 *
 * Создаёт или обновляет компанию (контрагента) с привязкой к партнёру.
 * Использует Company::withoutEvents() для предотвращения петель событий.
 * Использует withoutGlobalScopes() для обхода CompanyScope (фильтр по user_id).
 */
class HandleContractorCreated
{
    public function handle(array $payload): void
    {
        $uuid        = $payload['uuid']         ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $name        = $payload['name']         ?? null;
        $legalName   = $payload['legal_name']   ?? null;
        $taxId       = $payload['tax_id']       ?? null; // ИНН
        $regNumber   = $payload['registration_number'] ?? null; // КПП / ОГРН
        $legalAddr   = $payload['legal_address']   ?? null;
        $actualAddr  = $payload['actual_address']  ?? null;
        $phone       = $payload['phone']           ?? null;
        $email       = $payload['email']           ?? null;
        $country     = $payload['country']         ?? 'BY'; // default: Беларусь

        if (!$uuid) {
            Log::warning('contractor.created: отсутствует uuid', ['payload' => $payload]);
            return;
        }

        if (!$partnerUuid) {
            Log::warning('contractor.created: отсутствует partner_uuid', [
                'uuid' => $uuid,
                'payload' => $payload,
            ]);
            return;
        }

        // Найти пользователя по erp_id партнёра
        $user = User::where('erp_id', $partnerUuid)->first();

        if (!$user) {
            Log::warning('contractor.created: партнёр не найден', [
                'uuid'         => $uuid,
                'partner_uuid' => $partnerUuid,
            ]);
            return;
        }

        // Создать или обновить контрагента (без глобальных скоупов и без событий)
        $company = Company::withoutEvents(function () use ($uuid, $user, $name, $legalName, $taxId, $regNumber, $legalAddr, $actualAddr, $phone, $email, $country) {
            return Company::withoutGlobalScopes()->updateOrCreate(
                ['erp_id' => $uuid],
                array_merge(
                    ['user_id' => $user->id, 'country' => $country],
                    array_filter([
                        'name'                => $name,
                        'legal_name'          => $legalName,
                        'tax_id'              => $taxId,
                        'registration_number' => $regNumber,
                        'legal_address'       => $legalAddr,
                        'actual_address'      => $actualAddr,
                        'phone'               => $phone,
                        'email'               => $email,
                    ], fn ($value) => $value !== null)
                )
            );
        });

        $action = $company->wasRecentlyCreated ? 'создан' : 'обновлён';

        Log::info("contractor.created: контрагент {$action}", [
            'company_id'   => $company->id,
            'erp_id'       => $uuid,
            'user_id'      => $user->id,
            'partner_uuid' => $partnerUuid,
        ]);
    }
}
