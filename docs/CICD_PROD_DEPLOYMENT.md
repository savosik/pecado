# CI/CD: Деплой ветки `main` на Production-сервер

> **Статус:** ✅ **Production LIVE** с 2026-05-09. Сайт работает на https://pecado.ru, CI/CD активен (push в main → manual approve в Environment `production` → автодеплой). Self-hosted runner `prod-server` (systemd, uid=1000). Branch Protection для main включена.
> Prod (`m-s-web`, локальный `10.2.2.101`) занимает стандартные порты `22/80/443/15672` на общем внешнем IP `93.94.150.16`. Dev (`m-s-site`, локальный `10.2.2.100`) живёт на том же IP через временный проброс альтернативных портов `8022/8080/8443/25672`.
> **Стек:** Laravel 12 · PHP 8.3-FPM · Vite/Node 20 · MySQL 8 (×2: main + prices) · Redis · RabbitMQ 3 · MeiliSearch · MinIO · Supervisor · Docker Compose
> **Связанные документы:** [PROD_WORKFLOW.md](./PROD_WORKFLOW.md) · [PROD_SERVER_CREDENTIALS.md](./PROD_SERVER_CREDENTIALS.md) · [DEV_SERVER_CREDENTIALS.md](./DEV_SERVER_CREDENTIALS.md) (приостановлен) · [CICD_DEV_DEPLOYMENT.md](./CICD_DEV_DEPLOYMENT.md)
> **Актуализирован:** 2026-05-09 — синхронизирован с реальным [.github/workflows/deploy-dev.yml](../.github/workflows/deploy-dev.yml)

---

## 1. Архитектура пайплайна (целевая)

```
                     ┌── feature/* (опционально, разработка)
                     │
   dev ──────────────┤── GitHub Actions ──→ Dev Server m-s-site (10.2.2.100, порты 8022/8080/8443/25672)
                     │     Tests + Deploy (auto, fast-lane)
                     │
                     │  PR: dev → main
                     │
   main ─── PR merge ┤── GitHub Actions ──→ Prod Server m-s-web (10.2.2.101, порты 22/80/443/15672)
                     │     Tests + Approval + Deploy
                     └
```

### Ключевые отличия dev vs prod Pipeline

| Аспект | Dev | Prod |
|---|---|---|
| Триггер | Push в `dev` | Push в `main` (только через PR) |
| Approval | Нет | **Обязательный** (GitHub Environment protection) |
| Fast-lane (`[fast]` пропуск тестов) | Да | **Нет** — тесты прогоняются всегда |
| `APP_DEBUG` | `true` | **`false`** |
| `APP_ENV` | `dev` | **`production`** |
| `LOG_LEVEL` | `debug` | **`warning`** |
| Composer | `--no-dev --optimize-autoloader` | `--no-dev --optimize-autoloader --classmap-authoritative` |
| Maintenance mode | Нет | **Да** (`artisan down` во время деплоя) |
| `cancel-in-progress` | `true` | **`false`** (прод-деплой не отменяется на лету) |
| Health check | `http://localhost:8085/up` | `https://pecado.ru/up` |
| Rollback | Не нужен | **Обязательно** (revert + auto-redeploy) |
| Бэкапы БД | Нет | **Ежедневно** (cron) + перед деплоем, offsite-копия в Yandex Object Storage |

---

## 2. Где размещать Prod-сервер

### Рекомендация: Отдельный VPS/VDS

> Полная изоляция от dev, собственный IP, SSL, независимый от проблем dev-сервера.

**Рекомендуемые провайдеры (РФ):**

| Провайдер | Тариф (примерно) | Плюсы |
|---|---|---|
| **Timeweb Cloud** | ~1500₽/мес (4 vCPU, 8 GB RAM, 80 GB SSD) | Русский хостинг, DDoS защита, хорошая сеть |
| **Selectel** | ~2000₽/мес | Enterprise-grade, отличная сеть в РФ |
| **Hetzner** | ~€15/мес (CPX31: 4 vCPU, 8 GB) | Лучшее соотношение цена/качество, EU |
| **reg.ru VPS** | ~1500₽/мес | Русский провайдер, простой интерфейс |

**Рекомендуемые характеристики prod-сервера:**

| Параметр | Минимум | Рекомендуется |
|---|---|---|
| CPU | 2 vCPU | **4 vCPU** |
| RAM | 4 GB | **8 GB** |
| Disk | 40 GB SSD | **80 GB SSD** (NVMe) |
| OS | Ubuntu 22.04 LTS | **Ubuntu 24.04 LTS** |
| Сеть | 100 Mbps | 1 Gbps |
| Бэкапы | — | **Ежедневные** (провайдер или скрипт) |

> **Почему 8 GB RAM минимум:** на проде крутится **два MySQL-контейнера** (`mysql` + `mysql-prices`), Meilisearch с векторным индексом, RabbitMQ, Redis, PHP-FPM с OPcache, Supervisor с пулом воркеров (`erp_in.prices` и `erp_in.catalog` по 6 потоков каждый). На 4 GB RAM есть риск OOM при пиковой нагрузке.

---

## 3. Что нужно изменить для Prod

### 3.1 Создать `docker-compose.prod.yml` (override файл)

