<?php

namespace App\Services\Erp\Handlers;

use App\Models\PersonalManager;
use Illuminate\Support\Facades\Log;

trait ResolvesPersonalManager
{
    /**
     * Резолвит manager из payload в personal_manager_id.
     *
     * @return int|null|false int — найден/создан, null — сбросить, false — не менять
     */
    private function resolvePersonalManagerId(array $payload): int|null|false
    {
        if (! array_key_exists('manager', $payload)) {
            return false;
        }

        $manager = $payload['manager'];

        if ($manager === null) {
            return null;
        }

        $erpUuid = $manager['uuid'] ?? null;
        $name = $manager['name'] ?? null;

        if (! $erpUuid) {
            Log::warning('ERP: manager.uuid отсутствует, менеджер не изменён', [
                'manager' => $manager,
                'uuid' => $payload['uuid'] ?? null,
            ]);

            return false;
        }

        $personalManager = PersonalManager::firstOrCreate(
            ['erp_uuid' => $erpUuid],
            ['name' => $name ?? $erpUuid],
        );

        if ($name && $personalManager->name !== $name) {
            $personalManager->update(['name' => $name]);
        }

        return $personalManager->id;
    }
}
