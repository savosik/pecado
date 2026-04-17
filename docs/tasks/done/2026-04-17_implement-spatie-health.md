# ЗАДАЧА: Внедрение Spatie Health (Кастомные проверки здоровья)

## Техническое задание
Установить пакет `spatie/laravel-health` и настроить регулярные проверки критических узлов системы (БД, Redis, диск, Horizon) с уведомлениями о сбоях.

---

### 1. Установка
```bash
docker compose exec app composer require spatie/laravel-health
docker compose exec app php artisan vendor:publish --module="laravel-health" --tag="health-migrations"
docker compose exec app php artisan migrate
```

### 2. Service Provider (`app/Providers/HealthServiceProvider.php`)
Зарегистрируйте проверки. Не забудьте добавить провайдер в `bootstrap/providers.php` (для Laravel 11/12) или в конфиг.

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Health\Facades\Health;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            // Проверка основной БД (mysql)
            DatabaseCheck::new()
                ->connectionName('mysql')
                ->name('Database: Main'),

            // Проверка БД цен (prices)
            DatabaseCheck::new()
                ->connectionName('prices')
                ->name('Database: Prices'),

            // Проверка Redis
            RedisCheck::new(),

            // Проверка свободного места (порог 90%)
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(90),

            // Проверка статуса Horizon
            HorizonCheck::new(),
        ]);
    }
}
```

### 3. Расписание проверок (`routes/console.php`)
Добавьте запуск проверок каждую минуту:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('health:check')->everyMinute();
```

### 4. Настройка уведомлений (`config/health.php`)
Для отправки уведомлений в Telegram/Slack обновите секцию `notifications`:

```php
'notifications' => [
    'notifications' => [
        \Spatie\Health\Notifications\Notifications\CheckFailedNotification::class => ['mail', 'slack', 'telegram'],
    ],

    'notifiers' => [
        'mail' => [
            'to' => env('HEALTH_EMAIL', 'admin@pecado.ru'),
        ],
        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK'),
        ],
        'telegram' => [
            'token' => env('HEALTH_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('HEALTH_TELEGRAM_CHAT_ID'),
        ],
    ],
],
```
занеси все переменные в .env (на dev сервере и в .env.example)

---
**Метрика выполнения:** Команда `php artisan health:list` показывает все проверки в статусе "OK". При падении любой из проверок приходит уведомление.