Prod использует тот же базовый `docker-compose.yml`, но с переопределениями:

```yaml
# docker-compose.prod.yml — override для production
services:
  app:
    restart: always
    environment:
      - PHP_OPCACHE_ENABLE=1
      - PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
      - PHP_OPCACHE_MEMORY_CONSUMPTION=256
      - PHP_OPCACHE_MAX_ACCELERATED_FILES=20000

  nginx:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/prod.conf:/etc/nginx/conf.d/default.conf
      - /etc/letsencrypt:/etc/letsencrypt:ro
      - /var/www/certbot:/var/www/certbot

  worker:
    restart: always

  mysql:
    ports: []   # ⚠️ Не экспонировать наружу!
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}

  mysql-prices:
    ports: []   # ⚠️ Отдельная БД для индивидуальных цен — закрыть наружу
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PRICES_ROOT_PASSWORD}
      MYSQL_USER: ${DB_PRICES_USERNAME}
      MYSQL_PASSWORD: ${DB_PRICES_PASSWORD}

  redis:
    ports: []   # ⚠️ Не экспонировать наружу!
    command: redis-server --requirepass ${REDIS_PASSWORD}

  rabbitmq:
    ports:
      - "5672:5672"                       # AMQP — нужен 1С
      - "127.0.0.1:15672:15672"           # Management только с localhost
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_MANAGEMENT_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_MANAGEMENT_PASSWORD}

  meilisearch:
    ports: []   # ⚠️ Не экспонировать наружу!
    environment:
      MEILI_MASTER_KEY: ${MEILISEARCH_KEY}
      MEILI_NO_ANALYTICS: "true"
      MEILI_ENV: production

  minio:
    ports:
      - "127.0.0.1:9001:9001"   # Console только с localhost
    environment:
      MINIO_ROOT_USER: ${MINIO_ACCESS_KEY}
      MINIO_ROOT_PASSWORD: ${MINIO_SECRET_KEY}

  mailpit:
    profiles: ["dev"]   # На prod отключен — реальный SMTP в .env

  node:
    profiles: ["dev"]   # На prod не нужен — фронт собирается в CI
```

**Запуск на prod:** `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`

> ⚠️ Важно: 1С подключается к RabbitMQ напрямую через AMQP (порт 5672). Если 1С на другом сервере — порт 5672 должен быть открыт **только** для IP сервера 1С (через UFW: `ufw allow from <ip-1c> to any port 5672`).

---

### 3.2 Nginx конфигурация для Prod (SSL + безопасность)

Файл: `docker/nginx/prod.conf`

```nginx
server {
    listen 80;
    server_name pecado.ru www.pecado.ru;

    # ACME challenge для Let's Encrypt
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    # Редирект HTTP → HTTPS
    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name pecado.ru www.pecado.ru;

    # SSL
    ssl_certificate /etc/letsencrypt/live/pecado.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pecado.ru/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Заголовки безопасности
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' wss:;" always;

    # Основные настройки
    root /var/www/public;
    index index.php;
    client_max_body_size 100M;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript image/svg+xml;

    # Логи
    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    # PHP-FPM
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_buffering off;
        fastcgi_read_timeout 60;
    }

    # Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }

    # Статика с долгим кешем
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        log_not_found off;
    }

    # Запрет доступа к скрытым файлам
    location ~ /\. {
        deny all;
    }

    # Запрет прямого доступа к .env, composer и т.д.
    location ~* (\.env|composer\.(json|lock)|package(-lock)?\.json|webpack\.mix\.js|artisan) {
        deny all;
    }
}
```

---

### 3.3 `.env` для Production (шаблон)

