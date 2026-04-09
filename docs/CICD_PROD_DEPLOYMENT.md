# CI/CD: Деплой ветки `main` на Production-сервер

> **Статус:** 📋 В планах  
> **Стек:** Laravel 11 · PHP 8.3-FPM · Vite/Node 20 · MySQL 8 · Redis · RabbitMQ 3 · MeiliSearch · MinIO · Supervisor · Docker Compose  
> **Связанный документ:** [CICD_DEV_DEPLOYMENT.md](./CICD_DEV_DEPLOYMENT.md)

---

## 1. Архитектура пайплайна (целевая)

```
                     ┌── feature/* (только разработка)
                     │
   dev ──────────────┤── GitHub Actions ──→ Dev Server  (93.94.150.16)
                     │     Tests + Deploy (auto)
                     │
   main ─── PR merge ┤── GitHub Actions ──→ Prod Server (новый VPS)
                     │     Tests + Approval + Deploy
                     └
```

### Ключевые отличия dev vs prod Pipeline

| Аспект | Dev | Prod |
|---|---|---|
| Триггер | Push в `dev` | Push в `main` (после PR) |
| Approval | Нет | **Обязательный** (GitHub Environment protection) |
| `APP_DEBUG` | `true` | **`false`** |
| `APP_ENV` | `dev` | **`production`** |
| Composer | `--no-dev` | `--no-dev --optimize-autoloader` |
| Maintenance mode | Нет | **Да** (`artisan down` во время деплоя) |
| Rollback | Не нужен | **Обязательно** |
| Мониторинг | Логи | **Логи + Uptime + Alerting** |

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
    ports: []   # Не экспонировать наружу!
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}

  redis:
    ports: []   # Не экспонировать наружу!
    command: redis-server --requirepass ${REDIS_PASSWORD}

  rabbitmq:
    ports:
      - "127.0.0.1:15672:15672"   # Management только с localhost
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_PASSWORD}

  meilisearch:
    ports: []   # Не экспонировать наружу!
    environment:
      MEILI_MASTER_KEY: ${MEILISEARCH_KEY}

  minio:
    ports:
      - "127.0.0.1:9001:9001"
    environment:
      MINIO_ROOT_USER: ${MINIO_ACCESS_KEY}
      MINIO_ROOT_PASSWORD: ${MINIO_SECRET_KEY}

  mailpit:
    profiles: ["dev"]   # На prod отключен

  node:
    profiles: ["dev"]   # На prod не нужен — фронт собирается в CI
