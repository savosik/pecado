# CI/CD: Автодеплой ветки `dev` на Dev-сервер

> **Стек:** Laravel 11 · PHP 8.3-FPM · Vite/Node 20 · MySQL 8 · Redis · RabbitMQ 3 · MeiliSearch · MinIO · Supervisor · Docker Compose
> **Платформа CI:** GitHub Actions (рекомендуется, т.к. проект уже на Git)

---

## 1. Концепция и архитектура пайплайна

```
git push origin dev
       │
       ▼
┌──────────────────────────────────────────────┐
│           GitHub Actions Runner              │
│  ┌─────────┐  ┌──────────┐  ┌────────────┐  │
│  │  Lint & │  │  Tests   │  │   Build    │  │
│  │  Static │  │ (PHPUnit)│  │  (Vite)   │  │
│  └─────────┘  └──────────┘  └────────────┘  │
└──────────────────────┬───────────────────────┘
                       │ SSH Deploy (rsync + docker compose)
                       ▼
              ┌─────────────────┐
              │   Dev Server    │
              │  Docker Compose │
              │  (все сервисы)  │
              └─────────────────┘
```

**Принцип работы:**
1. Push в ветку `dev` → запускается GitHub Actions workflow
2. Выполняются lint и тесты (быстрая обратная связь)
3. Код доставляется на сервер через SSH + rsync (без пересборки образов если Dockerfile не менялся)
4. На сервере выполняется многоуровневая очистка кешей: Laravel cache/config/route/view, OPcache, compiled classes
5. Выполняются `artisan migrate`, `artisan queue:restart`, пересборка фронтенда
6. Сервисы перезапускаются через `docker compose restart` (FPM-рестарт гарантированно сбрасывает OPcache)

---

## 2. Требования к Dev-серверу

### 2.1 Минимальные характеристики
| Параметр | Значение |
|----------|----------|
| CPU | 2 vCPU |
| RAM | 4 GB |
| Disk | 40 GB SSD |
| OS | Ubuntu 22.04 LTS |
| Открытые порты | 22 (SSH), 80/443 (HTTP/S), 8085 (app), 15672 (RabbitMQ mgmt) |

### 2.2 Установка зависимостей на сервере
```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# Docker Compose plugin (v2)
sudo apt-get install docker-compose-plugin

# Git
sudo apt-get install git
```

### 2.3 Первоначальная настройка проекта на сервере
```bash
# Клонируем репозиторий
git clone -b dev git@github.com:ORG/pecado.git /srv/pecado

# Создаём .env для dev (из примера)
cp /srv/pecado/.env.example /srv/pecado/.env.dev
# Редактируем .env.dev: APP_ENV=dev, APP_DEBUG=true, DB, Redis, RabbitMQ, MinIO и т.д.
# Создаём симлинк
ln -sf /srv/pecado/.env.dev /srv/pecado/.env

# Строим образы первый раз
cd /srv/pecado
docker compose build
docker compose up -d

# Первичная инициализация
docker compose exec app composer install --no-interaction
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
docker compose exec node npm install
docker compose exec node npm run build
```

---

## 3. Структура GitHub Actions Workflow

### Файл: `.github/workflows/deploy-dev.yml`

