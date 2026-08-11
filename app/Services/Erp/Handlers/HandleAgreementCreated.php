<?php

namespace App\Services\Erp\Handlers;

use App\Models\Agreement;
use App\Services\Erp\Support\OrganizationResolver;
use App\Services\Erp\Support\ResolvesContractorParty;
use Illuminate\Support\Facades\Log;

/**
 * Соглашение с клиентом из 1С — создание и обновление (v16.0.0).
 *
 * Один класс на оба события намеренно: payload у `created` и `updated` полный
 * и одинаковый, а разводить их по двум классам значило бы получить два немного
 * разных набора полей. Ровно та же ловушка уже стоила нам потерянных платежей,
 * когда `payment.updated` на неизвестный документ ничего не создавал.
 *
 * Связи резолвятся мягко: соглашение может приехать раньше контрагента, и терять
 * его из-за порядка доставки нельзя. Сырые UUID сохраняются всегда — по ним связь
 * доклеится позже.
 */
class HandleAgreementCreated
{
    use ResolvesContractorParty;

    /** Имя события для логов — подменяется наследником. */
    protected string $event = 'agreement.created';

    /** @var list<string> Поля, переносимые из payload как есть. */
    private const PLAIN_FIELDS = [
        'number',
        'date',
        'name',
        'currency_code',
        'settlement_procedure',
        'credit_limit',
        'deferral_days',
        'status',
        'revision',
        'erp_created_at',
        'erp_updated_at',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning($this->event.': отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $contractorUuid = $payload['contractor_uuid'] ?? null;
        $partnerUuid = $payload['partner_uuid'] ?? null;
        $taxId = $payload['tax_id'] ?? null;

        [$companyId, $userId] = $this->resolveCompanyAndUser(
            is_string($contractorUuid) ? $contractorUuid : null,
            is_string($taxId) ? $taxId : null,
            is_string($partnerUuid) ? $partnerUuid : null,
        );

        if ($companyId === null) {
            Log::info($this->event.': контрагент ещё не на сайте, связь доклеится позже', [
                'uuid' => $uuid,
                'contractor_uuid' => $contractorUuid,
            ]);
        }

        $attributes = [
            'user_id' => $userId,
            'company_id' => $companyId,
            'organization_id' => app(OrganizationResolver::class)
                ->resolveByUuid(is_string($payload['organization_uuid'] ?? null) ? $payload['organization_uuid'] : null)
                ?->id,
            'partner_uuid' => $partnerUuid,
            'contractor_uuid' => $contractorUuid,
            'organization_uuid' => $payload['organization_uuid'] ?? null,
            'tax_id' => $taxId,
        ];

        foreach (self::PLAIN_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }

        $agreement = Agreement::withTrashed()->firstOrNew(['uuid' => trim($uuid)]);

        // Статус по умолчанию — только у нового соглашения. Иначе сообщение без
        // поля `status` открывало бы обратно закрытое: отсутствие поля означает
        // «1С не прислала», а не «сбросить».
        if (! $agreement->exists) {
            $attributes['status'] ??= Agreement::STATUS_ACTIVE;
        }

        $agreement->fill($attributes);

        // Повторное проведение возвращает помеченное удалённым соглашение:
        // в 1С снятие пометки — обычная операция, а не исключение.
        if ($agreement->trashed()) {
            $agreement->deleted_at = null;
        }

        $agreement->save();

        Log::info($this->event.': соглашение сохранено', [
            'agreement_id' => $agreement->id,
            'uuid' => $agreement->uuid,
            'company_id' => $companyId,
        ]);
    }
}