```dotenv
APP_NAME="Pecado"
APP_ENV=production
APP_KEY=base64:XXXXXXXX     # php artisan key:generate (уникальный!)
APP_DEBUG=false              # ⚠️ НИКОГДА true на prod!
APP_URL=https://pecado.ru
APP_TIMEZONE=Europe/Moscow

# ─── Логирование ───
LOG_CHANNEL=stack
LOG_LEVEL=warning            # Не debug! Только warning+

# ─── База данных (основная) ───
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=pecado
DB_USERNAME=pecado_prod
DB_PASSWORD=<STRONG_PASSWORD_32+_CHARS>
DB_ROOT_PASSWORD=<STRONG_ROOT_PASSWORD>

# ─── Individual Prices Database (отдельный контейнер) ───
DB_PRICES_HOST=mysql-prices
DB_PRICES_PORT=3306
DB_PRICES_DATABASE=pecado_prices
DB_PRICES_USERNAME=pecado_prod
DB_PRICES_PASSWORD=<STRONG_PRICES_PASSWORD>
DB_PRICES_ROOT_PASSWORD=<STRONG_PRICES_ROOT_PASSWORD>

# ─── Кеширование ───
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<STRONG_REDIS_PASSWORD>

# ─── Очереди (RabbitMQ) ───
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672

# Системные пользователи RabbitMQ (создаются автоматически в deploy job)
RABBITMQ_MANAGEMENT_USER=admin
RABBITMQ_MANAGEMENT_PASSWORD=<STRONG_ADMIN_PASSWORD>
RABBITMQ_USER=pecado_app
RABBITMQ_PASSWORD=<STRONG_APP_PASSWORD>

# Пользователь для 1С (читает из erp_out.*, пишет в erp_in.*)
RABBITMQ_ERP_USER=pecado_1c
RABBITMQ_ERP_PASSWORD=<STRONG_ERP_PASSWORD>

# Логирование шины ERP (опционально)
ERP_BUS_LOGGING_ENABLED=true

# ─── Shovel-ы с внешних ESB ───
# Заказы из чужой 1С через ESB Andrey Company (plain AMQP на порту 45671, vhost `/`).
# Реальные креды — в docs/ANDREY_ESB_CONNECTION.md (файл в .gitignore).
# ВАЖНО: `%2F` в конце URI — это URL-кодированный `/` (default vhost).
# Хвост просто `/` означал бы ПУСТОЙ vhost и приводил к `access to target virtual host was refused`.
ANDREY_ESB_AMQP_URI=amqp://pecado:<ANDREY_ESB_PASSWORD>@esb.services.andrey.company:45671/%2F
ANDREY_ESB_SRC_QUEUE=pecado.orders
ANDREY_ESB_SHOVEL_PREFETCH=100
ANDREY_ESB_SHOVEL_RECONNECT_DELAY=5

# ─── Поиск (Meilisearch) ───
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=<STRONG_MEILI_KEY_32+_CHARS>

# ─── Файлы (MinIO / S3) ───
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<MINIO_PROD_ACCESS_KEY>
AWS_SECRET_ACCESS_KEY=<MINIO_PROD_SECRET_KEY>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=pecado
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
MINIO_ACCESS_KEY=<MINIO_PROD_ACCESS_KEY>
MINIO_SECRET_KEY=<MINIO_PROD_SECRET_KEY>

# ─── Почта (реальный SMTP на prod!) ───
MAIL_MAILER=smtp
MAIL_HOST=smtp.yandex.ru
MAIL_PORT=465
MAIL_USERNAME=noreply@pecado.ru
MAIL_PASSWORD=<SMTP_PASSWORD>
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@pecado.ru
MAIL_FROM_NAME="Pecado"

# ─── Публичный API канбан ───
KANBAN_API_ENABLED=false     # Включать только если используется

# ─── Vite ───
VITE_APP_NAME="Pecado"
```

> ⚠️ Все пароли в prod `.env` должны быть **уникальные, длинные (32+ символов), случайно сгенерированные!**
> Используйте: `openssl rand -base64 32`

---

### 3.4 Чеклист безопасности

