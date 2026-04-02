<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DataNormalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Фоновая нормализация данных пользователя (ФИО, телефон, email) через AI.
 *
 * Диспатчится из HandlePartnerCreated после сохранения сырых данных.
 * При ошибке AI — данные остаются как есть, Job просто ретраится.
 *
 * v2: идемпотентный — повторный прогон не накапливает мусор.
 */
class NormalizeUserDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $userId,
        public bool $dryRun = false,
    ) {
        $this->queue = 'normalization';
    }

    public function handle(DataNormalizerService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            Log::warning('NormalizeUserDataJob: пользователь не найден', ['user_id' => $this->userId]);

            return;
        }

        $result = $service->normalizeUser(
            $user->surname,
            $user->name,
            $user->patronymic,
            $user->phone,
            $user->email,
        );

        if (! $result) {
            Log::info('NormalizeUserDataJob: AI не вернул результат, пропускаем', ['user_id' => $this->userId]);

            return;
        }

        $updates = [];
        $commentParts = [];

        // ФИО
        if (isset($result['type'])) {
            if ($result['type'] === 'organization') {
                // Используем org_name как есть — AI возвращает полное название
                $orgName = $result['org_name'] ?? $user->name;
                $updates['name'] = $orgName ?: $user->name; // name NOT NULL
                $updates['surname'] = null;
                $updates['patronymic'] = null;
            } else {
                // Персона
                if (isset($result['surname'])) {
                    $updates['surname'] = $result['surname'];
                }
                if (isset($result['name'])) {
                    $updates['name'] = $result['name'];
                }
                if (array_key_exists('patronymic', $result)) {
                    $updates['patronymic'] = $result['patronymic'];
                }
                if (! empty($result['org_type'])) {
                    $commentParts[] = "Форма собственности: {$result['org_type']}";
                }
            }
        }

        // Город
        if (! empty($result['city'])) {
            if (empty($user->city)) {
                $updates['city'] = $result['city'];
            } elseif ($user->city !== $result['city']) {
                $commentParts[] = "Город из 1С: {$result['city']}";
            }
        }

        // Телефон — с валидацией
        if (isset($result['primary_phone'])) {
            $validatedPhone = DataNormalizerService::validatePhone($result['primary_phone']);
            if ($validatedPhone && $validatedPhone !== $user->phone) {
                $updates['phone'] = $validatedPhone;
            }
        }

        // Email — не обнуляем (нужен для логина)
        if (array_key_exists('email', $result) && $result['email'] !== $user->email) {
            if ($result['email'] === null && $user->email) {
                $commentParts[] = "Некорректный email: {$user->email}";
            }
        }

        // Extra info
        if (! empty($result['extra_info'])) {
            $commentParts[] = $result['extra_info'];
        }

        // Собираем comment — ИДЕМПОТЕНТНО (затираем, не дописываем)
        if (! empty($commentParts)) {
            $updates['comment'] = implode('; ', $commentParts);
        }

        // Фильтруем — не обновляем если ничего не изменилось
        $updates = array_filter($updates, function ($value, $key) use ($user) {
            return $user->{$key} !== $value;
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($updates)) {
            Log::info('NormalizeUserDataJob: данные уже чистые', ['user_id' => $this->userId]);

            return;
        }

        if ($this->dryRun) {
            Log::info('NormalizeUserDataJob [DRY-RUN]', [
                'user_id' => $this->userId,
                'before'  => [
                    'name'       => $user->name,
                    'surname'    => $user->surname,
                    'patronymic' => $user->patronymic,
                    'phone'      => $user->phone,
                    'city'       => $user->city,
                ],
                'after' => $updates,
            ]);

            return;
        }

        User::withoutEvents(function () use ($user, $updates) {
            $user->update($updates);
        });

        Log::info('NormalizeUserDataJob: данные нормализованы', [
            'user_id' => $this->userId,
            'updates' => array_keys($updates),
        ]);
    }
}
