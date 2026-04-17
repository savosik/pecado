# ЗАДАЧА: Настройка Laravel Horizon (Мониторинг очередей)

## Техническое задание
Необходимо установить и настроить Laravel Horizon для визуального мониторинга очередей Redis. Требуется разделение на пулы для изоляции долгих задач от быстрых.

---

### 1. Установка
Запустите установку пакета через Composer:
```bash
docker compose exec app composer require laravel/horizon
docker compose exec app php artisan horizon:install
```

### 2. Оптимальная конфигурация (`config/horizon.php`)
Настройте пулы `default` и `heavy`. Пул `heavy` предназначен для длительных процессов (например, синхронизация цен или остатков).

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'strategy' => 'auto',
        ],
    ],

    'local' => [
        'supervisor-1' => [
            'maxProcesses' => 3,
        ],
    ],
],

'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['default', 'heavy'], // Очереди для обработки
        'balance' => 'auto',
        'maxProcesses' => 10,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 128,
        'tries' => 3,
        'timeout' => 60,
        'nice' => 0,
    ],
],
```
*Примечание: Пул `heavy` в коде можно использовать так: `DispatchJob::dispatch()->onQueue('heavy');`*

### 3. Конфигурация Supervisord
Добавьте следующий блок в `docker/supervisor/supervisord.conf`:

```ini
[program:horizon]
process_name=%(program_name)s
command=php /var/www/html/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/horizon.log
stopwaitsecs=3600
```

### 4. Защита роута (Gate)
Добавьте в `app/Providers/AppServiceProvider.php`:

```php
use Laravel\Horizon\Horizon;
use App\Models\User;

/**
 * Register any application services.
 */
public function boot(): void
{
    Horizon::auth(function ($request) {
        $adminEmails = explode(',', env('ADMIN_EMAILS', 'admin@pecado.ru'));
        
        return app()->environment('local') || 
               ($request->user() && in_array($request->user()->email, $adminEmails));
    });
}
```

---
**Метрика выполнения:** Роут `/horizon` доступен только администраторам, очереди успешно обрабатываются через Horizon.