```yaml
name: Deploy to Dev

on:
  push:
    branches:
      - dev

concurrency:
  group: deploy-dev
  cancel-in-progress: true   # Отменяем предыдущий деплой при новом пуше

jobs:
  # ─────────────────────────────────────────────
  # JOB 1: Статический анализ и тесты
  # ─────────────────────────────────────────────
  test:
    name: Lint & Tests
    runs-on: ubuntu-latest
    timeout-minutes: 15

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: pecado_test
          MYSQL_ROOT_PASSWORD: secret
          MYSQL_USER: pecado
          MYSQL_PASSWORD: secret
        ports: ["3306:3306"]
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

      redis:
        image: redis:alpine
        ports: ["6379:6379"]

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo_mysql, mbstring, bcmath, gd, zip, redis, amqp, pcntl, sockets
          coverage: none

      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}

      - name: Install PHP deps
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Prepare .env for tests
        run: |
          cp .env.example .env
          sed -i 's|DB_CONNECTION=sqlite|DB_CONNECTION=mysql|' .env
          echo "DB_HOST=127.0.0.1" >> .env
          echo "DB_PORT=3306" >> .env
          echo "DB_DATABASE=pecado_test" >> .env
          echo "DB_USERNAME=pecado" >> .env
          echo "DB_PASSWORD=secret" >> .env
          echo "REDIS_HOST=127.0.0.1" >> .env
          echo "QUEUE_CONNECTION=sync" >> .env
          php artisan key:generate

      - name: Run migrations
        run: php artisan migrate --force

      - name: Run PHPUnit
        run: php artisan test --parallel

  # ─────────────────────────────────────────────
  # JOB 2: Деплой на Dev-сервер
  # ─────────────────────────────────────────────
  deploy:
    name: Deploy
    runs-on: ubuntu-latest
    needs: test           # Деплоим только после прохождения тестов
    timeout-minutes: 20
    environment: dev      # GitHub Environment с защитой (опционально)

    steps:
      - name: Checkout
        uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Setup SSH
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.DEV_SSH_PRIVATE_KEY }}

      - name: Add server to known_hosts
        run: |
          ssh-keyscan -H ${{ secrets.DEV_SERVER_HOST }} >> ~/.ssh/known_hosts

      - name: Sync code to server (rsync)
        run: |
          rsync -az --delete \
            --exclude='.git' \
            --exclude='.env' \
            --exclude='vendor' \
            --exclude='node_modules' \
            --exclude='public/build' \
            --exclude='storage/logs' \
            --exclude='storage/framework/cache' \
            --exclude='storage/framework/sessions' \
            --exclude='storage/framework/views' \
            ./ ${{ secrets.DEV_SSH_USER }}@${{ secrets.DEV_SERVER_HOST }}:/srv/pecado/

      - name: Deploy on server
        env:
          HOST: ${{ secrets.DEV_SERVER_HOST }}
          USER: ${{ secrets.DEV_SSH_USER }}
        run: |
          ssh $USER@$HOST 'bash -s' << 'ENDSSH'
            set -e
            cd /srv/pecado

            echo "==> Проверка Dockerfile изменений..."
            DOCKERFILE_CHANGED=$(git diff HEAD~1 HEAD --name-only 2>/dev/null | grep -c "docker/" || true)

            echo "==> Устанавливаем зависимости PHP..."
            docker compose exec -T app composer install \
              --no-interaction --prefer-dist --optimize-autoloader --no-dev

            echo "==> Кэшируем конфиги Laravel..."
            docker compose exec -T app php artisan config:cache
            docker compose exec -T app php artisan route:cache
            docker compose exec -T app php artisan view:cache

            echo "==> Выполняем миграции..."
            docker compose exec -T app php artisan migrate --force

            echo "==> Индексация MeiliSearch..."
            docker compose exec -T app php artisan scout:sync-index-settings

            echo "==> Перезапускаем очереди..."
            docker compose exec -T app php artisan queue:restart

            echo "==> Сборка фронтенда..."
            docker compose exec -T node npm install --silent
            docker compose exec -T node npm run build

            if [ "$DOCKERFILE_CHANGED" -gt "0" ]; then
              echo "==> Dockerfile изменился, пересобираем образы..."
              docker compose build app worker
              docker compose up -d --no-deps app worker
            else
              echo "==> Перезапускаем app/worker контейнеры..."
              docker compose restart app worker
            fi

            echo "==> Очищаем устаревшие образы..."
            docker image prune -f

            echo "==> Деплой завершён успешно!"
          ENDSSH

      - name: Health Check
        run: |
          sleep 10
          curl -f http://${{ secrets.DEV_SERVER_HOST }}:8085/up || exit 1
          echo "Health check passed!"

      - name: Notify on failure
        if: failure()
        run: |
          echo "::error::Деплой на dev завершился с ошибкой!"
          # Здесь можно добавить уведомление в Telegram/Slack
```

---

## 4. GitHub Secrets (настройки репозитория)

Перейти: **Settings → Secrets and variables → Actions → New repository secret**

| Secret | Описание | Пример |
|--------|----------|--------|
| `DEV_SSH_PRIVATE_KEY` | Приватный SSH-ключ для подключения к серверу | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
| `DEV_SERVER_HOST` | IP или домен dev-сервера | `192.168.1.100` или `dev.pecado.ru` |
| `DEV_SSH_USER` | Пользователь на сервере | `deploy` |

