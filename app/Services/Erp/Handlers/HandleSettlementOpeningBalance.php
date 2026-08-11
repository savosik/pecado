<?php

namespace App\Services\Erp\Handlers;

use App\Models\SettlementEntry;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\ResolvesSettlementParties;
use Illuminate\Support\Facades\Log;

/**
 * Начальное сальдо взаиморасчётов на дату начала ленты движений (v16.0.0).
 *
 * Одна строка регистра `type = opening_balance` на дату 01.01.2026. Дальше лента
 * складывается из обычных движений, и баланс на любую дату получается тем же
 * `SUM(amount)`, что и всё остальное, — отдельной ветки в расчёте нет.
 *
 * Точка **не сверена бухгалтерией**: 1С ручается только за 01.07.2026, дату
 * закрытия периода. Расхождение делает измеримым `settlement.checkpoint`.
 *
 * Повторная доставка **заменяет** строку, а не добавляет вторую: сальдо на дату
 * величина одна, и задвоив её, мы бы удвоили долг всем клиентам разом.
 */
class HandleSettlementOpeningBalance
{
    use ResolvesSettlementParties;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $this->stringOrNull($payload['uuid'] ?? null);
        $asOfDate = $this->stringOrNull($payload['as_of_date'] ?? null);

        if ($uuid === null || $asOfDate === null || ! array_key_exists('amount', $payload)) {
            throw new ErpUnprocessableMessageException(
                'settlement.opening_balance: отсутствует uuid, as_of_date или amount',
            );
        }

        $amount = (float) $payload['amount'];
        $currency = $this->stringOrNull($payload['currency_code'] ?? null) ?? 'RUB';

        $attributes = array_merge($this->resolveSettlementParties($payload), [
            'source' => 'erp',
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_OPENING_BALANCE,
            'date' => $asOfDate,
            'amount' => $amount,
            'currency_code' => $currency,
            'amount_rub' => is_numeric($payload['amount_rub'] ?? null)
                ? (float) $payload['amount_rub']
                : ($currency === 'RUB' ? $amount : null),
            'partner_uuid' => $this->stringOrNull($payload['partner_uuid'] ?? null),
            'contractor_uuid' => $this->stringOrNull($payload['contractor_uuid'] ?? null),
            'organization_uuid' => $this->stringOrNull($payload['organization_uuid'] ?? null),
            'agreement_uuid' => $this->stringOrNull($payload['agreement_uuid'] ?? null),
            'document_kind' => 'other',
            'document_date' => $asOfDate,
            'comment' => $this->stringOrNull($payload['comment'] ?? null)
                ?? 'Начальное сальдо на '.$asOfDate,
        ]);

        SettlementEntry::query()->updateOrCreate(['uuid' => $uuid], $attributes);

        Log::info('settlement.opening_balance: начальное сальдо сохранено', [
            'uuid' => $uuid,
            'as_of_date' => $asOfDate,
            'amount' => $amount,
        ]);
    }
}
