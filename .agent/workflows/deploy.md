---
description: Правильный workflow для внесения изменений и деплоя на dev сервер через CI/CD
---

# Workflow разработки и деплоя

> **ВАЖНО:** Никогда не редактируй файлы напрямую на dev сервере. Все изменения вносятся локально, коммитятся и пушатся в ветку `dev`. CI/CD автоматически задеплоит.

## Порядок действий

### 1. Внеси изменения локально

Редактируй файлы в `/home/savosik/projects/pecado/`.

### 2. Закоммить и запушь в ветку `dev`

```bash
cd /home/savosik/projects/pecado
git add <changed-files>
git commit -m "feat/fix: описание изменений"
git push origin dev
```

### 3. CI/CD воронка (автоматически)

При пуше в `dev` запускается GitHub Actions workflow `.github/workflows/deploy-dev.yml`:

| Этап | Название | Что делает |
|---|---|---|
| **Job 1** | 🧪 Lint & Tests | PHP 8.3, MySQL, Redis, RabbitMQ. `composer install` → `migrate` → `php artisan test` |
| **Job 2** | ⚡ Build Frontend | Node.js 20, `npm ci` → `npm run build`. Артефакт `public/build` сохраняется. |
| **Job 3** | 🚀 Deploy to Dev | **Запускается только если Jobs 1+2 прошли.** Выполняется на self-hosted runner на dev сервере (`93.94.150.16`). |

### 4. Что делает Deploy (Job 3)

Деплой идёт в `/srv/pecado/` на dev сервере:
1. `rsync` кода (исключая `.env`, `vendor`, `storage/logs`, и т.д.)
2. `composer install --no-dev`
3. Очистка и прогрев кешей (`config:cache`, `route:cache`, `view:cache`)
4. `php artisan migrate --force`
5. `php artisan rabbitmq:setup` (топология RabbitMQ)
6. `php artisan queue:restart`
7. Перезапуск контейнеров `app` и `worker`
8. Health check: `curl http://localhost:8085/up` → ожидает HTTP 200

### 5. Проверка после деплоя

- Сайт: http://dev.pecado.ru
- Админка: http://dev.pecado.ru/admin (admin@pecado.ru / Admin2024!)
- RabbitMQ UI: http://93.94.150.16:15672

## Если нужно экстренно изменить что-то на сервере

В исключительных случаях (горячие фиксы для отладки) можно зайти по SSH и изменить файл в Docker-контейнере:

```bash
ssh ladmin@93.94.150.16
docker exec pecado-app sed -i 's/old/new/' /var/www/path/to/file.php
docker exec pecado-worker supervisorctl restart <worker-name>:*
```

> ⚠️ Эти изменения будут перезаписаны при следующем деплое! Обязательно продублируй изменение локально, закоммить и запушь.

## Перезапуск воркеров на сервере

```bash
ssh ladmin@93.94.150.16
# Supervisor доступен в контейнере pecado-worker
docker exec pecado-worker supervisorctl status
docker exec pecado-worker supervisorctl restart erp-orders-consumer:*
docker exec pecado-worker supervisorctl restart all
```