### Генерация SSH-ключа для деплоя
```bash
# Генерируем ключ на локальной машине (без passphrase!)
ssh-keygen -t ed25519 -C "github-deploy-dev" -f ~/.ssh/pecado_deploy -N ""

# Публичный ключ добавляем на сервер
ssh-copy-id -i ~/.ssh/pecado_deploy.pub deploy@DEV_SERVER_HOST
# или вручную:
# cat ~/.ssh/pecado_deploy.pub >> ~/.ssh/authorized_keys (на сервере)

# Приватный ключ (~/.ssh/pecado_deploy) добавляем в GitHub Secrets как DEV_SSH_PRIVATE_KEY
cat ~/.ssh/pecado_deploy
```

---

## 5. Конфигурация `.env` на dev-сервере

Файл хранится на сервере в `/srv/pecado/.env` и **не попадает в Git** (gitignore). Пример:

```dotenv
APP_NAME="Pecado Dev"
APP_ENV=dev
APP_KEY=base64:...   # php artisan key:generate
APP_DEBUG=true
APP_URL=http://DEV_SERVER_HOST:8085

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=pecado
DB_USERNAME=pecado
DB_PASSWORD=secret

CACHE_STORE=redis
REDIS_HOST=redis
REDIS_PORT=6379

QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest

MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=masterKey123

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=sail
AWS_SECRET_ACCESS_KEY=password
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=pecado
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025

VITE_APP_NAME="Pecado Dev"
```

---

## 6. Порядок первоначального деплоя (Cheat Sheet)

```
Шаг 1: Подготовка сервера
  └─ Установить Docker + Docker Compose
  └─ Создать пользователя deploy
  └─ Добавить SSH-ключ для деплоя

Шаг 2: Настройка репозитория
  └─ Добавить GitHub Secrets (SSH_KEY, HOST, USER)
  └─ Создать .github/workflows/deploy-dev.yml
  └─ Создать environment 'dev' в Settings → Environments

Шаг 3: Первый запуск на сервере (вручную)
  └─ git clone -b dev ...
  └─ cp .env.example .env && nano .env
  └─ docker compose up -d
  └─ docker compose exec app php artisan key:generate
  └─ docker compose exec app php artisan migrate --force
  └─ docker compose exec app php artisan storage:link
  └─ docker compose exec node npm run build  (или запустить node контейнер)

Шаг 4: Проверка
  └─ curl http://SERVER:8085/up → должен вернуть 200
  └─ Открыть http://SERVER:8085 в браузере

Шаг 5: Последующие деплои
  └─ git push origin dev → GitHub Actions запускается автоматически
```

---

## 7. Мониторинг и логи

### Просмотр логов на сервере
```bash
# Логи приложения
docker compose logs -f app

# Логи worker (очереди)
docker compose logs -f worker

# Логи nginx
docker compose logs -f nginx

# Laravel логи
docker compose exec app tail -f storage/logs/laravel.log

# Supervisor статус (очереди)
docker compose exec worker supervisorctl status
```

### Доступные сервисы после деплоя
| Сервис | URL |
|--------|-----|
| Приложение | `http://SERVER:8085` |
| RabbitMQ Management | `http://SERVER:15672` |
| MailPit (почта) | `http://SERVER:8025` |
| MinIO Console | `http://SERVER:9001` |
| MeiliSearch | `http://SERVER:7701` |

---

## 7.1 Стратегия очистки кешей при деплое

> **Ключевая проблема:** OPcache в PHP-FPM живёт в памяти воркер-процессов. `php artisan` запускается через CLI — это **отдельный процесс**, который не имеет доступа к FPM-кешу. Без явного сброса OPcache PHP продолжает выполнять **старый байт-код** даже после обновления файлов.

### Уровни кешей и способы сброса

