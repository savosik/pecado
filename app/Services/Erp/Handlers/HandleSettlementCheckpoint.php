<?php

namespace App\Services\Erp\Handlers;

use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\ResolvesSettlementParties;
use Illuminate\Support\Facades\Log;

/**
 * Сверенное сальдо на дату закрытия периода (v16.0.0).
 *
 * Контрольная сумма, а не источник данных: ни один экран её не читает. Смысл
 * в проверке — «сальдо на 01.01 + движения первого полугодия» обязано сойтись
 * с этой цифрой. Сойдётся — история первого полугодия достоверна, и мы это знаем;
 * не сойдётся — увидим точную величину расхождения вместо догадок.
 *
 * Ключ уникальности — контрагент × организация × валюта × дата, то есть ровно
 * ось акта сверки. Повторная доставка обновляет запись, а не добавляет вторую.
 */
class HandleSettlementCheckpoint
{
    use ResolvesSettlementParties;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $contractorUuid = $this->stringOrNull($payload['contractor_uuid'] ?? null);
        $asOfDate = $this->stringOrNull($payload['as_of_date'] ?? null);

        if ($contractorUuid === null || $asOfDate === null || ! array_key_exists('amount', $payload)) {
            throw new ErpUnprocessableMessageException(
                'settlement.checkpoint: отсутствует contractor_uuid, as_of_date или amount',
            );
        }

        $amount = (float) $payload['amount'];
        $currency = $this->stringOrNull($payload['currency_code'] ?? null) ?? 'RUB';
        $parties = $this->resolveSettlementParties($payload);

        // Точка по внутренней организации («Реклама») сверять нечего: её движения
        // на входе отбрасываются, и сохранённая точка вечно показывала бы расхождение.
        if (in_array($parties['organization_id'], Organization::settlementsExcludedIds(), true)) {
            Log::info('settlement.checkpoint: точка исключённой организации пропущена', [
                'contractor_uuid' => $contractorUuid,
                'organization_id' => $parties['organization_id'],
                'as_of_date' => $asOfDate,
            ]);

            return;
        }

        // Пустая строка вместо NULL: уникальный индекс MySQL считает NULL-ы
        // различными, и точка без организации задвоилась бы при повторе.
        $organizationUuid = $this->stringOrNull($payload['organization_uuid'] ?? null) ?? '';

        $keys = [
            'contractor_uuid' => $contractorUuid,
            'organization_uuid' => $organizationUuid,
            'currency_code' => $currency,
            'as_of_date' => $asOfDate,
        ];

        // Поиск через whereDate, а не updateOrCreate: каст `date` сохраняет значение
        // форматом соединения, и в SQLite это «2026-07-01 00:00:00». Сравнение
        // с чистой датой не нашло бы существующую точку, а вставка второй упёрлась
        // бы в уникальный индекс — на MySQL всё работало бы, а тесты падали.
        $checkpoint = SettlementCheckpoint::query()
            ->where('contractor_uuid', $contractorUuid)
            ->where('organization_uuid', $organizationUuid)
            ->where('currency_code', $currency)
            ->whereDate('as_of_date', $asOfDate)
            ->first() ?? new SettlementCheckpoint($keys);

        $checkpoint->fill([
            'user_id' => $parties['user_id'],
            'company_id' => $parties['company_id'],
            'organization_id' => $parties['organization_id'],
            'tax_id' => $this->stringOrNull($payload['tax_id'] ?? null),
            'amount' => $amount,
            'amount_rub' => is_numeric($payload['amount_rub'] ?? null)
                ? (float) $payload['amount_rub']
                : ($currency === 'RUB' ? $amount : null),
            'is_verified' => (bool) ($payload['is_verified'] ?? false),
            'erp_updated_at' => $payload['erp_updated_at'] ?? null,
        ])->save();

        Log::info('settlement.checkpoint: контрольная точка сохранена', [
            'contractor_uuid' => $contractorUuid,
            'as_of_date' => $asOfDate,
            'amount' => $amount,
        ]);
    }
}
