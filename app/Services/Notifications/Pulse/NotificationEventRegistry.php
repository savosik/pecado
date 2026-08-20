<?php

namespace App\Services\Notifications\Pulse;

use App\Notifications\Pulse\Contracts\NotificationEventContract;
use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Реестр событий пульта — обёртка над config/notification_pulse.php.
 *
 * По образцу существующего SubscriptionRegistry: валидация ключа, метка,
 * признак включённости. Добавляет то, чего требует конструктор правил —
 * группировку для выпадающего списка и раскрытие маски события.
 */
class NotificationEventRegistry
{
    /** @var array<string, NotificationEventContract>|null */
    private ?array $events = null;

    /**
     * Все зарегистрированные события: ключ => объект описания.
     *
     * @return array<string, NotificationEventContract>
     */
    public function all(): array
    {
        if ($this->events !== null) {
            return $this->events;
        }

        $events = [];

        foreach ((array) config('notification_pulse.events', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $event = app($class);

            if ($event instanceof NotificationEventContract) {
                $events[$event->key()] = $event;
            }
        }

        return $this->events = $events;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key): ?NotificationEventContract
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Включён ли домен события. Выключенный домен не порождает сигналов вовсе.
     */
    public function isEnabled(string $key): bool
    {
        $event = $this->get($key);

        if ($event === null) {
            return false;
        }

        return (bool) config('notification_pulse.domains.'.$event->domain().'.enabled', false);
    }

    public function label(string $key): string
    {
        return $this->get($key)?->label() ?? $key;
    }

    /**
     * Ключи, по которым матчер ищет правила для этого события.
     *
     * Кроме точного ключа — маска домена и маска «все события». Благодаря им
     * правило «всё по этому контрагенту» подхватывает события, которых на момент
     * его создания не существовало.
     *
     * @return array<int, string>
     */
    public function matchKeys(string $key): array
    {
        $domain = explode('.', $key)[0];

        return array_values(array_unique([$key, $domain.'.*', '*']));
    }

    /**
     * Допустим ли такой event_key в правиле: точный ключ или маска.
     */
    public function isValidRuleKey(string $key): bool
    {
        if ($key === '*' || $this->exists($key)) {
            return true;
        }

        if (! str_ends_with($key, '.*')) {
            return false;
        }

        $domain = substr($key, 0, -2);

        return array_key_exists($domain, (array) config('notification_pulse.domains', []));
    }

    /**
     * Поля, доступные условиям правила с таким event_key.
     *
     * У маски полей конкретного события быть не может — остаются только общие.
     *
     * @return array<string, FieldSpec>
     */
    public function fieldsFor(string $key): array
    {
        $common = AbstractNotificationEvent::commonFields();
        $event = $this->get($key);

        return $event === null ? $common : array_merge($common, $event->fields());
    }

    /**
     * Каталог для выпадающего списка конструктора: события, сгруппированные
     * по смыслу, плюс маски доменов.
     *
     * @return array<int, array{group: string, items: array<int, array<string, mixed>>}>
     */
    public function groupedForConstructor(): array
    {
        $groups = [];

        foreach ($this->all() as $event) {
            if (! $this->isEnabled($event->key())) {
                continue;
            }

            $groups[$event->group()][] = [
                'value' => $event->key(),
                'label' => $event->label(),
                'description' => $event->description(),
            ];
        }

        // Маска домена идёт первой строкой группы: «любое событие этого раздела»
        // — частый выбор, когда правило заводят на контрагента целиком.
        $result = [];

        foreach ($groups as $group => $items) {
            $domain = $this->domainByGroup($group);

            if ($domain !== null) {
                array_unshift($items, [
                    'value' => $domain.'.*',
                    'label' => 'Любое событие раздела «'.$group.'»',
                    'description' => 'Сработает и на события, которые появятся позже',
                ]);
            }

            $result[] = ['group' => $group, 'items' => $items];
        }

        return $result;
    }

    /**
     * Ключи включённых событий.
     *
     * @return array<int, string>
     */
    public function enabledKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->all()),
            fn (string $key): bool => $this->isEnabled($key),
        ));
    }

    private function domainByGroup(string $group): ?string
    {
        foreach ((array) config('notification_pulse.domains', []) as $domain => $meta) {
            if (($meta['label'] ?? null) === $group) {
                return $domain;
            }
        }

        return null;
    }
}
