<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Jobs\NormalizeUserDataJob;
use App\Listeners\PublishContractorToErp;
use App\Models\ClientStatus;
use App\Models\PersonalManager;
use App\Models\Region;
use App\Models\User;
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
    use NormalizesCountry, ResolvesPersonalManager;

    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        // E-mail нормализуем в нижний регистр: 1С генерирует пароль от lowercase-email,
        // иначе temporary_password (crc32 email) не совпадёт и партнёр не войдёт.
        $email = $payload['email'] ?? null;
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        $login = $payload['login'] ?? $email;
        $login = $login !== null ? mb_strtolower(trim($login)) : null;
        $name = $payload['name'] ?? null;

        $phone = $payload['phone'] ?? null;
        if ($phone !== null) {
            $phone = Str::limit(trim($phone), 252); // varchar(255) с запасом
        }
        $password = $payload['password'] ?? null;

        $city = $payload['city'] ?? null;
        $country = $this->normalizeCountry($payload['country'] ?? null);

        // v11: is_active (boolean) → UserStatus
        $isActive = $payload['is_active'] ?? true;
        $userStatus = $isActive ? UserStatus::ACTIVE : UserStatus::BLOCKED;

        // v11: client_status → ClientStatus по external_id
        $clientStatusId = $this->resolveClientStatusId($payload);

        // v15: manager → PersonalManager по erp_uuid
        $personalManagerId = $this->resolvePersonalManagerId($payload);

        if (! $uuid || ! $email) {
            Log::warning('partner.created: отсутствует uuid или email', ['payload' => $payload]);

            return;
        }

        // Сценарий 1а: Ищем по erp_id (повторная доставка от 1С — идемпотентность)
        $user = User::where('erp_id', $uuid)->first();

        if ($user) {
            User::withoutEvents(function () use ($user, $uuid, $city, $country, $phone, $userStatus, $clientStatusId, $personalManagerId) {
                $updateData = array_filter([
                    'erp_id' => $uuid,
                    'status' => $userStatus,
                    'city' => $city,
                    'country' => $country,
                    'phone' => $phone,
                ], fn ($v) => $v !== null);

                // client_status_id может быть null (сброс) — не фильтруем
                if ($clientStatusId !== false) {
                    $updateData['client_status_id'] = $clientStatusId;
                }

                // personal_manager_id может быть null (сброс) — не фильтруем
                if ($personalManagerId !== false) {
                    $updateData['personal_manager_id'] = $personalManagerId;
                }

                $user->update($updateData);
            });

            Log::info('partner.created: пользователь найден по erp_id, обновлён', [
                'user_id' => $user->id,
                'erp_id' => $uuid,
            ]);

            NormalizeUserDataJob::dispatch($user->id);

            return;
        }

        // Сценарий 1б: Ищем по email/login
        $user = User::where('email', $login)->first();

        if ($user) {
            User::withoutEvents(function () use ($user, $uuid, $userStatus, $clientStatusId, $personalManagerId) {
                $updateData = [
                    'erp_id' => $uuid,
                    'status' => $userStatus,
                ];

                if ($clientStatusId !== false) {
                    $updateData['client_status_id'] = $clientStatusId;
                }

                if ($personalManagerId !== false) {
                    $updateData['personal_manager_id'] = $personalManagerId;
                }

                $user->update($updateData);
            });

            Log::info('partner.created: пользователь найден по email, активирован', [
                'user_id' => $user->id,
                'login' => $user->email,
                'erp_id' => $uuid,
            ]);

            // v13.2: после того как партнёр получил UUID — догоняем зависшие Company
            PublishContractorToErp::catchupForUser($user->refresh());

            return;
        }

        // Сценарий 2: Создание нового пользователя из 1С
        if (! $password) {
            Log::warning('partner.created: пользователь не найден и нет пароля для создания', [
                'login' => $login,
                'uuid' => $uuid,
            ]);

            return;
        }

        $defaultRegionId = Region::defaultId();

        $createData = [
            'name' => $name ?? $login,
            'city' => $city,
            'country' => $country,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'must_change_password' => true,
            'erp_id' => $uuid,
            'status' => $userStatus,
            'region_id' => $defaultRegionId,
            'user_kind' => $this->kindForNewPartner($email),
        ];

        if ($clientStatusId !== false) {
            $createData['client_status_id'] = $clientStatusId;
        }

        if ($personalManagerId !== false) {
            $createData['personal_manager_id'] = $personalManagerId;
        }

        $newUser = User::withoutEvents(function () use ($createData) {
            return User::create($createData);
        });

        Log::info('partner.created: новый пользователь создан из 1С', [
            'user_id' => $newUser->id,
            'login' => $newUser->email,
            'erp_id' => $uuid,
            'must_change_password' => true,
        ]);

        NormalizeUserDataJob::dispatch($newUser->id);
    }

    /**
     * Тип аккаунта для партнёра, впервые пришедшего из 1С.
     *
     * В 1С сотрудники заведены и партнёрами тоже (компания работает через несколько
     * юрлиц), поэтому `partner.created` приходит и на менеджера отдела продаж. Без
     * этой проверки он окажется в CRM среди собственных клиентов — ровно то, что
     * пришлось разгребать миграцией `mark_manager_accounts_as_staff`.
     *
     * Сверяем по email карточки менеджера: типа партнёра в payload нет, а совпадение
     * адреса с справочником менеджеров случайным не бывает.
     */
    private function kindForNewPartner(?string $email): UserKind
    {
        if (! $email) {
            return UserKind::CLIENT;
        }

        $isManager = PersonalManager::query()
            ->whereNotNull('email')
            ->where('email', $email)
            ->exists();

        if ($isManager) {
            Log::info('partner.created: партнёр опознан как менеджер отдела продаж', [
                'login' => $email,
            ]);
        }

        return $isManager ? UserKind::STAFF : UserKind::CLIENT;
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
            Log::warning('partner.created: неизвестный client_status, статус не изменён', [
                'client_status' => $clientStatusCode,
                'uuid' => $payload['uuid'] ?? null,
            ]);

            return false;
        }

        return $clientStatusId;
    }
}
