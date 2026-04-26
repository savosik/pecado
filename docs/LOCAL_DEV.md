# Локальная разработка

Этот документ описывает, как поднять локально весь стек Pecado так, чтобы:

- приложение открывалось по `http://loc.pecado.ru` (без порта);
- БД содержала те же данные, что на dev-сервере;
- товарные изображения подгружались из dev MinIO;
- Vite с HMR запускался автоматически вместе со стеком.

## Один раз: первичная настройка

### 1. /etc/hosts

```bash
sudo sh -c 'echo "127.0.0.1 loc.pecado.ru" >> /etc/hosts'
```

Проверить: `getent hosts loc.pecado.ru` → `127.0.0.1 loc.pecado.ru`.

### 2. Свободный 80-й порт

Caddy слушает 80. Если на хосте уже есть apache2/nginx:

```bash
sudo lsof -iTCP:80 -sTCP:LISTEN
```

— покажет, кто держит порт. Гасим/отключаем (`sudo systemctl stop apache2`).

### 3. SSH-доступ к dev-серверу

Скрипт `db-pull.sh` ходит по SSH. Проверить:

```bash
ssh ladmin@93.94.150.16 echo ok
```

Если ключа нет — положить в `~/.ssh/`. Креды из [docs/DEV_SERVER_CREDENTIALS.md](DEV_SERVER_CREDENTIALS.md).

### 4. .env

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate
```

В `.env` уже подставлены значения для локального dev на `loc.pecado.ru`. Проверить, что есть:

- `APP_URL=http://loc.pecado.ru`
- `SESSION_DOMAIN=loc.pecado.ru`
- `SANCTUM_STATEFUL_DOMAINS=loc.pecado.ru`
- `VITE_HMR_HOST=loc.pecado.ru`
- `MEDIA_DISK=s3_dev_readonly`
- `DEV_S3_*`, `DEV_DB_SSH_HOST`

### 5. Поднять стек

```bash
make up          # docker compose up -d (включая Caddy)
make db-pull     # стянуть обе БД с dev (займёт минуты)
```

Открыть `http://loc.pecado.ru` — должна показаться витрина с реальными товарами.

## Повседневный workflow

| Команда                   | Что делает                                                       |
|---------------------------|------------------------------------------------------------------|
| `make up`                 | Поднять стек (Vite внутри `pecado-node` стартует автоматически)  |
| `make dev`                | `up` + tail логов node/nginx/caddy (видно HMR в реальном времени)|
| `make down`               | Остановить всё                                                   |
| `make restart`            | down + up                                                        |
| `make logs S=app`         | Tail логов сервиса (S=имя; пусто — все)                          |
| `make ps`                 | Статус контейнеров                                               |
| `make sh`                 | bash в `pecado-app`                                              |
| `make tinker`             | `php artisan tinker`                                             |
| `make restart-vite`       | Перезапустить контейнер `node` (если HMR завис)                  |
| `make db-pull`            | Стянуть обе БД с dev                                             |
| `make db-pull DB=main`    | Только `pecado` (без тяжёлой `pecado_prices`)                    |
| `make db-pull DB=prices`  | Только `pecado_prices`                                           |

## Архитектура локального стека

```
браузер → Caddy(80) → nginx(80) → app(php-fpm 9000)
                   ↘ (HMR WS) → node(5174→5173)

storage чтения (Spatie media) → s3_dev_readonly → 93.94.150.16:9000/pecado
storage записи (контент upload) → s3 → локальный MinIO (createbuckets)

БД: pecado-mysql (3308), pecado-mysql-prices (3309) — наполняются db-pull.sh
```

`docker-compose.override.yml` добавляет контейнер Caddy поверх основного `docker-compose.yml`. Override подхватывается автоматически.

## Troubleshooting

### `loc.pecado.ru` не открывается

1. `getent hosts loc.pecado.ru` — должен возвращать `127.0.0.1`. Если нет — см. шаг 1 выше.
2. `make ps` — в списке должен быть `pecado-caddy`. Если нет — `make up`.
3. `docker logs pecado-caddy` — ищем ошибки на 80 порту (часто «address already in use»).
4. `curl -sI http://loc.pecado.ru` — должен вернуть 200/302.

### HMR не работает / WebSocket падает

1. В DevTools → Network проверить, что `@vite/client` грузится с `loc.pecado.ru:5174`.
2. Если порт другой — проверить `VITE_HMR_HOST` и `VITE_HMR_CLIENT_PORT` в `.env`.
3. `make restart-vite` — иногда контейнер залипает после `npm install`.
4. `docker logs pecado-node --tail=100` — Vite должен сказать `ready in N ms`.

### Картинки товаров не грузятся

1. Открыть DevTools → Network, найти запрос на `93.94.150.16:9000/pecado/...`.
2. Если CORS/блокировка — проверить, что `MEDIA_DISK=s3_dev_readonly` в `.env` и `php artisan config:clear`.
3. Если 403 — bucket `pecado` на dev должен быть public; проверить через консоль `93.94.150.16:9001`.

### `db-pull` падает на SSH

```
Permission denied (publickey)
```

— положить SSH-ключ в `~/.ssh/`, добавить `Host 93.94.150.16` в `~/.ssh/config` или прокинуть через `ssh-agent`.

### `db-pull` падает на mysqldump (большая БД)

`pecado_prices` может быть несколько ГБ. Варианты:
- запускать только основную: `make db-pull DB=main`;
- увеличить таймаут SSH: добавить `ServerAliveInterval 60` в `~/.ssh/config`;
- запустить ночью.

### Конфликт с собственным `docker-compose.override.yml`

Если у разработчика уже есть свой override — переименовать наш в `docker-compose.caddy.yml` и подключать вручную:

```bash
docker compose -f docker-compose.yml -f docker-compose.caddy.yml -f docker-compose.override.yml up -d
```

### OAuth-логины

Google/Yandex redirect-URL зарегистрированы на prod-домен. На локалке социальный логин не работает — это нормально, тестируем на dev/prod.

## Не делать

- **Не загружать на локалке "тестовые" изображения и не ожидать, что они будут на dev.** Локальные uploads уходят в локальный MinIO (createbuckets bucket `pecado`), не в shared dev-bucket.
- **Не редактировать `docker-compose.yml` под себя.** Вместо этого использовать `docker-compose.override.yml` (gitignored по конвенции).
- **Не коммитить `.env`** — он в `.gitignore`.
