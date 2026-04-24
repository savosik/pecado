<?php

namespace App\Listeners;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Jobs\PublishContractorToErpJob;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-07 v13.2: Слушает CompanyCreated / CompanyUpdated и публикует
 * contractor.created в erp_out.contractors.
 *
 * Триггер — первое заполнение tax_id у Company:
 *  - CompanyCreated с непустым tax_id → публикуем.
 *  - CompanyUpdated, где tax_id стал непустым (ранее был пуст) → публикуем.
 *  - Иначе — не публикуем. После первой публикации 1С становится
 *    авторитетом; обновления контрагента приходят через contractor.updated.
 *
 * Защита от петли contractor.updated → CompanyUpdated → contractor.created → ... —
 * три уровня:
 *  1. Входящие handlers (HandleContractorCreated / HandleContractorUpdated /
 *     HandleContractorDeleted / HandleOrderCreated / HandleShipment*) обновляют
 *     Company через Company::withoutEvents() — CompanyUpdated не диспатчится.
 *  2. resolveFromUpdated публикует ТОЛЬКО при первом заполнении tax_id
 *     (wasChanged + getOriginal === пусто). Любой другой апдейт (в т.ч.
 *     если withoutEvents окажется забыт) не публикуется.
 *  3. Явная проверка erp_id: если Company уже имеет UUID из 1С, публикация
 *     не выполняется (1С уже авторитет, повторное сообщение не нужно).
 *
 * Откат — env-флаг PUBLISH_CONTRACTORS_TO_ERP=false.
 */
class PublishContractorToErp
{
    public function handle(object $event): void
    {
        if (! (bool) config('erp.publish_contractors', true)) {
            return;
        }

        $company = match (true) {
            $event instanceof CompanyCreated => $this->resolveFromCreated($event),
            $event instanceof CompanyUpdated => $this->resolveFromUpdated($event),
            default => null,
        };

        if ($company === null) {
            return;
        }

        $this->dispatch($company);
    }

    private function resolveFromCreated(CompanyCreated $event): ?Company
    {
        $company = $event->company;

        if (empty($company->tax_id)) {
            return null;
        }

        // Защита от петли: Company с уже назначенным erp_id пришла из 1С (через
        // HandleContractorCreated). Повторная публикация не нужна — 1С авторитет.
        if (! empty($company->erp_id)) {
            return null;
        }

        return $company;
    }

    private function resolveFromUpdated(CompanyUpdated $event): ?Company
    {
        $company = $event->company;

        // Защита от петли: Company уже синхронизирована с 1С (имеет erp_id).
        // contractor.updated от 1С → HandleContractorUpdated обновляет Company
        // через withoutEvents, но страховка — даже если апдейт придёт без
        // withoutEvents, мы не опубликуем обратно в 1С.
        if (! empty($company->erp_id)) {
            return null;
        }

        // Публикуем только при первом заполнении tax_id (было пусто → стало непусто).
        // Это гарантирует, что любой другой апдейт (rename, смена адреса и т.п.)
        // не триггерит повторную публикацию.
        if ($company->wasChanged('tax_id') && empty($company->getOriginal('tax_id')) && ! empty($company->tax_id)) {
            return $company;
        }

        return null;
    }

    private function dispatch(Company $company): void
    {
        self::publishCompany($company);
    }

    /**
     * Публикует contractor.created для конкретной Company. Если партнёр ещё
     * не выгружен в 1С (нет User.erp_id) — публикация пропускается.
     *
     * Используется listener-ом при CompanyCreated/Updated и догоном в
     * HandlePartnerCreated/HandlePartnerUpdated после того как партнёр получил UUID.
     */
    public static function publishCompany(Company $company): void
    {
        if (! (bool) config('erp.publish_contractors', true)) {
            return;
        }

        if (empty($company->tax_id)) {
            return;
        }

        // Финальная защита от петли: Company с уже назначенным erp_id не публикуется.
        if (! empty($company->erp_id)) {
            Log::info('PublishContractorToErp: пропуск публикации — Company уже имеет erp_id', [
                'company_id' => $company->id,
                'erp_id' => $company->erp_id,
            ]);

            return;
        }

        $partnerUuid = $company->user?->erp_id;

        if (empty($partnerUuid)) {
            Log::info('PublishContractorToErp: партнёр ещё не выгружен в 1С, публикация отложена', [
                'company_id' => $company->id,
                'user_id' => $company->user_id,
                'tax_id' => $company->tax_id,
            ]);

            return;
        }

        $bankAccounts = $company->bankAccounts
            ->map(fn ($account) => [
                'bank_name' => $account->bank_name,
                'bank_bik' => $account->bank_bik,
                'correspondent_account' => $account->correspondent_account,
                'account_number' => $account->account_number,
                'is_primary' => (bool) $account->is_primary,
            ])
            ->values()
            ->toArray();

        $payload = [
            'event' => 'contractor.created',
            'message_id' => 'msg-'.Str::uuid()->toString(),
            'timestamp' => now()->toIso8601String(),
            'uuid' => (string) ($company->erp_id ?? Str::uuid()->toString()),
            'partner_uuid' => (string) $partnerUuid,
            'tax_id' => (string) $company->tax_id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'country' => self::resolveCountryCode($company->country),
            'tax_code' => $company->tax_code,
            'registration_number' => $company->registration_number,
            'okpo_code' => $company->okpo_code,
            'legal_address' => $company->legal_address,
            'actual_address' => $company->actual_address,
            'phone' => $company->phone,
            'email' => $company->email,
            'bank_accounts' => $bankAccounts,
        ];

        PublishContractorToErpJob::dispatch($payload);
    }

    /**
     * Догон: публикует contractor.created для всех Company пользователя,
     * у которых есть tax_id но ещё не заполнен erp_id. Вызывается из
     * HandlePartner* после того как у партнёра появился UUID.
     */
    public static function catchupForUser(\App\Models\User $user): void
    {
        if (empty($user->erp_id)) {
            return;
        }

        $companies = \App\Models\Company::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('tax_id')
            ->whereNull('erp_id')
            ->get();

        foreach ($companies as $company) {
            self::publishCompany($company->loadMissing(['user', 'bankAccounts']));
        }
    }

    /**
     * Нормализует country к 2-буквенному коду ISO, ожидаемому schema.
     */
    private static function resolveCountryCode(mixed $country): string
    {
        if ($country === null) {
            return 'RU';
        }

        $value = is_object($country) && property_exists($country, 'value') ? $country->value : $country;
        $code = is_string($value) ? strtoupper(substr($value, 0, 2)) : 'RU';

        return strlen($code) === 2 ? $code : 'RU';
    }
}
