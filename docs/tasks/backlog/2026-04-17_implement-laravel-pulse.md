# ЗАДАЧА: Внедрение Laravel Pulse (Мониторинг производительности)

## Техническое задание
Установить и настроить Laravel Pulse для мониторинга производительности приложения в реальном времени, включая отслеживание медленных запросов к обеим базам данных (`mysql` и `prices`).

---

### 1. Установка
Запустите установку и миграцию:
```bash
docker compose exec app composer require laravel/pulse
docker compose exec app php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
docker compose exec app php artisan migrate
```

### 2. Конфигурация медленных запросов (`config/pulse.php`)
Настройте рекордер `SlowQueries`, чтобы он отслеживал запросы во всех используемых БД:

```php
'recorders' => [
    \Laravel\Pulse\Recorders\SlowQueries::class => [
        'threshold' => 1000, // Порог в мс для "медленного" запроса
        'ignore' => [
            '/(^|\.)pulse_/', // Игнорировать таблицы самого Pulse
        ],
        // Мониторинг обеих баз данных (основная и цены)
        'connections' => ['mysql', 'prices'],
    ],
    // Остальные рекордеры...
],
```

### 3. Запуск Worker (Supervisor)
Для работы Pulse в режиме реального времени необходимо запустить воркер. В Docker рекомендуется использовать Supervisor (аналогично Horizon).

Добавьте в `docker/supervisor/supervisord.conf`:
```ini
[program:pulse-check]
process_name=%(program_name)s
command=php /var/www/html/artisan pulse:check
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/pulse.log
```
*Примечание: Если нагрузка небольшая, можно использовать `pulse:work` (но `pulse:check` предпочтительнее для постоянного мониторинга в фоне).*

### 4. Авторизация доступа (Gate)
Добавьте в `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;
use App\Models\User;

/**
 * Register any application services.
 */
public function boot(): void
{
    // ... после Horizon::auth ...

    Gate::define('viewPulse', function (User $user) {
        $adminEmails = explode(',', env('ADMIN_EMAILS', 'admin@pecado.ru'));
        
        return in_array($user->email, $adminEmails);
    });
}
```
занеси все переменные в .env (на dev сервере и в .env.example)
---
**Метрика выполнения:** Дашборд `/pulse` доступен администраторам, отображаются графики нагрузки и список медленных запросов для обеих БД.
