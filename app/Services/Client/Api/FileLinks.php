<?php

namespace App\Services\Client\Api;

use App\Models\Contract;
use App\Models\PrintedDocument;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Временные подписанные ссылки на файлы клиента.
 *
 * Агент получает JSON, а человеку нужен файл: ссылка живёт недолго, открывается
 * без Bearer и проверяется подписью и принадлежностью в {@see \App\Http\Controllers\Api\Client\ClientFileController}.
 */
class FileLinks
{
    public const DEFAULT_TTL_MINUTES = 60;

    public const MAX_TTL_MINUTES = 60;

    /**
     * @return array{download_url: string, expires_at: string}
     */
    public function document(PrintedDocument $document, User $user, ?int $ttlMinutes = null): array
    {
        $expires = now()->addMinutes($this->ttl($ttlMinutes));

        return [
            'download_url' => URL::temporarySignedRoute('api.client.v1.files.document', $expires, [
                'document' => $document->getKey(),
                'user' => $user->getKey(),
            ]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * @return array{download_url: string, expires_at: string}
     */
    public function contractFile(Contract $contract, Media $media, User $user, ?int $ttlMinutes = null): array
    {
        $expires = now()->addMinutes($this->ttl($ttlMinutes));

        return [
            'download_url' => URL::temporarySignedRoute('api.client.v1.files.contract-file', $expires, [
                'contract' => $contract->getKey(),
                'media' => $media->getKey(),
                'user' => $user->getKey(),
            ]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params  параметры платёжки (company_id, organization_id, scenario, entry_id, amount, format)
     * @return array{download_url: string, expires_at: string}
     */
    public function paymentOrder(User $user, array $params, ?int $ttlMinutes = null): array
    {
        $expires = now()->addMinutes($this->ttl($ttlMinutes));

        return [
            'download_url' => URL::temporarySignedRoute('api.client.v1.files.payment-order', $expires, $params + ['user' => $user->getKey()]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function ttl(?int $minutes): int
    {
        return max(1, min(self::MAX_TTL_MINUTES, $minutes ?? self::DEFAULT_TTL_MINUTES));
    }
}
