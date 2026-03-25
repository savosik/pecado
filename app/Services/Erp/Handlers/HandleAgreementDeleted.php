<?php

namespace App\Services\Erp\Handlers;

use App\Models\Agreement;
use Illuminate\Support\Facades\Log;

class HandleAgreementDeleted
{
    public function handle(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $uuid = $data['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('agreement.deleted: отсутствует uuid', ['payload' => $payload]);
            return;
        }

        $agreement = Agreement::where('uuid', $uuid)->first();
        if ($agreement) {
            $agreement->delete();
            Log::info('agreement.deleted: индивидуальное соглашение удалено', [
                'uuid' => $uuid
            ]);
        }
    }
}
