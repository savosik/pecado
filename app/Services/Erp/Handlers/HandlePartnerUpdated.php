<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Jobs\NormalizeUserDataJob;
use App\Models\ClientStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-02 v12: Обработка события partner.updated из 1С.
 *
 * Обновляет атрибуты существующего пользователя.
 * НЕ создаёт нового пользователя (в отличие от partner.created).
 *
 * Два сценария поиска:
 * 1. По erp_id (UUID) — идемпотентность
 * 2. По email (login) — привязка erp_id + обновление атрибутов
 *
 * Все операции через User::withoutEvents() для предотвращения петли:
 * partner.updated → UserUpdated → PublishUserToErp → partner.created → LOOP
 */
class HandlePartnerUpdated
{
    use NormalizesCountry;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $login = $payload['login'] ?? null;
        $email = $payload['email'] ?? $login;

        if (! $uuid) {
            Log::warning('partner.updated: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        // Сценарий 1: Ищем по erp_id (повторная доставка — идемпотентность)
        $user = User::where('erp_id', $uuid)->first();

        if ($user) {
            $this->updateUser($user, $payload);

            Log::info('partner.updated: пользователь найден по erp_id, обновлён', [
                'user_id' => $user->id,
                'erp_id' => $uuid,
            ]);

            return;
        }

        // Сценарий 2: Ищем по email/login — привязываем erp_id
        if ($email) {
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            $this->updateUser($user, $payload, bindErpId: true);

            Log::info('partner.updated: пользователь найден по email, erp_id привязан', [
                'user_id' => $user->id,
                'email' => $email,
                'erp_id' => $uuid,
            ]);

            return;
        }

        // Пользователь не найден — partner.updated не создаёт новых
        Log::warning('partner.updated: пользователь не найден', [
            'uuid' => $uuid,
            'login' => $login,
            'email' => $email,
        ]);
    }

    /**
     * Обновить атрибуты пользователя из payload.
     */
    private function updateUser(User $user, array $payload, bool $bindErpId = false): void
    {
        $updateData = [];

        // erp_id — привязка при поиске по email
        if ($bindErpId && isset($payload['uuid'])) {
            $updateData['erp_id'] = $payload['uuid'];
        }

        // name
        if (isset($payload['name'])) {
            $updateData['name'] = $payload['name'];
        }

        // phone
        if (array_key_exists('phone', $payload)) {
            $phone = $payload['phone'];
            if ($phone !== null) {
                $phone = Str::limit(trim($phone), 252);
            }
            $updateData['phone'] = $phone;
        }

        // city
        if (array_key_exists('city', $payload)) {
            $updateData['city'] = $payload['city'];
        }

        // country
        if (isset($payload['country'])) {
            $updateData['country'] = $this->normalizeCountry($payload['country']);
        }

        // is_active → UserStatus
        if (array_key_exists('is_active', $payload)) {
            $updateData['status'] = $payload['is_active']
                ? UserStatus::ACTIVE
                : UserStatus::BLOCKED;
        }

        // client_status → ClientStatus по external_id
        $clientStatusId = $this->resolveClientStatusId($payload);
        if ($clientStatusId !== false) {
            $updateData['client_status_id'] = $clientStatusId;
        }

        if (empty($updateData)) {
            return;
        }

        User::withoutEvents(function () use ($user, $updateData) {
            $user->update($updateData);
        });

        NormalizeUserDataJob::dispatch($user->id);
    }

    /**
     * Резолвит client_status из payload в client_status_id.
     *
     * @return int|null|false int — найден, null — сбросить, false — не менять
     */
    private function resolveClientStatusId(array $payload): int|null|false
    {
        // Поле отсутствует в payload — не менять текущий статус
        if (! array_key_exists('client_status', $payload)) {
            return false;
        }

        $clientStatusCode = $payload['client_status'];

        // Явный null — сбросить статус
        if ($clientStatusCode === null) {
            return null;
        }

        $clientStatusId = ClientStatus::where('external_id', $clientStatusCode)->value('id');

        if ($clientStatusId === null) {
            Log::warning('partner.updated: неизвестный client_status, статус не изменён', [
                'client_status' => $clientStatusCode,
                'uuid' => $payload['uuid'] ?? null,
            ]);

            return false;
        }

        return $clientStatusId;
    }
}