```

**Запуск на prod:** `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`

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

# ─── База данных ───
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=pecado
DB_USERNAME=pecado_prod
DB_PASSWORD=<STRONG_PASSWORD_32+_CHARS>

# ─── Кеширование ───
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<STRONG_REDIS_PASSWORD>

# ─── Очереди ───
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=pecado_prod
RABBITMQ_PASSWORD=<STRONG_RABBITMQ_PASSWORD>

# ─── Поиск ───
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

# ─── Почта (реальный SMTP на prod!) ───
MAIL_MAILER=smtp
MAIL_HOST=smtp.yandex.ru
MAIL_PORT=465
MAIL_USERNAME=noreply@pecado.ru
MAIL_PASSWORD=<SMTP_PASSWORD>
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@pecado.ru
MAIL_FROM_NAME="Pecado"

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
| 3 | Порты MySQL | Открыты (3308) | **Закрыты** |
| 4 | Порты Redis | Открыты (6381) | **Закрыты** + пароль |
| 5 | Порты RabbitMQ mgmt | Открыты (15672) | **Только localhost** |
| 6 | Порты MeiliSearch | Открыты (7701) | **Закрыты** |
| 7 | Порты MinIO console | Открыты (9001) | **Только localhost** |
| 8 | Пароли | Простые (`secret`, `guest`) | **Сильные** (32+ chars) |
| 9 | SSL/HTTPS | Нет | **Обязательно** (Let's Encrypt) |
| 10 | Security headers | Нет | **Да** (HSTS, CSP, X-Frame-Options) |
| 11 | Firewall (UFW) | Нет | **Да** (только 22, 80, 443) |
| 12 | Fail2ban | Нет | **Да** |
| 13 | SSH root login | — | **Запрещён** |
| 14 | Бэкапы БД | Нет | **Ежедневно** |
| 15 | Mailpit | Да | **Убран** |

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
  cancel-in-progress: false  # НЕ отменяем — prod деплой должен завершиться!

jobs:
  # ─────────────────────────────────────────
  # JOB 1: Тесты (идентично dev)
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
        ports:
          - "3306:3306"
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3

      redis:
        image: redis:alpine
        ports:
          - "6379:6379"
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
        ports:
          - "5672:5672"
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
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

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
          php artisan key:generate
          mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions
          mkdir -p bootstrap/cache
          mkdir -p public/build
          echo '{"resources/css/app.css":{"file":"assets/app.css","src":"resources/css/app.css"},"resources/js/app.jsx":{"file":"assets/app.js","src":"resources/js/app.jsx","isEntry":true}}' > public/build/manifest.json

      - name: "🗃️ Run migrations"
        run: php artisan migrate --force

      - name: "✅ Run tests"
        run: php artisan test

  # ─────────────────────────────────────────
  # JOB 2: Сборка фронтенда
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

      - name: "📤 Upload build artifacts"
        uses: actions/upload-artifact@v4
        with:
          name: frontend-build
          path: public/build
          retention-days: 1

  # ─────────────────────────────────────────
  # JOB 3: Деплой на Prod-сервер
  # ─────────────────────────────────────────
  deploy:
    name: "🚀 Deploy to Production"
    runs-on: [self-hosted, prod-server]
    needs: [test, build-frontend]
    timeout-minutes: 20
    environment:
      name: production   # ⬅️ Требует ручного approval в GitHub!

    steps:
      - name: "📥 Checkout"
        uses: actions/checkout@v4

      - name: "📥 Download frontend build"
        uses: actions/download-artifact@v4
        with:
          name: frontend-build
          path: public/build

      - name: "🔧 Fix Docker file ownership"
        run: |
          CURRENT_UID=$(id -u)
          CURRENT_GID=$(id -g)
          sg docker -c "docker run --rm -v /srv/pecado/public/build:/build alpine chown -R ${CURRENT_UID}:${CURRENT_GID} /build" 2>/dev/null || true

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
            ./ /srv/pecado/

      - name: "🐳 Deploy"
        run: |
          set -euo pipefail
          cd /srv/pecado
          COMPOSE="sg docker -c 'docker compose -f docker-compose.yml -f docker-compose.prod.yml'"

          # Maintenance mode
          eval "$COMPOSE exec -T app php artisan down --retry=30 --refresh=5" || true

          echo "==> [1/8] PHP зависимости..."
          eval "$COMPOSE exec -T app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader"

          echo "==> [2/8] Очистка и прогрев кешей..."
          eval "$COMPOSE exec -T app php artisan optimize:clear"
          eval "$COMPOSE exec -T app php artisan config:cache"
          eval "$COMPOSE exec -T app php artisan route:cache"
          eval "$COMPOSE exec -T app php artisan view:cache"
          eval "$COMPOSE exec -T app php artisan optimize"

          echo "==> [3/8] Миграции..."
          eval "$COMPOSE exec -T app php artisan migrate --force"

          echo "==> [4/8] Роли и права..."
          eval "$COMPOSE exec -T app php artisan db:seed --class=RolesAndPermissionsSeeder --force"

          echo "==> [5/8] RabbitMQ topology..."
          eval "$COMPOSE exec -T app php artisan rabbitmq:setup" || true

          echo "==> [6/8] Перезапуск очередей..."
          eval "$COMPOSE exec -T app php artisan queue:restart"

          echo "==> [7/8] Перезагрузка контейнеров..."
          eval "$COMPOSE restart app worker"

          echo "==> [8/8] Снятие maintenance mode..."
          eval "$COMPOSE exec -T app php artisan up"

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
          # TODO: Telegram/Slack notification
```

---

## 5. Настройка GitHub

### 5.1 Environment `production`

**Settings → Environments → New environment → `production`**

- ✅ **Required reviewers** — добавить себя
- ✅ **Wait timer** — 0 минут (или 5 мин для "cooling off")
- ✅ **Deployment branches** — только `main`

### 5.2 GitHub Secrets для Prod

| Secret | Описание |
|---|---|
| `PROD_SSH_PRIVATE_KEY` | SSH-ключ для prod сервера (если без self-hosted runner) |
| `PROD_SERVER_HOST` | IP или домен prod-сервера |
| `PROD_SSH_USER` | Пользователь `deploy` |

