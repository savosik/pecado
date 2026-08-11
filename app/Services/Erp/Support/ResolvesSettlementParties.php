<?php

namespace App\Services\Erp\Support;

use App\Models\Agreement;

/**
 * Резолв сторон движения регистра взаиморасчётов (v16.0.0).
 *
 * Собирает четыре связи разом — партнёр, контрагент, наша организация, соглашение —
 * потому что в движении они приезжают вместе и разбирать их по разным местам значило бы
 * получить четыре немного разных трактовки одного и того же payload.
 *
 * Главное правило: **ненайденная связь не отбрасывает движение.** Деньги важнее связей.
 * Строка сохраняется с NULL и сырым UUID рядом; связь доклеится, когда справочник
 * доедет. Обратное решение (отбрасывать) дало бы дыру в балансе, которую потом
 * пришлось бы искать сверкой.
 */
trait ResolvesSettlementParties
{
    use ResolvesContractorParty;

    /**
     * Связи, готовые к записи в `settlement_entries`.
     *
     * @param  array<string, mixed>  $source  Строка движения либо шапка сообщения
     * @return array<string, int|null>
     */
    protected function resolveSettlementParties(array $source): array
    {
        $contractorUuid = $this->stringOrNull($source['contractor_uuid'] ?? null);
        $partnerUuid = $this->stringOrNull($source['partner_uuid'] ?? null);
        $taxId = $this->stringOrNull($source['tax_id'] ?? null);

        [$companyId, $userId] = $this->resolveCompanyAndUser($contractorUuid, $taxId, $partnerUuid);

        return [
            'user_id' => $userId,
            'company_id' => $companyId,
            'organization_id' => app(OrganizationResolver::class)
                ->resolveByUuid($this->stringOrNull($source['organization_uuid'] ?? null))
                ?->id,
            'agreement_id' => $this->resolveAgreementId($this->stringOrNull($source['agreement_uuid'] ?? null)),
        ];
    }

    /**
     * Соглашение по UUID. Заглушку, в отличие от организации, НЕ создаём:
     * организация нужна как ось акта сверки, а соглашение — только фильтр,
     * и пустая карточка в справочнике полезнее не сделает.
     */
    protected function resolveAgreementId(?string $agreementUuid): ?int
    {
        if ($agreementUuid === null) {
            return null;
        }

        return Agreement::withTrashed()->where('uuid', $agreementUuid)->value('id');
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
