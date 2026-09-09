<?php

namespace App\Services\Crm\Avatars;

use App\Models\CrmClientAvatar;

/**
 * Ссылки на аватарки для интерфейса — пачкой на страницу списка.
 *
 * Отдельный класс, потому что нужен в двух местах (список и карточка) и потому
 * что запрос обязан быть один на страницу: аватарка не стоит того, чтобы
 * список из 25 партнёров сделал 25 обращений к базе.
 *
 * В адрес подставляется кусок контрольной суммы. Без него браузер, однажды
 * закешировавший картинку на неделю, показывал бы старую и после замены.
 */
class ClientAvatarLinks
{
    /**
     * @param  list<int>  $clientIds
     * @return array<int, array{url: string, source: string|null}>
     */
    public function forClients(array $clientIds): array
    {
        if ($clientIds === [] || ! config('crm_avatars.enabled')) {
            return [];
        }

        return CrmClientAvatar::query()
            ->whereIn('user_id', $clientIds)
            ->whereNotNull('path')
            ->get(['user_id', 'checksum', 'source'])
            ->mapWithKeys(fn (CrmClientAvatar $avatar): array => [
                (int) $avatar->user_id => [
                    'url' => $this->url((int) $avatar->user_id, $avatar->checksum),
                    'source' => $avatar->source,
                ],
            ])
            ->all();
    }

    /**
     * @return array{url: string, source: string|null}|null
     */
    public function for(int $clientId): ?array
    {
        return $this->forClients([$clientId])[$clientId] ?? null;
    }

    private function url(int $clientId, ?string $checksum): string
    {
        return route('crm.clients.avatar', array_filter([
            'client' => $clientId,
            'v' => $checksum === null ? null : substr($checksum, 0, 12),
        ]));
    }
}
