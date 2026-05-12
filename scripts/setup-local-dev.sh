#!/usr/bin/env bash
#
# Идемпотентная настройка локального dev-окружения для Pecado.
#
# Делает:
#   1. Проверяет /etc/hosts (запись loc.pecado.ru) — подсказывает sudo-команду, если нет.
#   2. Создаёт/мержит .env: добавляет недостающие переменные из .env.example.
#   3. Поднимает стек: docker compose up -d.
#   4. Генерирует APP_KEY, если он пустой.
#   5. Делает health-чек.
#
# НЕ делает (нужно вручную):
#   - sudo-добавление в /etc/hosts (печатает команду).
#   - make db-pull (тяжёлая операция, запускается отдельно).

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"

# ─── Утилиты ──────────────────────────────────────────────────────────
log()  { printf '\033[1;34m[setup]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[setup]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[setup]\033[0m %s\n' "$*" >&2; }
ok()   { printf '\033[1;32m[setup]\033[0m %s\n' "$*"; }

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    err "Не найдена команда: $1"
    exit 1
  fi
}

require_cmd docker
require_cmd curl

# ─── 1. /etc/hosts ────────────────────────────────────────────────────
log "Проверяю /etc/hosts…"
HOSTS_LINE='127.0.0.1 loc.pecado.ru'
if grep -qE '^[^#]*\bloc\.pecado\.ru\b' /etc/hosts 2>/dev/null; then
  ok "запись loc.pecado.ru уже есть"
  HOSTS_OK=1
else
  warn "В /etc/hosts нет записи для loc.pecado.ru."
  warn "Выполни (требует sudo):"
  warn "    sudo sh -c 'echo \"$HOSTS_LINE\" >> /etc/hosts'"
  HOSTS_OK=0
fi

# ─── 2. .env merge ────────────────────────────────────────────────────
log "Готовлю .env…"
ENV_KEYS=(
  APP_URL SESSION_DOMAIN SANCTUM_STATEFUL_DOMAINS
  VITE_HMR_HOST VITE_HMR_CLIENT_PORT
  MEDIA_DISK
  DEV_S3_KEY DEV_S3_SECRET DEV_S3_REGION DEV_S3_BUCKET DEV_S3_ENDPOINT
  DEV_DB_SSH_HOST DEV_DB_NAME_MAIN DEV_DB_NAME_PRICES
  DEV_DB_REMOTE_USER DEV_DB_REMOTE_PASS
)

if [[ ! -f .env ]]; then
  log ".env не найден — копирую из .env.example"
  cp .env.example .env
  ok ".env создан"
else
  log ".env существует — добавлю недостающие переменные"
  APPENDED=0
  for key in "${ENV_KEYS[@]}"; do
    if ! grep -qE "^${key}=" .env; then
      example_value=$(grep -E "^${key}=" .env.example | head -1 || true)
      if [[ -n "$example_value" ]]; then
        if [[ $APPENDED -eq 0 ]]; then
          {
            echo ""
            echo "# ─── Локальный dev (добавлено setup-local-dev.sh) ───"
          } >> .env
          APPENDED=1
        fi
        echo "$example_value" >> .env
        ok "  + $key"
      fi
    fi
  done
  if [[ $APPENDED -eq 0 ]]; then
    ok "все переменные уже на месте"
  fi
fi

# Принудительно выставляем APP_URL и SESSION_DOMAIN на loc.pecado.ru,
# если они указывают на старый localhost:8085 / null. Эти ключи критичны
# для корректной работы сессий, Vite-манифеста и Sanctum.
fix_env_value() {
  local key="$1" desired="$2"
  local current
  current=$(grep -E "^${key}=" .env | head -1 || true)
  if [[ -z "$current" ]]; then return; fi
  if [[ "$current" == "${key}=${desired}" ]]; then return; fi
  # Замена через временный файл (без sed -i, портабельно для macOS/Linux)
  awk -v k="$key" -v v="$desired" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' .env > .env.tmp && mv .env.tmp .env
  ok "  ~ $key обновлён на '$desired' (было: '${current#*=}')"
}

log "Проверяю APP_URL / SESSION_DOMAIN / MEDIA_DISK…"
fix_env_value APP_URL http://loc.pecado.ru:8085
fix_env_value SESSION_DOMAIN loc.pecado.ru
# Локально читаем медиа из dev MinIO. В .env.example стоит 'public',
# чтобы CI не падал, поэтому форсим тут.
fix_env_value MEDIA_DISK s3_dev_readonly

# ─── 3. docker compose up -d ──────────────────────────────────────────
log "Поднимаю стек: docker compose up -d…"
docker compose up -d

# ─── 4. APP_KEY ───────────────────────────────────────────────────────
log "Жду готовности pecado-app…"
for i in $(seq 1 20); do
  if docker exec pecado-app php -r 'echo "ok";' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! grep -qE '^APP_KEY=.+' .env; then
  log "APP_KEY пуст — генерирую…"
  docker exec pecado-app php artisan key:generate
  ok "APP_KEY сгенерирован"
else
  ok "APP_KEY уже задан"
fi

# Сбрасываем кэш конфига, чтобы свежий .env подхватился
docker exec pecado-app php artisan config:clear >/dev/null 2>&1 || true

# ─── 5. Health-чек ────────────────────────────────────────────────────
log "Health-чек…"
sleep 2

NGINX_OK=0

if curl -sIf --max-time 5 http://localhost:8085/up >/dev/null 2>&1; then
  ok "http://localhost:8085/up отвечает (nginx → app)"
  NGINX_OK=1
fi

if [[ $HOSTS_OK -eq 1 ]]; then
  if curl -sIf --max-time 5 http://loc.pecado.ru:8085/up >/dev/null 2>&1; then
    ok "http://loc.pecado.ru:8085/up отвечает"
  else
    warn "http://loc.pecado.ru:8085 не отвечает, хотя /etc/hosts настроен. Проверь логи: docker logs pecado-nginx"
  fi
fi

if [[ $NGINX_OK -eq 0 ]]; then
  warn "nginx не отвечает на /up. Проверь:"
  warn "    docker compose ps"
  warn "    docker compose logs --tail=50 nginx app"
fi

# ─── Итог ─────────────────────────────────────────────────────────────
echo
log "Готово. Что дальше:"
if [[ $HOSTS_OK -eq 0 ]]; then
  log "  1. Добавь loc.pecado.ru в /etc/hosts:"
  log "     sudo sh -c 'echo \"$HOSTS_LINE\" >> /etc/hosts'"
fi
log "  • Стянуть БД с dev (займёт минуты): make db-pull"
log "  • Только основная БД (быстро):       make db-pull DB=main"
log "  • Логи Vite/nginx:                   make dev"
log "  • Открыть приложение:                http://loc.pecado.ru:8085"
