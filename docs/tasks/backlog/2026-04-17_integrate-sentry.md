# ЗАДАЧА: Интеграция Sentry (Трекинг ошибок Backend + Frontend)

## Техническое задание
Внедрить Sentry для автоматического сбора ошибок и мониторинга производительности на бэкенде (PHP) и фронтенде (React/Inertia).

---

### 1. Установка
Установите пакеты для обеих частей приложения:

**Backend:**
```bash
docker compose exec app composer require sentry/sentry-laravel
docker compose exec app php artisan sentry:publish --dsn=YOUR_DSN
```

**Frontend:**
```bash
docker compose exec node npm install --save @sentry/react
```

### 2. Настройка Backend (`bootstrap/app.php`)
Для Laravel 11/12 интеграция выполняется через обработчик исключений:

```php
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        // Регистрация обработчиков Sentry
        Integration::handlers($exceptions);

        // Ваши существующие кастомные обработчики остаются ниже
        $exceptions->respond(function ($response, $exception, $request) {
            // ...
        });
    })->create();
```
*Sentry автоматически перехватывает ошибки в очередях (Jobs), если пакет настроен.*

### 3. Настройка Frontend (`resources/js/app.jsx`)
Добавьте инициализацию Sentry в начало файла:

```javascript
import * as Sentry from "@sentry/react";

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN_PUBLIC,
  integrations: [
    Sentry.browserTracingIntegration(),
    Sentry.replayIntegration(),
  ],
  // Настройка трассировки
  tracesSampleRate: 1.0,
  replaysSessionSampleRate: 0.1,
  replaysOnErrorSampleRate: 1.0,
});

createInertiaApp({
    // ...
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <Provider>
                <App {...props} />
            </Provider>
        );
    },
});
```

### 4. Переменные окружения (`.env`)
Добавьте следующие ключи:
```env
SENTRY_LARAVEL_DSN=https://your-sentry-dns
SENTRY_TRACES_SAMPLE_RATE=1.0

# Для фронтенда
VITE_SENTRY_DSN_PUBLIC=${SENTRY_LARAVEL_DSN}
```
занеси все переменные в .env (на dev сервере и в .env.example)
---
**Метрика выполнения:** Ошибки бэкенда и фронтенда (включая ошибки Inertia-рендеринга) успешно отображаются в панели управления Sentry.
