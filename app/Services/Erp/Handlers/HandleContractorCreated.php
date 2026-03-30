<?php

namespace App\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * US-06 v5: Обработка события contractor.created из 1С.
 *
 * Создаёт или обновляет компанию (контрагента) с привязкой к партнёру.
 * Синхронизирует банковские счета (bank_accounts).
 * Использует Company::withoutEvents() для предотвращения петель событий.
 * Использует withoutGlobalScopes() для обхода CompanyScope (фильтр по user_id).
 */
class HandleContractorCreated
{
    use NormalizesCountry;
    public function handle(array $payload): void
    {
        $uuid        = $payload['uuid']         ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $name        = $payload['name']         ?? null;
        $legalName   = $payload['legal_name']   ?? null;
        $taxId       = $payload['tax_id']       ?? null; // ИНН
        $regNumber   = $payload['registration_number'] ?? null;
        $taxCode     = $payload['tax_code']     ?? null; // КПП
        $okpoCode    = $payload['okpo_code']    ?? null; // ОКПО
        $legalAddr   = $payload['legal_address']   ?? null;
        $actualAddr  = $payload['actual_address']  ?? null;
        $phone       = $payload['phone']           ?? null;
        $email       = $payload['email']           ?? null;
        $country     = $this->normalizeCountry($payload['country'] ?? null, 'RU'); // default: Россия
        $bankAccounts = $payload['bank_accounts']  ?? null;

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
        $company = Company::withoutEvents(function () use ($uuid, $user, $name, $legalName, $taxId, $regNumber, $taxCode, $okpoCode, $legalAddr, $actualAddr, $phone, $email, $country) {
            return Company::withoutGlobalScopes()->updateOrCreate(
                ['erp_id' => $uuid],
                array_merge(
                    ['user_id' => $user->id, 'country' => $country],
                    array_filter([
                        'name'                => $name,
                        'legal_name'          => $legalName,
                        'tax_id'              => $taxId,
                        'registration_number' => $regNumber,
                        'tax_code'            => $taxCode,
                        'okpo_code'           => $okpoCode,
                        'legal_address'       => $legalAddr,
                        'actual_address'      => $actualAddr,
                        'phone'               => $phone,
                        'email'               => $email,
                    ], fn ($value) => $value !== null)
                )
            );
        });

        // Синхронизация банковских счетов (v5)
        if (is_array($bankAccounts)) {
            $company->bankAccounts()->delete();

            foreach ($bankAccounts as $account) {
                $company->bankAccounts()->create([
                    'bank_name'             => $account['bank_name'] ?? null,
                    'bank_bik'              => $account['bank_bik'] ?? null,
                    'correspondent_account' => $account['correspondent_account'] ?? null,
                    'account_number'        => $account['account_number'] ?? '',
                    'is_primary'            => $account['is_primary'] ?? false,
                ]);
            }
        }

        $action = $company->wasRecentlyCreated ? 'создан' : 'обновлён';

        Log::info("contractor.created: контрагент {$action}", [
            'company_id'     => $company->id,
            'erp_id'         => $uuid,
            'user_id'        => $user->id,
            'partner_uuid'   => $partnerUuid,
            'bank_accounts'  => is_array($bankAccounts) ? count($bankAccounts) : 0,
        ]);
    }
}