| Уровень кеша | Хранится в | Команда сброса | Сброс при рестарте FPM |
|---|---|---|---|
| **Laravel app cache** | Redis / файлы | `php artisan cache:clear` | ❌ нет |
| **Laravel config cache** | `bootstrap/cache/config.php` | `php artisan config:clear` | ✅ да |
| **Laravel route cache** | `bootstrap/cache/routes*.php` | `php artisan route:clear` | ✅ да |
| **Laravel view cache** | `storage/framework/views/` | `php artisan view:clear` | ✅ да |
| **Compiled classes** | `bootstrap/cache/` | `php artisan clear-compiled` | ✅ да |
| **OPcache** | Память FPM процессов | Web-endpoint `opcache_reset()` | ✅ ДА |

### Порядок операций в деплое (критичен!)

```
1. composer install        ← новые классы появляются на диске
2. cache:clear             ← чистим Redis/file кеш приложения
3. config/route/view:clear ← чистим старые кешированные файлы Laravel
4. clear-compiled          ← удаляем скомпилированные классы
5. OPcache reset           ← сбрасываем байт-код старых файлов в памяти FPM
   (через временный web-файл public/opcache-reset-*.php)
6. migrate                 ← применяем новые миграции
7. config/route/view:cache ← прогреваем кеши с новым кодом
8. queue:restart           ← воркеры подхватят новый код
9. docker compose restart  ← FPM перезапускается → OPcache сбрасывается ПОЛНОСТЬЮ
   (страховочный шаг, гарантирует чистоту даже если web-reset не сработал)
```

### Почему рестарт FPM — это финальная страховка

При `docker compose restart app` PHP-FPM (контейнер `app`) полностью перезапускается. Все форкнутые воркер-процессы уничтожаются, вместе с ними и весь OPcache. Новые процессы стартуют с чистым кешем и компилируют актуальный код.

```bash
# Проверить текущее состояние OPcache вручную
curl http://SERVER:8085/opcache-status.php   # только если файл существует

# Принудительно сбросить OPcache вручную
docker compose restart app
```

---

## 8. Стратегия ветвления и деплоя

```
main ────────────────────────────── production (будущий)
  │
  └── dev ──────────────────────── dev-server (текущий)
        │
        └── feature/* ──────────── только разработка (не деплоится)
```

| Ветка | Сервер | Триггер |
|-------|--------|---------|
| `dev` | Dev-сервер | Push в `dev` |
| `main` | Prod-сервер | Push в `main` (будущая реализация) |

---

## 9. Оптимизации и улучшения (дорожная карта)

- [ ] **Zero-downtime deploy**: использовать `php artisan down` → деплой → `php artisan up` или Envoyer-подобный rolling deploy
- [ ] **Telegram/Slack уведомления**: добавить шаг в workflow для нотификаций об успехе/неудаче
- [ ] **Docker Registry**: публиковать образы в GitHub Container Registry (GHCR) для ускорения деплоя
- [ ] **Rollback**: хранить последние N версий кода для быстрого отката (`git reset --hard HEAD~1`)
- [ ] **Prod окружение**: аналогичный workflow для ветки `main` с обязательным approve через GitHub Environments
- [ ] **Кэш Docker layers**: использовать `cache-from` в `docker build` для ускорения сборки образов
- [ ] **Health checks**: расширить проверку — RabbitMQ, Redis, MeiliSearch доступность

---

## 10. Troubleshooting

| Проблема | Решение |
|----------|---------|
| `Permission denied (publickey)` в Actions | Проверить `DEV_SSH_PRIVATE_KEY` secret, добавить публичный ключ в `authorized_keys` на сервере |
| Тесты падают в CI, но проходят локально | Проверить что в workflow правильно настроены переменные окружения (DB_HOST, QUEUE_CONNECTION=sync) |
| Контейнер `app` не стартует после деплоя | `docker compose logs app` — чаще всего `.env` не настроен или `APP_KEY` пустой |
| `rsync` не синхронизирует файлы | Проверить права пользователя `deploy` на директорию `/srv/pecado` |
| Фронтенд не собирается | `docker compose exec node npm run build` выполнить вручную, проверить логи node-контейнера |
| Очереди не обрабатываются | `docker compose exec worker supervisorctl status` — все воркеры должны быть в статусе `RUNNING` |
| **Старый код после деплоя** | OPcache не сброшен → `docker compose restart app` вручную |
| **500 ошибка после деплоя** | Скорее всего проблема с кешем конфигов → `docker compose exec app php artisan config:clear` |
