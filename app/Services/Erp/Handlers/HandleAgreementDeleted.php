<?php

namespace App\Services\Erp\Handlers;

use App\Models\Agreement;
use Illuminate\Support\Facades\Log;

/**
 * Соглашение с клиентом из 1С — пометка удаления (v16.0.0).
 *
 * Удаление мягкое, и движения регистра на нём **не завязаны**: `agreement_id`
 * в них остаётся, а история продолжает читаться. Каскадное удаление движений
 * было бы прямой потерей денег — в 1С пометка удаления соглашения задолженность
 * не отменяет.
 */
class HandleAgreementDeleted
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            Log::warning('agreement.deleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $agreement = Agreement::where('uuid', trim($uuid))->first();

        if (! $agreement) {
            Log::info('agreement.deleted: соглашение не найдено, пропускаем', ['uuid' => $uuid]);

            return;
        }

        $agreement->update(['status' => Agreement::STATUS_CLOSED]);
        $agreement->delete();

        Log::info('agreement.deleted: соглашение помечено удалённым', [
            'agreement_id' => $agreement->id,
            'uuid' => $agreement->uuid,
        ]);
    }
}
