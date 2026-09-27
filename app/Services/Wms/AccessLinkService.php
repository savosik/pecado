<?php

namespace App\Services\Wms;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\WmsAccessLink;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ссылки для кладовщиков (pick-17): «как в Google Docs» — начальник склада выпускает ссылку и кидает
 * её в мессенджер, кладовщик открывает и работает без логина и пароля.
 *
 * Ссылка привязана к своей учётной записи кладовщика (роль storekeeper), поэтому в журнале выдачи
 * видно, с какого телефона выдали. Перевыпуск меняет секрет и версию доступа: старая ссылка перестаёт
 * входить, а уже вошедшие по ней телефоны разлогиниваются на первом же запросе.
 */
class AccessLinkService
{
    /** Роль учётки ссылки: только экран выдачи (pick-17, «киоск»). */
    public const ROLE = 'pickup-operator';

    /** @return array{0: WmsAccessLink, 1: string} ссылка и её секрет */
    public function create(string $name, ?User $by): array
    {
        $name = mb_substr(trim($name), 0, 120) ?: 'Кладовщик';
        $token = $this->token();

        $link = DB::transaction(function () use ($name, $by, $token) {
            $user = User::forceCreate([
                'name' => 'Кладовщик · '.$name,
                // Адрес технический: по нему нельзя войти паролем и восстановить его тоже нельзя.
                'email' => 'wms-link-'.Str::lower(Str::random(12)).'@link.pecado.local',
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
                'status' => UserStatus::ACTIVE,
            ]);
            $user->assignRole(self::ROLE);

            return WmsAccessLink::create([
                'name' => $name,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => Crypt::encryptString($token),
                'created_by' => $by?->id,
            ]);
        });

        return [$link, $token];
    }

    /** Новый секрет, старые телефоны выходят. */
    public function regenerate(WmsAccessLink $link): string
    {
        $token = $this->token();

        // Версию двигаем в базе: у только что созданной модели атрибут ещё не прочитан (умолчание колонки).
        $link->refresh();
        $link->forceFill([
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => Crypt::encryptString($token),
            'session_version' => (int) $link->session_version + 1,
            'rotated_at' => now(),
            'revoked_at' => null,
        ])->save();

        // «Запомнить меня» у старых телефонов тоже перестаёт работать.
        $link->user?->setRememberToken(Str::random(60));
        $link->user?->save();

        return $token;
    }

    public function revoke(WmsAccessLink $link): void
    {
        $link->refresh();
        $link->forceFill(['revoked_at' => now(), 'session_version' => (int) $link->session_version + 1])->save();
        $link->user?->setRememberToken(Str::random(60));
        $link->user?->save();
    }

    public function findActiveByToken(string $token): ?WmsAccessLink
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return WmsAccessLink::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    public function url(WmsAccessLink $link): string
    {
        return route('wms.join', ['token' => Crypt::decryptString($link->token_encrypted)]);
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
