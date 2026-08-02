#!/usr/bin/env bash
#
# Скачивает БД с dev-сервера и импортирует в локальные контейнеры.
#
# Использование:
#   scripts/db-pull.sh             # обе БД (по умолчанию)
#   scripts/db-pull.sh all         # обе БД
#   scripts/db-pull.sh main        # только pecado
#   scripts/db-pull.sh prices      # только pecado_prices
#
# Конфигурация через .env (с фолбэками):
#   DEV_DB_SSH_HOST   — SSH к dev, например ladmin@dev.pecado.ru (93.94.150.74)
#   DEV_DB_NAME_MAIN  — имя основной БД (default: pecado)
#   DEV_DB_NAME_PRICES — имя БД цен (default: pecado_prices)
#   DEV_DB_REMOTE_USER, DEV_DB_REMOTE_PASS — креды БД на dev (default: pecado/secret)
#
# Локальные контейнеры (фиксированы в docker-compose.yml):
#   pecado-mysql        — БД pecado
#   pecado-mysql-prices — БД pecado_prices

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# Загрузка .env
# ─────────────────────────────────────────────────────────────
ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

# ⚠️ Только dev: 93.94.150.16 — это prod, оттуда базу не тянем.
DEV_DB_SSH_HOST="${DEV_DB_SSH_HOST:-ladmin@dev.pecado.ru}"
DEV_DB_NAME_MAIN="${DEV_DB_NAME_MAIN:-pecado}"
DEV_DB_NAME_PRICES="${DEV_DB_NAME_PRICES:-pecado_prices}"
DEV_DB_REMOTE_USER="${DEV_DB_REMOTE_USER:-pecado}"
DEV_DB_REMOTE_PASS="${DEV_DB_REMOTE_PASS:-secret}"
DEV_DB_REMOTE_CONTAINER_MAIN="${DEV_DB_REMOTE_CONTAINER_MAIN:-pecado-mysql}"
DEV_DB_REMOTE_CONTAINER_PRICES="${DEV_DB_REMOTE_CONTAINER_PRICES:-pecado-mysql-prices}"

LOCAL_DB_USER="pecado"
LOCAL_DB_PASS="secret"

TARGET="${1:-all}"

# ─────────────────────────────────────────────────────────────
# Утилиты
# ─────────────────────────────────────────────────────────────
log() { printf '\033[1;34m[db-pull]\033[0m %s\n' "$*"; }
err() { printf '\033[1;31m[db-pull]\033[0m %s\n' "$*" >&2; }

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    err "Не найдена команда: $1"
    exit 1
  fi
}

require_cmd ssh
require_cmd docker

# ─────────────────────────────────────────────────────────────
# Проверка SSH-доступа
# ─────────────────────────────────────────────────────────────
log "Проверяю SSH-доступ к ${DEV_DB_SSH_HOST}…"
if ! ssh -o BatchMode=yes -o ConnectTimeout=5 "$DEV_DB_SSH_HOST" "echo ok" >/dev/null 2>&1; then
  err "SSH к ${DEV_DB_SSH_HOST} не проходит. Проверь ~/.ssh/config или ключи."
  exit 1
fi

# ─────────────────────────────────────────────────────────────
# Импорт одной БД: dev → локальный контейнер
# ─────────────────────────────────────────────────────────────
# args: <remote_container> <remote_db_name> <local_container> <local_db_name>
pull_one() {
  local remote_container="$1"
  local remote_db="$2"
  local container="$3"
  local local_db="$4"

  log "→ ${remote_db}: дамп с dev (${remote_container}) и импорт в ${container}/${local_db}"

  # Проверка, что локальный контейнер запущен
  if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
    err "Контейнер ${container} не запущен. Сначала: make up"
    exit 1
  fi

  # mysqldump запускается внутри dev-контейнера через docker exec — на хосте
  # dev-сервера mysql-client не установлен. Локально gunzip и docker exec
  # импортируют дамп.
  # --single-transaction — консистентный снимок без блокировок (InnoDB).
  # --no-tablespaces — чтобы не требовать PROCESS-привилегию.
  # --routines/--triggers — переносим хранимки и триггеры.
  # --column-statistics=0 — для совместимости MySQL 8.
  ssh "$DEV_DB_SSH_HOST" "docker exec -i ${remote_container} mysqldump \
      -u${DEV_DB_REMOTE_USER} -p${DEV_DB_REMOTE_PASS} \
      --single-transaction --quick --no-tablespaces \
      --routines --triggers --column-statistics=0 \
      ${remote_db} | gzip -c" \
    | gunzip -c \
    | docker exec -i "$container" mysql \
        -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" "$local_db"

  log "✓ ${remote_db} импортирована в ${container}"
}

# ─────────────────────────────────────────────────────────────
# Пост-обработка (только когда тащили основную БД)
# ─────────────────────────────────────────────────────────────
post_main() {
  log "Чистим кэши Laravel…"
  docker exec pecado-app php artisan optimize:clear || true

  log "Пересинк Meilisearch (Product)…"
  docker exec pecado-app php artisan scout:flush "App\\Models\\Product" || true
  docker exec pecado-app php artisan scout:import "App\\Models\\Product" || true
}

# ─────────────────────────────────────────────────────────────
# Главная логика
# ─────────────────────────────────────────────────────────────
case "$TARGET" in
  all)
    pull_one "$DEV_DB_REMOTE_CONTAINER_MAIN"   "$DEV_DB_NAME_MAIN"   "pecado-mysql"        "pecado"
    pull_one "$DEV_DB_REMOTE_CONTAINER_PRICES" "$DEV_DB_NAME_PRICES" "pecado-mysql-prices" "pecado_prices"
    post_main
    ;;
  main)
    pull_one "$DEV_DB_REMOTE_CONTAINER_MAIN" "$DEV_DB_NAME_MAIN" "pecado-mysql" "pecado"
    post_main
    ;;
  prices)
    pull_one "$DEV_DB_REMOTE_CONTAINER_PRICES" "$DEV_DB_NAME_PRICES" "pecado-mysql-prices" "pecado_prices"
    ;;
  *)
    err "Неизвестная цель: '$TARGET'. Используй: all | main | prices"
    exit 2
    ;;
esac

log "Готово."