> При использовании self-hosted runner на prod-сервере SSH secrets не нужны.  
> Нужен runner с лейблом `prod-server`.

---

## 6. Подготовка Prod-сервера — пошагово

### Шаг 1: Настройка ОС

```bash
# Обновление
sudo apt update && sudo apt upgrade -y

# Создать пользователя deploy
sudo adduser deploy
sudo usermod -aG sudo,docker deploy

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

# GitHub Actions Runner
cd /home/deploy
mkdir actions-runner && cd actions-runner
curl -o actions-runner-linux-x64.tar.gz -L https://github.com/actions/runner/releases/download/v2.XXX.X/actions-runner-linux-x64-2.XXX.X.tar.gz
tar xzf ./actions-runner-linux-x64.tar.gz
./config.sh --url https://github.com/ORG/pecado --token TOKEN --labels prod-server
sudo ./svc.sh install
sudo ./svc.sh start
```

### Шаг 3: SSL (Let's Encrypt)

```bash
sudo apt install certbot -y

# DNS должен указывать на prod IP!
sudo certbot certonly --standalone -d pecado.ru -d www.pecado.ru

# Автопродление уже настроено через systemd timer
sudo systemctl status certbot.timer
```

### Шаг 4: Первый запуск проекта

```bash
su - deploy
cd /srv
git clone -b main git@github.com:ORG/pecado.git pecado
cd pecado

# Создать .env для production (по шаблону из п. 3.3)
cp .env.example .env
nano .env

# Запуск
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Инициализация
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
docker compose exec app php artisan optimize
```

### Шаг 5: Бэкапы базы данных

```bash
# Создать скрипт /srv/scripts/backup-db.sh
#!/bin/bash
BACKUP_DIR="/srv/backups/mysql"
DATE=$(date +%Y-%m-%d_%H%M)
mkdir -p $BACKUP_DIR

docker compose -f /srv/pecado/docker-compose.yml exec -T mysql \
  mysqldump -u pecado_prod -p<PASSWORD> pecado | gzip > "$BACKUP_DIR/pecado_$DATE.sql.gz"

# Удаляем бэкапы старше 30 дней
find $BACKUP_DIR -type f -name "*.sql.gz" -mtime +30 -delete

echo "Backup completed: pecado_$DATE.sql.gz"
```

```bash
# Cron: ежедневно в 3:00
crontab -e
0 3 * * * /srv/scripts/backup-db.sh >> /var/log/backup.log 2>&1
```

---

## 7. Workflow разработки (итоговый)

```
1. Разработчик создаёт feature/xxx из dev
2. Работает в feature/xxx, коммитит
3. Merge feature/xxx → dev (или push в dev напрямую)
   → CI: тесты → автодеплой на DEV сервер
4. Тестирование на dev
5. Создаёт PR: dev → main
6. Code review → approve PR → merge
   → CI: тесты → ожидание approval → деплой на PROD
7. Проверка на prod (health check + мониторинг)
```

---

## 8. Дорожная карта реализации

| Приоритет | Задача | Описание |
|---|---|---|
| 🔴 P0 | Купить prod-сервер | VPS 4 vCPU / 8 GB RAM |
| 🔴 P0 | Настроить DNS | `pecado.ru` → prod IP |
| 🔴 P0 | SSL через Let's Encrypt | HTTPS на prod |
| 🟡 P1 | `docker-compose.prod.yml` | Override файл с усилением безопасности |
| 🟡 P1 | `docker/nginx/prod.conf` | Nginx с SSL, security headers, gzip |
| 🟡 P1 | `deploy-prod.yml` | GitHub Actions workflow |
| 🟡 P1 | GitHub Environment `production` | Ручной approve |
| 🟡 P1 | Self-hosted runner prod | Лейбл `prod-server` |
| 🟢 P2 | Бэкапы БД | Ежедневный cron + retention 30 дней |
| 🟢 P2 | Telegram уведомления | Оповещения о деплое |
| 🟢 P2 | Мониторинг (UptimeRobot) | Мониторинг доступности + алертинг |
| 🟢 P2 | Rate limiting | Laravel throttle middleware |
| ⚪ P3 | CDN (Cloudflare) | Кеширование статики + DDoS protection |
| ⚪ P3 | Log aggregation | Централизованные логи (Sentry / Grafana) |
| ⚪ P3 | Blue-green deployment | Zero-downtime деплой |
