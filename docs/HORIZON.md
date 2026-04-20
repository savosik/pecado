# Laravel Horizon — мониторинг очередей Redis

## Что это и зачем

Laravel Horizon — дашборд для визуального мониторинга очередей на Redis. Показывает в реальном времени:

- сколько задач ожидает выполнения и сколько уже выполнено
- графики throughput и runtime по каждой очереди
- список упавших задач с полным стектрейсом
- количество воркеров и нагрузку на них

В проекте Horizon управляет **Redis-очередями** (`default` и `heavy`). RabbitMQ-очереди (ERP-интеграция) продолжают работать через отдельные Supervisor-воркеры и в Horizon не отображаются.

---

## Очереди

| Очередь | Назначение | Timeout | Memory |
|---------|-----------|---------|--------|
| `default` | Обычные фоновые задачи (bulk-delete и др.) | 60 сек | 128 МБ |
| `heavy` | Долгие задачи (генерация экспортов, синхронизации) | 3600 сек | 256 МБ |

Чтобы отправить задачу в очередь `heavy`:

```php
MyJob::dispatch()->onConnection('redis')->onQueue('heavy');
```

---

## Как войти

**URL:** http://dev.pecado.ru/horizon

Доступ только для пользователей с ролью `super-admin`:

| Email | Password |
|-------|----------|
| admin@pecado.ru | Admin2024! |
| savosik@pecado.ru | Savosik2024! |

---

## Как работает под капотом

Horizon запускается как отдельный Supervisor-процесс внутри Docker-контейнера:

```
docker/supervisor/conf.d/horizon.conf
```

Он сам управляет воркерами (запускает/останавливает процессы) согласно конфигу в `config/horizon.php`. Два супервизора:

- **supervisor-1** — обрабатывает очередь `default` (до 10 процессов в prod)
- **supervisor-heavy** — обрабатывает очередь `heavy` (до 5 процессов в prod)

Баланс процессов автоматический (`balance: auto`) — Horizon сам добавляет воркеры при росте очереди и убирает при простое.

Метрики снимаются каждые 5 минут через `horizon:snapshot` (расписание в `routes/console.php`), хранятся последние 24 снимка.

---

## Диагностика

```bash
# Статус Horizon
docker exec pecado-app php artisan horizon:status

# Посмотреть логи
docker exec pecado-app tail -f storage/logs/horizon.log

# Перезапустить (после изменений конфига)
docker exec pecado-app php artisan horizon:terminate
```

После `horizon:terminate` Supervisor автоматически поднимет новый процесс.
