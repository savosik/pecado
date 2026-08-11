<?php

namespace App\Services\Erp\Handlers;

use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Отмена проведения документа: движения регистра гасятся (v16.0.0).
 *
 * Удаляются и фактические движения, и строки графика оплаты — у непроведённого
 * документа нет ни задолженности, ни плана её погашения.
 *
 * Отметка `is_reverted` остаётся жить в `settlement_documents` после удаления строк.
 * Без неё устаревшее `settlement.posted`, доехавшее следом, воскресило бы отменённый
 * документ: сравнивать ревизию стало бы не с чем.
 */
class HandleSettlementReverted
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $documentUuid = $payload['document_uuid'] ?? null;

        if (! is_string($documentUuid) || trim($documentUuid) === '') {
            throw new ErpUnprocessableMessageException('settlement.reverted: отсутствует document_uuid');
        }

        $documentUuid = trim($documentUuid);

        $removed = DB::transaction(function () use ($documentUuid, $payload): int {
            $removed = SettlementEntry::query()->where('document_uuid', $documentUuid)->delete();

            SettlementDocument::query()->updateOrCreate(
                ['uuid' => $documentUuid],
                [
                    'document_kind' => $payload['document_kind'] ?? null,
                    'document_number' => $payload['document_number'] ?? null,
                    'is_reverted' => true,
                ],
            );

            return $removed;
        });

        app(SettlementProjector::class)->projectDocument($documentUuid);

        Log::info('settlement.reverted: движения документа удалены', [
            'document_uuid' => $documentUuid,
            'removed' => $removed,
        ]);
    }
}
