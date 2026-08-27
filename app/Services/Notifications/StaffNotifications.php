<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Что получает сотрудник.
 *
 * Тот же принцип, что и у партнёра: строка существует, только когда настройка
 * отличается от умолчания. Разница в том, что адресат здесь один и всегда
 * известен — сам сотрудник, — поэтому настраивается лишь «получать или нет».
 */
class StaffNotifications
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return (array) config('staff_notifications', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function enabledByDefault(string $key): bool
    {
        return (bool) ($this->all()[$key]['default_enabled'] ?? false);
    }

    /**
     * Получает ли сотрудник этот тип писем.
     *
     * Единственный вопрос, который задают доменные места отправки. Незнакомый
     * ключ разрешаем: забытая строка в конфиге не должна тихо гасить письма.
     */
    public function wants(?User $staff, string $key): bool
    {
        if ($staff === null) {
            return false;
        }

        if (! $this->exists($key)) {
            return true;
        }

        $row = NotificationPreference::query()
            ->where('user_id', $staff->getKey())
            ->where('occasion_key', $key)
            ->first();

        return $row === null
            ? $this->enabledByDefault($key)
            : (bool) $row->is_enabled;
    }

    /**
     * Сотрудник по адресу — чтобы места отправки, знающие только email,
     * могли спросить про настройку.
     */
    public function wantsByEmail(?string $email, string $key): bool
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        $staff = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        // Адрес без учётки — общий ящик отдела. Настраивать нечего, шлём.
        return $staff === null ? true : $this->wants($staff, $key);
    }

    /**
     * Строки для экрана.
     *
     * @return array<string, mixed>
     */
    public function matrixFor(User $staff): array
    {
        $rows = [];

        foreach ($this->all() as $key => $meta) {
            $row = NotificationPreference::query()
                ->where('user_id', $staff->getKey())
                ->where('occasion_key', $key)
                ->first();

            $rows[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'family_label' => (string) ($meta['family'] ?? 'Прочее'),
                'hint' => (string) ($meta['hint'] ?? ''),
                'enabled' => $row === null ? $this->enabledByDefault($key) : (bool) $row->is_enabled,
                'overridden' => $row !== null,
            ];
        }

        return [
            'rows' => $rows,
            'staff' => ['id' => (int) $staff->getKey(), 'name' => $staff->name],
        ];
    }

    /**
     * Сохранить. Совпадение с умолчанием удаляет строку — тогда сотрудник
     * снова следует за конфигом, а не за его старой копией.
     */
    public function save(User $staff, string $key, bool $enabled, ?User $actor): void
    {
        if ($enabled === $this->enabledByDefault($key)) {
            NotificationPreference::query()
                ->where('user_id', $staff->getKey())
                ->where('occasion_key', $key)
                ->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $staff->getKey(), 'occasion_key' => $key],
            [
                'is_enabled' => $enabled,
                'destinations' => null,
                'options' => null,
                'changed_by_client' => false,
                'updated_by_user_id' => $actor?->getKey(),
            ],
        );
    }
}
