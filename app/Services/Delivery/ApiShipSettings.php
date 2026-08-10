<?php

namespace App\Services\Delivery;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Настройки ApiShip, которые ведёт начальник склада через интерфейс.
 *
 * Приоритет: значение из таблицы `settings` перекрывает `.env`. Иначе смысла в
 * экранной форме нет — правка через интерфейс должна что-то менять, а лезть
 * в переменные окружения на проде складу нечем и незачем.
 *
 * Пустая строка в базе означает «не задано» и откатывает на `.env`: так стирание
 * поля в форме возвращает значение по умолчанию, а не ломает интеграцию.
 *
 * Токен, пароль и секрет вебхука лежат в базе зашифрованными: таблица `settings`
 * читается BI-агентом и попадает в дампы.
 */
class ApiShipSettings
{
    /** Группа записей в таблице `settings`. */
    public const GROUP = 'apiship';

    /**
     * Ключ настройки => путь в config. Порядок задаёт порядок полей в форме.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'enabled' => 'services.apiship.enabled',
        'base_url' => 'services.apiship.base_url',
        'token' => 'services.apiship.token',
        'login' => 'services.apiship.login',
        'password' => 'services.apiship.password',
        'timeout' => 'services.apiship.timeout',
        'webhook_enabled' => 'services.apiship.webhook.enabled',
        'webhook_secret' => 'services.apiship.webhook.secret',
        'sender_company_name' => 'services.apiship.sender.company_name',
        'sender_contact_name' => 'services.apiship.sender.contact_name',
        'sender_phone' => 'services.apiship.sender.phone',
        'sender_email' => 'services.apiship.sender.email',
        'sender_country_code' => 'services.apiship.sender.country_code',
        'sender_index' => 'services.apiship.sender.index',
        'sender_region' => 'services.apiship.sender.region',
        'sender_city' => 'services.apiship.sender.city',
        'sender_street' => 'services.apiship.sender.street',
        'sender_house' => 'services.apiship.sender.house',
        'default_item_weight_grams' => 'services.apiship.defaults.item_weight_grams',
        'default_place_length' => 'services.apiship.defaults.place_length',
        'default_place_width' => 'services.apiship.defaults.place_width',
        'default_place_height' => 'services.apiship.defaults.place_height',
    ];

    /** Значения, которые нельзя показывать и хранить открытым текстом. */
    public const SECRET_KEYS = ['token', 'password', 'webhook_secret'];

    /** @var list<string> */
    public const BOOLEAN_KEYS = ['enabled', 'webhook_enabled'];

    /** @var list<string> */
    public const INTEGER_KEYS = [
        'timeout',
        'default_item_weight_grams',
        'default_place_length',
        'default_place_width',
        'default_place_height',
    ];

    /** @var array<string, string>|null Кэш строк из БД на время запроса. */
    private ?array $stored = null;

    /**
     * Итоговое значение настройки: из базы, иначе из конфига.
     */
    public function get(string $key): mixed
    {
        $stored = $this->stored()[$key] ?? null;

        if ($stored !== null && $stored !== '') {
            return $this->cast($key, $stored);
        }

        return config(self::MAP[$key] ?? '');
    }

    public function string(string $key): string
    {
        return (string) ($this->get($key) ?? '');
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = (int) $this->get($key);

        return $value > 0 ? $value : $default;
    }

    /**
     * Все настройки для формы. Секреты не отдаются — только признак «задано».
     *
     * @return array<string, mixed>
     */
    public function forForm(): array
    {
        $values = [];

        foreach (array_keys(self::MAP) as $key) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                $values[$key] = '';
                $values[$key.'_is_set'] = $this->string($key) !== '';

                continue;
            }

            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Сохранить значения из формы.
     *
     * Секреты с пустым значением не затираются: форма их не показывает, и пустое
     * поле означает «не меняю», а не «сотри». Явное стирание — отдельным флагом.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (self::MAP as $key => $configPath) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $isSecret = in_array($key, self::SECRET_KEYS, true);

            if ($isSecret && ($value === null || $value === '')) {
                continue;
            }

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $value = $value ? '1' : '0';
            }

            $stored = $isSecret && $value !== null && $value !== ''
                ? Crypt::encryptString((string) $value)
                : (string) ($value ?? '');

            Setting::set($key, $stored, 'string', self::GROUP);
        }

        // Явное стирание секрета: чекбокс «удалить» в форме.
        foreach (self::SECRET_KEYS as $key) {
            if (! empty($values['clear_'.$key])) {
                Setting::set($key, '', 'string', self::GROUP);
            }
        }

        $this->stored = null;
    }

    /**
     * Настройки, которых не хватает для работы интеграции.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->string('base_url') === '') {
            $missing[] = 'адрес API';
        }

        if ($this->string('token') === '' && ($this->string('login') === '' || $this->string('password') === '')) {
            $missing[] = 'токен либо пара логин/пароль';
        }

        foreach (['sender_city' => 'город отправителя', 'sender_phone' => 'телефон отправителя'] as $key => $label) {
            if ($this->string($key) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Строки из базы. Читаются один раз за запрос — они не меняются на лету.
     *
     * @return array<string, string>
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        // Именно getRawOriginal: аксессор Setting::value кастует значение по полю
        // `type`, а нам нужна сырая строка — секреты здесь ещё зашифрованы.
        return $this->stored = Setting::query()
            ->where('group', self::GROUP)
            ->get(['key', 'value', 'type'])
            ->mapWithKeys(static fn (Setting $setting): array => [
                $setting->key => (string) $setting->getRawOriginal('value'),
            ])
            ->all();
    }

    private function cast(string $key, string $value): mixed
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable) {
                // Значение записали до включения шифрования либо сменился APP_KEY.
                // Роняться нельзя: интеграция просто откатится на .env.
                Log::warning('ApiShip: не удалось расшифровать настройку', ['key' => $key]);

                return '';
            }
        }

        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (in_array($key, self::INTEGER_KEYS, true)) {
            return (int) $value;
        }

        return $value;
    }
}
