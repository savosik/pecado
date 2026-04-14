<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Jobs\NormalizeUserDataJob;
use App\Models\ClientStatus;
use App\Models\User;
use App\Models\Region;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * US-02 v11: Обработка события partner.created из 1С.
 *
 * Три сценария:
 * 1а. Пользователь найден по erp_id (идемпотентность) → обновляет данные
 * 1б. Пользователь найден по email (login) → привязывает erp_id
 * 2.  Пользователь не найден + есть password → создаёт нового
 *
 * v11:
 * - is_active (boolean) → определяет UserStatus (ACTIVE / BLOCKED)
 * - client_status (string|null) → резолвит ClientStatus по external_id
 *
 * Все операции через User::withoutEvents() для предотвращения петли:
 * partner.created → UserUpdated → PublishUserToErp → partner.created → LOOP
 */
class HandlePartnerCreated
{
    use NormalizesCountry;

    public function handle(array $payload): void
    {
        $uuid     = $payload['uuid']     ?? null;
        $login    = $payload['login']    ?? null;
        $email    = $payload['email']    ?? $login;
        $name     = $payload['name'] ?? null;

        $phone    = $payload['phone']    ?? null;
        if ($phone !== null) {
            $phone = Str::limit(trim($phone), 252); // varchar(255) с запасом
        }
        $password = $payload['password'] ?? null;

        $city     = $payload['city'] ?? null;
        $country  = $this->normalizeCountry($payload['country'] ?? null);

        $regionName = $payload['region'] ?? null;
        $currencyCode = $payload['currency'] ?? null;

        $regionId = null;
        if ($regionName) {
            $regionId = Region::where('name', $regionName)->value('id');
        }

        // Валюта из 1С не привязывается к пользователю — определяется через регион
        if ($currencyCode) {
            Log::info('partner.created: валюта из 1С игнорируется, определяется через регион', [
                'currency' => $currencyCode,
                'region'   => $regionName,
            ]);
        }

        // v11: is_active (boolean) → UserStatus
        $isActive = $payload['is_active'] ?? true;
        $userStatus = $isActive ? UserStatus::ACTIVE : UserStatus::BLOCKED;

        // v11: client_status → ClientStatus по external_id
        $clientStatusId = $this->resolveClientStatusId($payload);

        if (!$uuid || !$login) {
            Log::warning('partner.created: отсутствует uuid или login', ['payload' => $payload]);

            return;
        }

        // Сценарий 1а: Ищем по erp_id (повторная доставка от 1С — идемпотентность)
        $user = User::where('erp_id', $uuid)->first();

        if ($user) {
            User::withoutEvents(function () use ($user, $uuid, $city, $country, $regionId, $phone, $userStatus, $clientStatusId) {
                $updateData = array_filter([
                    'erp_id'      => $uuid,
                    'status'      => $userStatus,
                    'city'        => $city,
                    'country'     => $country,
                    'region_id'   => $regionId,
                    'phone'       => $phone,
                ], fn($v) => $v !== null);

                // client_status_id может быть null (сброс) — не фильтруем
                if ($clientStatusId !== false) {
                    $updateData['client_status_id'] = $clientStatusId;
                }

                $user->update($updateData);
            });

            Log::info('partner.created: пользователь найден по erp_id, обновлён', [
                'user_id' => $user->id,
                'erp_id'  => $uuid,
            ]);

            NormalizeUserDataJob::dispatch($user->id);

            return;
        }

        // Сценарий 1б: Ищем по email/login
        $user = User::where('email', $login)->first();

        if ($user) {
            User::withoutEvents(function () use ($user, $uuid, $userStatus, $clientStatusId) {
                $updateData = [
                    'erp_id' => $uuid,
                    'status' => $userStatus,
                ];

                if ($clientStatusId !== false) {
                    $updateData['client_status_id'] = $clientStatusId;
                }

                $user->update($updateData);
            });

            Log::info('partner.created: пользователь найден по email, активирован', [
                'user_id' => $user->id,
                'login'   => $user->email,
                'erp_id'  => $uuid,
            ]);

            return;
        }

        // Сценарий 2: Создание нового пользователя из 1С
        if (!$password) {
            Log::warning('partner.created: пользователь не найден и нет пароля для создания', [
                'login' => $login,
                'uuid'  => $uuid,
            ]);

            return;
        }

        $createData = [
            'name'                 => $name ?? $login,
            'city'                 => $city,
            'country'              => $country,
            'region_id'            => $regionId,
            'email'                => $email,
            'phone'                => $phone,
            'password'             => $password,
            'must_change_password' => true,
            'erp_id'               => $uuid,
            'status'               => $userStatus,
        ];

        if ($clientStatusId !== false) {
            $createData['client_status_id'] = $clientStatusId;
        }

        $newUser = User::withoutEvents(function () use ($createData) {
            return User::create($createData);
        });

        Log::info('partner.created: новый пользователь создан из 1С', [
            'user_id'              => $newUser->id,
            'login'                => $newUser->email,
            'erp_id'               => $uuid,
            'must_change_password' => true,
        ]);

        NormalizeUserDataJob::dispatch($newUser->id);
    }

    /**
     * Резолвит client_status из payload в client_status_id.
     *
     * @return int|null|false  int — найден, null — сбросить, false — не менять
     */
    private function resolveClientStatusId(array $payload): int|null|false
    {
        // Поле отсутствует в payload — не менять текущий статус
        if (!array_key_exists('client_status', $payload)) {
            return false;
        }

        $clientStatusCode = $payload['client_status'];

        // Явный null — сбросить статус
        if ($clientStatusCode === null) {
            return null;
        }

        $clientStatusId = ClientStatus::where('external_id', $clientStatusCode)->value('id');

        if ($clientStatusId === null) {
            Log::warning('partner.created: неизвестный client_status, статус не изменён', [
                'client_status' => $clientStatusCode,
                'uuid'          => $payload['uuid'] ?? null,
            ]);

            return false;
        }

        return $clientStatusId;
    }
}
