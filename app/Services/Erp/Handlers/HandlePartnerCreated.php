<?php

namespace App\Services\Erp\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Region;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;

/**
 * US-01 v4: Обработка события partner.created из 1С.
 *
 * Два сценария:
 * 1. Пользователь найден по email (login) → обновляет erp_id и статус ACTIVE
 * 2. Пользователь не найден + есть password → создаёт нового пользователя
 *
 * Все операции через User::withoutEvents() для предотвращения петли:
 * partner.created → UserUpdated → PublishUserToErp → partner.created → LOOP
 */
class HandlePartnerCreated
{
    public function handle(array $payload): void
    {
        $uuid     = $payload['uuid']     ?? null;
        $login    = $payload['login']    ?? null;
        $email    = $payload['email']    ?? $login;
        $name     = $payload['first_name'] ?? $payload['name'] ?? null;
        $surname  = $payload['last_name'] ?? null;
        $patronymic = $payload['middle_name'] ?? null;
        
        $phone    = $payload['phone']    ?? null;
        $password = $payload['password'] ?? null;
        
        $city     = $payload['city'] ?? null;
        $country  = $payload['country'] ?? null;
        
        $regionName = $payload['region'] ?? null;
        $currencyCode = $payload['currency'] ?? null;
        
        $regionId = null;
        if ($regionName) {
            $regionId = Region::where('name', $regionName)->value('id');
        }
        
        $currencyId = null;
        if ($currencyCode) {
            $currencyId = Currency::where('code', $currencyCode)->value('id');
        }

        if (!$uuid || !$login) {
            Log::warning('partner.created: отсутствует uuid или login', ['payload' => $payload]);

            return;
        }

        $user = User::where('email', $login)->first();

        if ($user) {
            // Сценарий 1: Пользователь существует — активация
            User::withoutEvents(function () use ($user, $uuid) {
                $user->update([
                    'erp_id' => $uuid,
                    'status' => UserStatus::ACTIVE,
                ]);
            });

            Log::info('partner.created: пользователь активирован', [
                'user_id' => $user->id,
                'login'   => $login,
                'erp_id'  => $uuid,
            ]);

            return;
        }

        // Сценарий 2: Создание нового пользователя из 1С (v4)
        if (!$password) {
            Log::warning('partner.created: пользователь не найден и нет пароля для создания', [
                'login' => $login,
                'uuid'  => $uuid,
            ]);

            return;
        }

        $newUser = User::withoutEvents(function () use ($uuid, $login, $email, $name, $surname, $patronymic, $city, $country, $regionId, $currencyId, $phone, $password) {
            return User::create([
                'name'                 => $name ?? $login,
                'surname'              => $surname,
                'patronymic'           => $patronymic,
                'city'                 => $city,
                'country'              => $country,
                'region_id'            => $regionId,
                'currency_id'          => $currencyId,
                'email'                => $email,
                'phone'                => $phone,
                'password'             => $password, // auto-hashed через cast 'hashed'
                'must_change_password' => true,
                'erp_id'               => $uuid,
                'status'               => UserStatus::ACTIVE,
            ]);
        });

        Log::info('partner.created: новый пользователь создан из 1С', [
            'user_id'              => $newUser->id,
            'login'                => $login,
            'erp_id'               => $uuid,
            'must_change_password' => true,
        ]);
    }
}
