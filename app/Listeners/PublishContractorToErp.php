<?php

namespace App\Listeners;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Jobs\PublishContractorToErpJob;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-07: Слушает CompanyCreated / CompanyUpdated и публикует исходящие
 * сообщения о контрагенте в erp_out.contractors.
 *
 * Два сценария публикации:
 *  - contractor.created (v13.2): первое заполнение tax_id у Company без erp_id.
 *  - contractor.updated (v13.X): значимое изменение Company с уже заполненным
 *    erp_id (синхронизированный с 1С контрагент). Также триггерится из
 *    CompanyBankAccountObserver при изменении банковских реквизитов.
 *
 * Защита от петли contractor.updated → CompanyUpdated → contractor.updated → ... —
 * три уровня:
 *  1. Входящие handlers (HandleContractorCreated/Updated/Deleted) обновляют
 *     Company через Company::withoutEvents() — CompanyUpdated не диспатчится.
 *  2. Транзиентный флаг $company->fromErp выставляется в HandleContractor* перед
 *     любыми операциями. Listener проверяет его и пропускает публикацию.
 *  3. Для contractor.created: явная проверка erp_id (если уже есть — не публикуем).
 *
 * Откат — env-флаг PUBLISH_CONTRACTORS_TO_ERP=false (общий для обоих событий).
 */
class PublishContractorToErp
{
    /**
     * Поля Company, изменение которых триггерит contractor.updated.
     * Меняется состав — обнови описание в docs-erp/content/rules/contractors.md.
     */
    private const SIGNIFICANT_FIELDS = [
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
        'country',
    ];

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

        // Маркер: модель в составе resolveFromUpdated может быть либо
        // "первое заполнение tax_id" (publishCompany / contractor.created),
        // либо "значимое изменение синхронизированного контрагента"
        // (publishCompanyUpdated / contractor.updated). Решает наличие erp_id.
        if (! empty($company->erp_id)) {
            self::publishCompanyUpdated($company);
        } else {
            self::publishCompany($company);
        }
    }

    private function resolveFromCreated(CompanyCreated $event): ?Company
    {
        $company = $event->company;

        // Уровень 2: модель пришла из обработки входящего сообщения 1С.
        if ($company->fromErp ?? false) {
            return null;
        }

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

        // Уровень 2: модель только что обновлена входящим сообщением 1С.
        if ($company->fromErp ?? false) {
            return null;
        }

        // Сценарий A: Company уже синхронизирована с 1С (есть erp_id).
        // Публикуем contractor.updated, если изменилось хотя бы одно
        // значимое поле.
        if (! empty($company->erp_id)) {
            foreach (self::SIGNIFICANT_FIELDS as $field) {
                if ($company->wasChanged($field)) {
                    return $company;
                }
            }

            return null;
        }

        // Сценарий B: Company ещё не синхронизирована с 1С (erp_id пуст).
        // Публикуем contractor.created только при первом заполнении tax_id
        // (было пусто → стало непусто). Любой другой апдейт игнорируется.
        if ($company->wasChanged('tax_id') && empty($company->getOriginal('tax_id')) && ! empty($company->tax_id)) {
            return $company;
        }

        return null;
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

        // Финальная защита от петли: Company с уже назначенным erp_id не публикуется
        // как contractor.created — для неё используется publishCompanyUpdated.
        if (! empty($company->erp_id)) {
            Log::info('PublishContractorToErp: пропуск contractor.created — Company уже имеет erp_id', [
                'company_id' => $company->id,
                'erp_id' => $company->erp_id,
            ]);

            return;
        }

        $partnerUuid = $company->user?->erp_id;

        if (empty($partnerUuid)) {
            Log::info('PublishContractorToErp: партнёр ещё не выгружен в 1С, contractor.created отложен', [
                'company_id' => $company->id,
                'user_id' => $company->user_id,
                'tax_id' => $company->tax_id,
            ]);

            return;
        }

        $payload = [
            'event' => 'contractor.created',
            'message_id' => 'msg-'.Str::uuid()->toString(),
            'timestamp' => now()->toIso8601String(),
            'uuid' => (string) Str::uuid(),
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
            'bank_accounts' => self::serializeBankAccounts($company),
        ];

        PublishContractorToErpJob::dispatch($payload);
    }

    /**
     * Публикует contractor.updated для синхронизированной с 1С Company
     * (имеющей erp_id). Используется и listener-ом, и
     * CompanyBankAccountObserver-ом при изменении банковских реквизитов.
     */
    public static function publishCompanyUpdated(Company $company): void
    {
        if (! (bool) config('erp.publish_contractors', true)) {
            return;
        }

        if (empty($company->erp_id)) {
            Log::warning('PublishContractorToErp: contractor.updated требует erp_id, публикация пропущена', [
                'company_id' => $company->id,
                'tax_id' => $company->tax_id,
            ]);

            return;
        }

        if (empty($company->tax_id)) {
            return;
        }

        $partnerUuid = $company->user?->erp_id;

        if (empty($partnerUuid)) {
            Log::info('PublishContractorToErp: партнёр ещё не выгружен в 1С, contractor.updated отложен', [
                'company_id' => $company->id,
                'user_id' => $company->user_id,
                'erp_id' => $company->erp_id,
            ]);

            return;
        }

        $payload = [
            'event' => 'contractor.updated',
            'message_id' => 'msg-'.Str::uuid()->toString(),
            'timestamp' => now()->toIso8601String(),
            'uuid' => (string) $company->erp_id,
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
            'bank_accounts' => self::serializeBankAccounts($company),
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
     * Сериализует банковские счета Company для payload-а 1С.
     */
    private static function serializeBankAccounts(Company $company): array
    {
        return $company->bankAccounts
            ->map(fn ($account) => [
                'bank_name' => $account->bank_name,
                'bank_bik' => $account->bank_bik,
                'correspondent_account' => $account->correspondent_account,
                'account_number' => $account->account_number,
                'is_primary' => (bool) $account->is_primary,
            ])
            ->values()
            ->toArray();
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
