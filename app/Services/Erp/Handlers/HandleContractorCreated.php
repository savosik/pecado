<?php

namespace App\Services\Erp\Handlers;

use App\Jobs\NormalizeCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * US-06 v13.3: Обработка события contractor.created из 1С.
 *
 * Создаёт или обновляет компанию (контрагента). Привязка к партнёру опциональна:
 * если User с erp_id=partner_uuid не найден, Company создаётся с user_id=NULL
 * (1С могла прислать contractor.created раньше partner.created).
 * Матчинг: erp_id → tax_id + tax_code (без user_id, ИНН/КПП юридически уникальны).
 * lockForUpdate защищает от race в окне SELECT → INSERT.
 */
class HandleContractorCreated
{
    use NormalizesCountry;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $legalName = $payload['legal_name'] ?? null;
        $taxId = $payload['tax_id'] ?? null; // ИНН
        $regNumber = $payload['registration_number'] ?? null;
        $taxCode = $payload['tax_code'] ?? ''; // КПП — '' для ИП
        $okpoCode = $payload['okpo_code'] ?? null; // ОКПО
        $legalAddr = $payload['legal_address'] ?? null;
        $actualAddr = $payload['actual_address'] ?? null;
        $phone = $payload['phone'] ?? null;
        $email = $payload['email'] ?? null;
        $country = $this->normalizeCountry($payload['country'] ?? null, 'RU'); // default: Россия
        $bankAccounts = $payload['bank_accounts'] ?? null;

        if (! $uuid) {
            Log::warning('contractor.created: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        if (! $partnerUuid) {
            Log::warning('contractor.created: отсутствует partner_uuid', [
                'uuid' => $uuid,
                'payload' => $payload,
            ]);

            return;
        }

        // Найти пользователя по erp_id партнёра. NULL допустим: контрагент может прийти
        // раньше партнёра — создадим Company без user_id, привяжем при partner.created.
        $user = User::where('erp_id', $partnerUuid)->first();
        $userId = $user?->id;

        if (! $user) {
            Log::warning('contractor.created: партнёр не найден, создаём контрагента без user_id', [
                'uuid' => $uuid,
                'partner_uuid' => $partnerUuid,
            ]);
        }

        $company = DB::transaction(function () use ($uuid, $userId, $name, $legalName, $taxId, $regNumber, $taxCode, $okpoCode, $legalAddr, $actualAddr, $phone, $email, $country) {
            $company = Company::withoutGlobalScopes()
                ->withTrashed()
                ->where('erp_id', $uuid)
                ->lockForUpdate()
                ->first();

            if (! $company && $taxId) {
                // tax_code: до миграции v13.3 в БД могут лежать NULL — трактуем NULL и '' как один ключ.
                $company = Company::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('tax_id', $taxId)
                    ->where(function ($q) use ($taxCode) {
                        $q->where('tax_code', $taxCode);
                        if ($taxCode === '') {
                            $q->orWhereNull('tax_code');
                        }
                    })
                    ->lockForUpdate()
                    ->first();
            }

            $attributes = array_merge(
                ['erp_id' => $uuid, 'country' => $country, 'tax_code' => $taxCode],
                array_filter([
                    'user_id' => $userId,
                    'name' => $name,
                    'legal_name' => $legalName,
                    'tax_id' => $taxId,
                    'registration_number' => $regNumber,
                    'okpo_code' => $okpoCode,
                    'legal_address' => $legalAddr,
                    'actual_address' => $actualAddr,
                    'phone' => $phone,
                    'email' => $email,
                ], fn ($value) => $value !== null)
            );

            return Company::withoutEvents(function () use ($company, $attributes) {
                if ($company) {
                    if ($company->trashed()) {
                        $company->restoreQuietly();
                    }
                    $company->update($attributes);

                    return $company;
                }

                return Company::withoutGlobalScopes()->create($attributes);
            });
        });

        // Маркер "пришло из 1С" — listener PublishContractorToErp и
        // CompanyBankAccountObserver проверяют его и пропускают публикацию.
        $company->fromErp = true;

        // Синхронизация банковских счетов (v5).
        // CompanyBankAccount::withoutEvents — не триггерим Observer, иначе при
        // каждом счёте уйдёт contractor.updated обратно в 1С (петля).
        if (is_array($bankAccounts)) {
            CompanyBankAccount::withoutEvents(function () use ($company, $bankAccounts) {
                $company->bankAccounts()->delete();

                foreach ($bankAccounts as $account) {
                    $company->bankAccounts()->create([
                        'bank_name' => $account['bank_name'] ?? null,
                        'bank_bik' => $account['bank_bik'] ?? null,
                        'correspondent_account' => $account['correspondent_account'] ?? null,
                        'account_number' => $account['account_number'] ?? '',
                        'is_primary' => $account['is_primary'] ?? false,
                    ]);
                }
            });
        }

        $action = $company->wasRecentlyCreated ? 'создан' : 'обновлён';

        Log::info("contractor.created: контрагент {$action}", [
            'company_id' => $company->id,
            'erp_id' => $uuid,
            'user_id' => $userId,
            'partner_uuid' => $partnerUuid,
            'bank_accounts' => is_array($bankAccounts) ? count($bankAccounts) : 0,
        ]);

        NormalizeCompanyDataJob::dispatch($company->id);
    }
}