| # | Изменение | Dev | Prod |
|---|---|---|---|
| 1 | `APP_DEBUG` | `true` | **`false`** |
| 2 | `LOG_LEVEL` | `debug` | **`warning`** |
| 3 | Порты MySQL (3308) | Открыты | **Закрыты** |
| 4 | Порты MySQL-prices (3309) | Открыты | **Закрыты** |
| 5 | Порты Redis (6381) | Открыты | **Закрыты** + пароль |
| 6 | Порты RabbitMQ AMQP (5672) | Открыты | **Только для 1С** (UFW по IP) |
| 7 | Порты RabbitMQ mgmt (15672) | Открыты | **Только localhost** |
| 8 | Порты MeiliSearch (7701) | Открыты | **Закрыты** |
| 9 | Порты MinIO console (9001) | Открыты | **Только localhost** |
| 10 | Пользователь RabbitMQ `guest` | Есть | **Удалён** (deploy job) |
| 11 | Пароли | Простые (`secret`, `guest`) | **Сильные** (32+ chars) |
| 12 | SSL/HTTPS | Нет | **Обязательно** (Let's Encrypt) |
| 13 | Security headers | Нет | **Да** (HSTS, CSP, X-Frame-Options) |
| 14 | Firewall (UFW) | Нет | **Да** (только 22, 80, 443, 5672 по IP) |
| 15 | Fail2ban | Нет | **Да** |
| 16 | SSH root login | — | **Запрещён** |
| 17 | Бэкапы БД | Нет | **Ежедневно** |
| 18 | Mailpit | Да | **Убран** (profiles: ["dev"]) |
| 19 | Telescope | Включен | **Отключен** (dont-discover в composer) |
| 20 | Scramble API docs | Публично | **Защитить gate-ом или выключить** |

---

## 4. GitHub Actions Workflow для Prod

### Файл: `.github/workflows/deploy-prod.yml`

```yaml
name: Deploy to Production

on:
  push:
    branches:
      - main

concurrency:
  group: deploy-prod
  cancel-in-progress: false  # ⚠️ НЕ отменяем — prod деплой должен завершиться!

jobs:
  # ─────────────────────────────────────────
  # JOB 1: Тесты (без fast-lane! на проде гоняем всегда)
  # ─────────────────────────────────────────
  test:
    name: "🧪 Lint & Tests"
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
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
          --health-start-period=40s

      mysql-prices:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: pecado_prices_test
          MYSQL_ROOT_PASSWORD: secret
          MYSQL_USER: pecado
          MYSQL_PASSWORD: secret
        ports: ["3307:3306"]
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
          --health-start-period=40s

      redis:
        image: redis:alpine
        ports: ["6379:6379"]
        options: >-
          --health-cmd="redis-cli ping"
          --health-interval=5s
          --health-timeout=3s
          --health-retries=3

      rabbitmq:
        image: rabbitmq:3.13-alpine
        env:
          RABBITMQ_DEFAULT_USER: guest
          RABBITMQ_DEFAULT_PASS: guest
        ports: ["5672:5672"]
        options: >-
          --health-cmd="rabbitmq-diagnostics ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5

    steps:
      - name: "📥 Checkout"
        uses: actions/checkout@v4

      - name: "🐘 Setup PHP 8.3"
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo_mysql, mbstring, bcmath, gd, zip, redis, pcntl, sockets
          coverage: none

      - name: "📦 Cache Composer dependencies"
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
          restore-keys: |
            ${{ runner.os }}-composer-

      - name: "📦 Install PHP dependencies"
        run: composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

      - name: "⚙️ Prepare .env for tests"
        run: |
          cp .env.example .env
          sed -i 's|DB_CONNECTION=sqlite|DB_CONNECTION=mysql|' .env
          cat >> .env << 'EOF'
          DB_HOST=127.0.0.1
          DB_PORT=3306
          DB_DATABASE=pecado_test
          DB_USERNAME=pecado
          DB_PASSWORD=secret
          DB_PRICES_HOST=127.0.0.1
          DB_PRICES_PORT=3307
          DB_PRICES_DATABASE=pecado_prices_test
          DB_PRICES_USERNAME=pecado
          DB_PRICES_PASSWORD=secret
          REDIS_HOST=127.0.0.1
          REDIS_PORT=6379
          QUEUE_CONNECTION=sync
          CACHE_STORE=array
          MAIL_MAILER=log
          SCOUT_DRIVER=null
          RABBITMQ_HOST=127.0.0.1
          RABBITMQ_PORT=5672
          RABBITMQ_USER=guest
          RABBITMQ_PASSWORD=guest
          EOF
          mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions
          mkdir -p bootstrap/cache
          php artisan key:generate
          php artisan package:discover --ansi

          mkdir -p public/build
          echo '{"resources/css/app.css":{"file":"assets/app.css","src":"resources/css/app.css"},"resources/js/app.jsx":{"file":"assets/app.js","src":"resources/js/app.jsx","isEntry":true}}' > public/build/manifest.json

      - name: "🗃️ Run migrations"
        run: php artisan migrate --force

      - name: "✅ Run tests"
        run: php artisan test

  # ─────────────────────────────────────────
  # JOB 2: Сборка фронтенда + документации
  # ─────────────────────────────────────────
  build-frontend:
    name: "⚡ Build Frontend"
    runs-on: ubuntu-latest
    timeout-minutes: 10

    steps:
      - name: "📥 Checkout"
        uses: actions/checkout@v4

      - name: "🟢 Setup Node.js"
        uses: actions/setup-node@v4
        with:
          node-version: "20"
          cache: "npm"

      - name: "📦 Install Node dependencies"
        run: npm ci

      - name: "🏗️ Build assets"
        run: npm run build

      - name: "📄 Build AsyncAPI docs"
        run: npm run asyncapi:build

      - name: "🐍 Setup Python"
        uses: actions/setup-python@v5
        with:
          python-version: '3.12'

      - name: "📚 Build MkDocs (ERP guide)"
        run: |
          pip install mkdocs-material
          mkdocs build

      - name: "📤 Upload build artifacts"
        uses: actions/upload-artifact@v4
        with:
          name: frontend-build
          path: public/build
          retention-days: 7

      - name: "📤 Upload AsyncAPI docs"
        uses: actions/upload-artifact@v4
        with:
          name: asyncapi-docs
          path: |
            docs/asyncapi/html
            docs/asyncapi/pecado-erp-bundled.yaml
          retention-days: 7

      - name: "📤 Upload MkDocs site"
        uses: actions/upload-artifact@v4
        with:
          name: mkdocs-site
          path: docs-erp/site
          retention-days: 7

  # ─────────────────────────────────────────
  # JOB 3: Деплой на Prod-сервер
  # ─────────────────────────────────────────
  deploy:
    name: "🚀 Deploy to Production"
    runs-on: [self-hosted, prod-server]
    needs: [test, build-frontend]
    timeout-minutes: 25
    environment:
      name: production         # ⬅️ Требует ручного approval в GitHub!
      url: https://pecado.ru

    steps:
      - name: "📥 Checkout"
        uses: actions/checkout@v4

      - name: "📥 Download frontend build"
        uses: actions/download-artifact@v4
        with:
          name: frontend-build
          path: public/build

      - name: "📥 Download AsyncAPI docs"
        uses: actions/download-artifact@v4
        with:
          name: asyncapi-docs
          path: docs/asyncapi

      - name: "📥 Download MkDocs site"
        uses: actions/download-artifact@v4
        with:
          name: mkdocs-site
          path: docs-erp/site

      - name: "🔧 Fix Docker file ownership"
        run: |
          CURRENT_UID=$(id -u)
          CURRENT_GID=$(id -g)
          sg docker -c "docker run --rm -v /srv/pecado/public/build:/build alpine chown -R ${CURRENT_UID}:${CURRENT_GID} /build" 2>/dev/null || true

      - name: "💾 Backup БД (snapshot перед деплоем)"
        run: |
          set -euo pipefail
          # Хранилище — на отдельном диске /dev/sdb (/media), чтобы не забивать системный sda2.
          BACKUP_DIR="/media/backups/mysql/pre-deploy"
          mkdir -p "$BACKUP_DIR"
          DATE=$(date +%Y-%m-%d_%H%M%S)

          # Основная БД
          sg docker -c "docker compose -f /srv/pecado/docker-compose.yml -f /srv/pecado/docker-compose.prod.yml exec -T mysql \
            sh -c 'exec mysqldump -uroot -p\$MYSQL_ROOT_PASSWORD pecado'" \
            | gzip > "$BACKUP_DIR/pecado_${DATE}.sql.gz"

          # Prices БД
          sg docker -c "docker compose -f /srv/pecado/docker-compose.yml -f /srv/pecado/docker-compose.prod.yml exec -T mysql-prices \
            sh -c 'exec mysqldump -uroot -p\$MYSQL_ROOT_PASSWORD pecado_prices'" \
            | gzip > "$BACKUP_DIR/pecado_prices_${DATE}.sql.gz"

          # Retention основной БД: оставляем 10 последних
          ls -1t "$BACKUP_DIR"/pecado_*.sql.gz 2>/dev/null | grep -v '/pecado_prices_' | tail -n +11 | xargs -r rm -fv

          # Retention БД цен: оставляем 5 последних — архивы большие, могут забить диск
          ls -1t "$BACKUP_DIR"/pecado_prices_*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -fv

      - name: "📤 Sync code"
        run: |
          rsync -a --delete \
            --exclude='.git' \
            --exclude='.env' \
            --exclude='vendor' \
            --exclude='node_modules' \
            --exclude='bootstrap/cache' \
            --exclude='public/hot' \
            --exclude='storage/logs' \
            --exclude='storage/framework/cache' \
            --exclude='storage/framework/sessions' \
            --exclude='storage/framework/views' \
            --exclude='storage/app/exports' \
            --exclude='storage/app/public' \
            ./ /srv/pecado/

      - name: "🐳 Deploy"
        run: |
          set -euo pipefail
          cd /srv/pecado
          DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

          echo "==> [0/9] Maintenance mode ON..."
          sg docker -c "$DC exec -T app php artisan down --retry=30 --refresh=5" || true

          echo "==> [1/9] Установка PHP зависимостей..."
          sg docker -c "$DC exec -T app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative"

          echo "==> [1.5/9] Создание symlink public/storage (если ещё нет)..."
          sg docker -c "$DC exec -T app php artisan storage:link" || true

          echo "==> [2/9] Очистка и прогрев кешей Laravel..."
          sg docker -c "$DC exec -T app php artisan optimize:clear"
          sg docker -c "$DC exec -T app php artisan config:cache"
          sg docker -c "$DC exec -T app php artisan route:cache"
          sg docker -c "$DC exec -T app php artisan view:cache"
          sg docker -c "$DC exec -T app php artisan event:cache"
          sg docker -c "$DC exec -T app php artisan optimize"

          echo "==> [3/9] Запуск и ожидание prices DB..."
          sg docker -c "$DC up -d mysql-prices"
          for i in $(seq 1 30); do
            STATUS=$(sg docker -c "docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' pecado-mysql-prices" 2>/dev/null || echo "missing")
            [ "$STATUS" = "healthy" ] && break
            [ "$i" -eq 30 ] && { echo "❌ mysql-prices не перешёл в healthy: $STATUS"; sg docker -c 'docker logs --tail 100 pecado-mysql-prices' || true; exit 1; }
            sleep 2
          done

          echo "==> [4/9] Миграции (main DB + prices DB)..."
          sg docker -c "$DC exec -T app php artisan migrate --force"

          echo "==> [5/9] Синхронизация ролей и прав..."
          sg docker -c "$DC exec -T app php artisan db:seed --class=RolesAndPermissionsSeeder --force"

          echo "==> [6/9] RabbitMQ users + topology..."
          sg docker -c "$DC up -d rabbitmq"
          for i in $(seq 1 30); do
            sg docker -c "$DC exec -T rabbitmq rabbitmq-diagnostics ping" >/dev/null 2>&1 && break
            [ "$i" -eq 30 ] && { echo "❌ RabbitMQ не поднялся"; exit 1; }
            sleep 2
          done

          # Provisioning пользователей RabbitMQ
          set -a; source /srv/pecado/.env; set +a
          ensure_user() {
            local user="$1" pass="$2" tag="$3" conf="$4" write="$5" read="$6"
            if sg docker -c "$DC exec -T rabbitmq rabbitmqctl list_users" 2>/dev/null | awk '{print $1}' | grep -Fxq "$user"; then
              sg docker -c "$DC exec -T rabbitmq rabbitmqctl change_password '$user' '$pass'"
            else
              sg docker -c "$DC exec -T rabbitmq rabbitmqctl add_user '$user' '$pass'"
            fi
            [ -n "$tag" ] && sg docker -c "$DC exec -T rabbitmq rabbitmqctl set_user_tags '$user' $tag"
            sg docker -c "$DC exec -T rabbitmq rabbitmqctl set_permissions -p / '$user' '$conf' '$write' '$read'"
          }
          ensure_user "${RABBITMQ_MANAGEMENT_USER}" "${RABBITMQ_MANAGEMENT_PASSWORD}" "administrator" ".*" ".*" ".*"
          ensure_user "${RABBITMQ_USER}"            "${RABBITMQ_PASSWORD}"            ""               ".*" ".*" ".*"
          if [ -n "${RABBITMQ_ERP_USER:-}" ] && [ -n "${RABBITMQ_ERP_PASSWORD:-}" ]; then
            ensure_user "${RABBITMQ_ERP_USER}"      "${RABBITMQ_ERP_PASSWORD}"        "" 'erp\..*|erp_out\..*|external\..*' 'erp\..*' 'erp_out\..*|external\..*'
          fi
          sg docker -c "$DC exec -T rabbitmq rabbitmqctl delete_user guest" 2>/dev/null || true

          # Топология (exchanges, queues, shovels)
          sg docker -c "$DC exec -T app php artisan rabbitmq:setup" || true

          echo "==> [7/9] Meilisearch settings + synonyms..."
          sg docker -c "$DC exec -T app php artisan scout:sync-index-settings" || true
          sg docker -c "$DC exec -T app php artisan meilisearch:sync-synonyms" || true

          echo "==> [8/9] Перезапуск очередей и контейнеров..."
          sg docker -c "$DC exec -T app php artisan queue:restart"
          sg docker -c "$DC restart app worker nginx"

          echo "==> [9/9] Maintenance mode OFF..."
          sg docker -c "$DC exec -T app php artisan up"

          echo ""
          echo "✅ Production деплой завершён!"

      - name: "🏥 Health Check"
        run: |
          sleep 10
          HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" https://pecado.ru/up)
          if [ "$HTTP_CODE" = "200" ]; then
            echo "✅ Health check passed (HTTP $HTTP_CODE)"
          else
            echo "❌ Health check FAILED (HTTP $HTTP_CODE)"
            # Поднимаем сайт обратно из maintenance, если он там завис
            sg docker -c "docker compose -f /srv/pecado/docker-compose.yml -f /srv/pecado/docker-compose.prod.yml exec -T app php artisan up" || true
            exit 1
          fi

      - name: "📢 Notify on success"
        if: success()
        run: |
          echo "✅ Prod deploy successful!"
          echo "   Commit: ${{ github.sha }}"
          echo "   Author: ${{ github.actor }}"
          # TODO: Telegram/Slack notification

      - name: "🔔 Notify on failure"
        if: failure()
        run: |
          echo "❌ Prod deploy FAILED!"
          echo "   Commit: ${{ github.sha }}"
          echo "   Author: ${{ github.actor }}"
          # TODO: Telegram/Slack notification — на проде это критично!
```

---

## 5. Настройка GitHub

### 5.1 Environment `production`

**Settings → Environments → New environment → `production`**

- ✅ **Required reviewers** — добавить себя (и ещё 1-2 ответственных)
- ✅ **Wait timer** — 0 минут (или 5 мин для "cooling off")
- ✅ **Deployment branches** — только `main`

### 5.2 Branch protection для `main`

**Settings → Branches → Add rule** (паттерн `main`):

- ✅ **Require a pull request before merging**
  - ✅ Require approvals: 1 (для соло-разработки можно 0, но включите всё равно)
  - ✅ Dismiss stale pull request approvals when new commits are pushed
- ✅ **Require status checks to pass before merging**
  - ✅ Required: `🧪 Lint & Tests`, `⚡ Build Frontend`
- ✅ **Require conversation resolution before merging**
- ✅ **Do not allow bypassing the above settings** (для продакшена обязательно)
- ❌ **Allow force pushes** — выключить
- ❌ **Allow deletions** — выключить

### 5.3 GitHub Secrets для Prod

При использовании self-hosted runner на prod-сервере SSH-секреты не нужны. Достаточно зарегистрированного runner с лейблом `prod-server`.

Если же используется ssh-deploy (без self-hosted runner):

| Secret | Описание |
|---|---|
| `PROD_SSH_PRIVATE_KEY` | SSH-ключ для prod сервера |
| `PROD_SERVER_HOST` | IP или домен prod-сервера |
| `PROD_SSH_USER` | Пользователь `deploy` |

---

## 6. Подготовка Prod-сервера — пошагово

### Шаг 1: Настройка ОС

```bash
# Обновление
sudo apt update && sudo apt upgrade -y

# Создать пользователя deploy
sudo adduser deploy
sudo usermod -aG sudo deploy

# Запретить root SSH
sudo sed -i 's/^PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
sudo sed -i 's/^#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart sshd

# Firewall (UFW)
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp     # SSH
sudo ufw allow 80/tcp     # HTTP
sudo ufw allow 443/tcp    # HTTPS
# RabbitMQ AMQP — только для IP сервера 1С
# sudo ufw allow from <ip-1c> to any port 5672
sudo ufw enable

# Fail2ban
sudo apt install fail2ban -y
sudo systemctl enable fail2ban
```

### Шаг 2: Docker + Self-Hosted Runner

```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker deploy

# Перелогиниться, чтобы группа docker применилась
exit
ssh deploy@PROD_SERVER

# GitHub Actions Runner
cd /home/deploy
mkdir actions-runner && cd actions-runner

# Скачать актуальную версию: https://github.com/actions/runner/releases
RUNNER_VERSION="2.321.0"
curl -o actions-runner-linux-x64.tar.gz -L \
  https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz
tar xzf ./actions-runner-linux-x64.tar.gz

# Регистрация (токен взять в Settings → Actions → Runners → New self-hosted runner)
./config.sh --url https://github.com/savosik/pecado --token <TOKEN> --labels prod-server

# Установка как systemd-сервис
sudo ./svc.sh install deploy
sudo ./svc.sh start
sudo ./svc.sh status
```

### Шаг 3: SSL (Let's Encrypt)

```bash
sudo apt install certbot -y

# DNS должен указывать на prod IP до этого шага!
sudo certbot certonly --standalone -d pecado.ru -d www.pecado.ru

# Автопродление уже настроено через systemd timer
sudo systemctl status certbot.timer

# Hook для перезагрузки nginx после продления:
sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/bash
cd /srv/pecado && sg docker -c "docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T nginx nginx -s reload"
EOF
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

### Шаг 4: Первый запуск проекта

```bash
su - deploy
sudo mkdir -p /srv/pecado /media/backups/mysql
sudo chown -R deploy:deploy /srv/pecado /media/backups
cd /srv

git clone -b main git@github.com:savosik/pecado.git pecado
cd pecado

# Создать .env для production (по шаблону из п. 3.3)
cp .env.example .env
# Сгенерировать пароли:
openssl rand -base64 32   # повторить для каждого пароля
nano .env

# Создать docker-compose.prod.yml и docker/nginx/prod.conf по шаблонам

# Запуск
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Инициализация (первый раз — вручную)
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose exec app php artisan storage:link
docker compose exec app php artisan rabbitmq:setup
docker compose exec app php artisan scout:sync-index-settings
docker compose exec app php artisan optimize

# Создать первого админа
docker compose exec app php artisan tinker
# >>> User::factory()->create(['email' => 'admin@pecado.ru', 'password' => bcrypt('...')])->assignRole('admin');

# Health check
curl -k https://pecado.ru/up
```

### Шаг 5: Бэкапы базы данных

> **Статус:** ✅ настроено и работает. Ежедневный cron + offsite-копия в Yandex Object Storage (с 2026-07-16).

Бэкап делается в два места, потому что копия на том же сервере не спасает от потери самого сервера:

| Куда | Что | Retention |
|---|---|---|
| Локально `/media/backups/mysql/daily/` | обе БД, ежедневно в 03:00 | основная — 30 дней, цены — 5 архивов |
| Локально `/media/backups/mysql/pre-deploy/` | обе БД, перед каждым деплоем ([deploy-prod.yml](../.github/workflows/deploy-prod.yml)) | основная — 10, цены — 5 |
| **Облако** `yandex:pecado-backup/daily/` | обе БД, ежедневно | 30 дней |
| **Облако** `yandex:pecado-backup/monthly/` | обе БД, 1-го числа | 365 дней |

Offsite-копия льётся через `rclone` (бинарник `/srv/scripts/bin/rclone`, конфиг `~ladmin/.config/rclone/rclone.conf`, режим `600`). Бакет создан с классом **ICE**, объекты наследуют его автоматически — `storage_class` в конфиге указывать не нужно. Креды — в [PROD_SERVER_CREDENTIALS.md](./PROD_SERVER_CREDENTIALS.md) §17.

Скрипт `/srv/scripts/backup-db.sh` (снимает дампы → проверяет `gzip -t` → отгружает в S3 → чистит по retention). Живёт вне `/srv/pecado`, поэтому `rsync --delete` при деплое его не трогает — но и CI его туда **не доставляет**. Эталонная копия для ревью — [scripts/prod-backup-db.sh](../scripts/prod-backup-db.sh), правки нужно копировать на сервер руками (инструкция в шапке скрипта). Ключевые решения:

- **`gzip -t` перед отгрузкой** — битый архив в облаке хуже, чем его отсутствие: даёт ложное чувство защиты.
- **Сбой S3 не роняет локальный бэкап** — ошибка логируется, скрипт завершается кодом 1 (виден в логе), но дампы на диске остаются.
- **Retention в облаке чистится только при успешной загрузке** — иначе можно срезать историю, не получив взамен свежую копию.
- **30 дней = минимальный срок хранения класса ICE.** Удалять раньше бессмысленно: оплата всё равно списывается за 30 суток.
- **Pre-deploy бэкапы в облако не льются** — их по несколько в день, они нужны для быстрого отката на живом сервере; катастрофу закрывает daily.

```bash
# Cron: ежедневно в 3:00 от пользователя ladmin (отдельного deploy-пользователя не делали)
crontab -l -u ladmin
0 3 * * * /srv/scripts/backup-db.sh >> /var/log/pecado-backup.log 2>&1
```

Проверка, что всё живо:

```bash
tail -5 /var/log/pecado-backup.log                          # последний прогон — "Backup OK"
/srv/scripts/bin/rclone ls yandex:pecado-backup             # свежие архивы в облаке
/srv/scripts/bin/rclone size yandex:pecado-backup           # объём
```

---

## 7. Workflow разработки (итоговый)

```
1. Локально: разработчик кодит в feature/* (или сразу в dev)
2. Push в dev → CI: тесты (или fast-lane по [fast]) + сборка → автодеплой на DEV
3. Тестирование на dev (через временные порты 8080/8443, см. [DEV_SERVER_CREDENTIALS.md](./DEV_SERVER_CREDENTIALS.md))
4. Создать PR: dev → main
5. Code review → approve PR → merge
6. CI: тесты (без fast-lane!) + сборка → ожидание approval в Environment 'production'
7. Approve в GitHub → деплой на PROD с maintenance mode
8. Health check → если упал → revert PR в main → новый деплой = откат
```

Подробнее: [PROD_WORKFLOW.md](./PROD_WORKFLOW.md).

---

## 8. Откат (Rollback) на проде

### Способ 1: Revert через GitHub (рекомендуется)

```bash
# Локально
git checkout main
git pull origin main

# Откатить последний релизный коммит (merge commit)
git revert -m 1 HEAD
git push origin main
# → запускается deploy-prod.yml → откат до предыдущей версии
```

### Способ 2: Восстановление БД из бэкапа

Если миграция испортила данные:

```bash
ssh deploy@PROD_SERVER
cd /srv/pecado

# Включить maintenance
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan down

# Восстановить из бэкапа
gunzip < /media/backups/mysql/pre-deploy/pecado_<DATE>.sql.gz | \
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" pecado'

# Поднять обратно
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan up
```

Если локальные бэкапы недоступны (потеря диска `/dev/sdb`, переезд на новый сервер) — тянем из облака. Класс ICE отдаёт данные сразу, процедуры разморозки как у AWS Glacier здесь нет:

```bash
R=/srv/scripts/bin/rclone

$R ls yandex:pecado-backup/daily/                    # выбрать нужную дату
$R copy yandex:pecado-backup/daily/pecado_<DATE>.sql.gz /tmp/restore/
gzip -t /tmp/restore/pecado_<DATE>.sql.gz            # убедиться, что архив целый

gunzip < /tmp/restore/pecado_<DATE>.sql.gz | \
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" pecado'
```

> На чистом сервере rclone ставится одним бинарником, без root:
> `curl -sSfL https://downloads.rclone.org/rclone-current-linux-amd64.zip -o /tmp/r.zip` → распаковать (`unzip` в базовой Ubuntu 24.04 нет, можно `python3 -m zipfile -e /tmp/r.zip /tmp/r/`) → положить в `/srv/scripts/bin/rclone` → воссоздать конфиг из §17 credentials.

### Способ 3: Чрезвычайный — git checkout прошлой версии

```bash
ssh deploy@PROD_SERVER
cd /srv/pecado
git fetch origin
git checkout <SHA_предыдущего_коммита>
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart app worker nginx
```

> ⚠️ Ручной откат через `git checkout` создаёт расхождение с веткой `main` на GitHub. После него **обязательно** сделайте revert в main, чтобы восстановить синхронизацию.

---

## 9. Дорожная карта реализации

| Приоритет | Задача | Описание |
|---|---|---|
| 🔴 P0 | Купить prod-сервер | VPS 4 vCPU / 8 GB RAM / 80 GB SSD |
| 🔴 P0 | Настроить DNS | `pecado.ru` → prod IP |
| 🔴 P0 | SSL через Let's Encrypt | HTTPS на prod |
| 🔴 P0 | Синхронизировать `main` с `dev` | `git push origin dev:main --force-with-lease` (один раз) |
| 🔴 P0 | Branch protection для `main` | Только через PR, без force-push |
| 🟡 P1 | `docker-compose.prod.yml` | Override-файл с усилением безопасности |
| 🟡 P1 | `docker/nginx/prod.conf` | Nginx с SSL, security headers, gzip |
| 🟡 P1 | `deploy-prod.yml` | GitHub Actions workflow |
| 🟡 P1 | GitHub Environment `production` | Ручной approve |
| 🟡 P1 | Self-hosted runner prod | Лейбл `prod-server` |
| ✅ P2 | Бэкапы БД | Ежедневный cron + retention 30 дней. Offsite в Yandex Object Storage (ICE) с 2026-07-16 |
| 🟢 P2 | Telegram уведомления | Оповещения о деплое |
| 🟢 P2 | Мониторинг (UptimeRobot/Healthchecks.io) | Мониторинг доступности + алертинг |
| 🟢 P2 | Rate limiting | Laravel throttle middleware |
| ⚪ P3 | CDN (Cloudflare) | Кеширование статики + DDoS protection |
| ⚪ P3 | Log aggregation (Sentry) | Централизованные ошибки |
| ⚪ P3 | Blue-green deployment | Zero-downtime деплой |

---

## 10. Связанные документы

- [PROD_WORKFLOW.md](./PROD_WORKFLOW.md) — пошаговый процесс для разработчика (как пушить, мержить, откатывать)
- [CICD_DEV_DEPLOYMENT.md](./CICD_DEV_DEPLOYMENT.md) — автодеплой на dev
- [DEV_SERVER_CREDENTIALS.md](./DEV_SERVER_CREDENTIALS.md) — доступы к dev-серверу
- [.github/workflows/deploy-dev.yml](../.github/workflows/deploy-dev.yml) — реальный dev-workflow (источник для prod)
