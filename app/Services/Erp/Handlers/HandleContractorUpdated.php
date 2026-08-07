<?php

namespace App\Services\Erp\Handlers;

use App\Jobs\NormalizeCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * US-07 v13.2: Обработка события contractor.updated из 1С.
 *
 * Используется для двух сценариев:
 *  1. Привязка UUID после первой публикации contractor.created сайтом — 1С
 *     генерирует свой UUID и возвращает его; сайт находит Company по
 *     tax_id + user_id и проставляет erp_id (ленивый backfill).
 *  2. Частичное обновление атрибутов существующего контрагента.
 *  3. v15.14.0: перепривязка контрагента к другому партнёру — если partner_uuid
 *     резолвится в другого пользователя, Company переезжает к нему. Иначе клиент
 *     не видит свою организацию в ЛК и не может оформить заказ.
 *
 * Матчинг Company:
 *  1. По erp_id = uuid (приоритет, идемпотентность).
 *  2. Fallback: tax_id + user_id (резолв через partner_uuid → User.erp_id).
 *  3. Если не найдена — warning, ничего не создаём (это задача contractor.created).
 *
 * Все операции через Company::withoutEvents() + withoutGlobalScopes()
 * (защита от петли + обход CompanyScope).
 */
class HandleContractorUpdated
{
    use NormalizesCountry;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;

        if (! $uuid) {
            Log::warning('contractor.updated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        // Резолв пользователя (нужен для fallback-поиска, backfill и перепривязки)
        $user = $partnerUuid ? User::where('erp_id', $partnerUuid)->first() : null;

        if ($partnerUuid && ! $user) {
            Log::warning('contractor.updated: партнёр не найден, владелец контрагента не меняется', [
                'uuid' => $uuid,
                'partner_uuid' => $partnerUuid,
            ]);
        }

        // Сценарий 1: ищем по erp_id (повторная доставка / существующий контрагент)
        $company = Company::withoutGlobalScopes()
            ->where('erp_id', $uuid)
            ->first();

        $bindErpId = false;

        // Сценарий 2: fallback по tax_id + user_id — привязка erp_id
        if (! $company) {
            $taxId = $payload['tax_id'] ?? null;

            if ($user && $taxId) {
                $company = Company::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->where('tax_id', $taxId)
                    ->first();

                if ($company) {
                    $bindErpId = true;
                }
            }
        }

        if (! $company) {
            Log::warning('contractor.updated: контрагент не найден', [
                'uuid' => $uuid,
                'partner_uuid' => $partnerUuid,
                'tax_id' => $payload['tax_id'] ?? null,
            ]);

            return;
        }

        // Маркер "пришло из 1С" — listener PublishContractorToErp и
        // CompanyBankAccountObserver проверяют его и пропускают публикацию.
        $company->fromErp = true;

        // Перепривязка владельца: в 1С контрагента могли перевести на другого
        // партнёра. Только при найденном партнёре — неизвестный partner_uuid
        // владельца не обнуляет, иначе опечатка в 1С осиротит контрагента.
        $previousUserId = $company->user_id;
        $rebindUserId = ($user && $company->user_id !== $user->id) ? $user->id : null;

        $this->updateCompany($company, $payload, $bindErpId, $rebindUserId);

        Log::info('contractor.updated: контрагент обновлён', [
            'company_id' => $company->id,
            'erp_id' => $uuid,
            'partner_uuid' => $partnerUuid,
            'backfill' => $bindErpId,
            'user_id_from' => $rebindUserId !== null ? $previousUserId : null,
            'user_id_to' => $rebindUserId,
        ]);

        NormalizeCompanyDataJob::dispatch($company->id);
    }

    /**
     * @param  int|null  $rebindUserId  Новый владелец контрагента; null — владельца не меняем.
     */
    private function updateCompany(Company $company, array $payload, bool $bindErpId, ?int $rebindUserId = null): void
    {
        $updateData = [];

        if ($bindErpId && isset($payload['uuid'])) {
            $updateData['erp_id'] = $payload['uuid'];
        }

        if ($rebindUserId !== null) {
            $updateData['user_id'] = $rebindUserId;
        }

        $fields = [
            'name',
            'legal_name',
            'tax_id',
            'tax_code',
            'registration_number',
            'okpo_code',
            'legal_address',
            'actual_address',
            'phone',
            'email',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $updateData[$field] = $payload[$field];
            }
        }

        if (array_key_exists('country', $payload) && $payload['country'] !== null) {
            $updateData['country'] = $this->normalizeCountry($payload['country']);
        }

        if (! empty($updateData)) {
            Company::withoutEvents(function () use ($company, $updateData) {
                $company->update($updateData);
            });
        }

        // bank_accounts: только если явно передан (не null, не отсутствует).
        // CompanyBankAccount::withoutEvents — иначе на каждом счёте сработает
        // Observer и отправит contractor.updated обратно в 1С (петля).
        $bankAccounts = $payload['bank_accounts'] ?? null;

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
    }
}
