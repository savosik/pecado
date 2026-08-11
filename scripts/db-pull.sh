#!/usr/bin/env bash
#
# Скачивает БД с dev- или prod-сервера и импортирует в локальные контейнеры.
#
# Использование:
#   scripts/db-pull.sh                      # обе БД с dev (по умолчанию)
#   scripts/db-pull.sh all                  # обе БД с dev
#   scripts/db-pull.sh main                 # только pecado с dev
#   scripts/db-pull.sh prices               # только pecado_prices с dev
#   scripts/db-pull.sh all --from=prod      # обе БД с БОЕВОГО сервера
#   scripts/db-pull.sh all --reindex        # + переиндексация Meilisearch
#   scripts/db-pull.sh all --from=prod --yes  # без интерактивного подтверждения
#
# Конфигурация через .env (с фолбэками):
#   DEV_DB_SSH_HOST    — SSH к dev,  например ladmin@dev.pecado.ru (93.94.150.74)
#   PROD_DB_SSH_HOST   — SSH к prod, например ladmin@93.94.150.16 (LAN: 10.2.2.101)
#   *_DB_NAME_MAIN / *_DB_NAME_PRICES / *_DB_REMOTE_USER / *_DB_REMOTE_PASS
#
# Локальные контейнеры (фиксированы в docker-compose.yml):
#   pecado-mysql        — БД pecado
#   pecado-mysql-prices — БД pecado_prices
#
# ⚠️ С прода приезжают РЕАЛЬНЫЕ персональные данные клиентов (телефоны, адреса,
#    заказы). Диск локальной машины не зашифрован. Скрипт обнуляет токены
#    интеграций после импорта, но саму базу не анонимизирует.

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# Загрузка .env
# ─────────────────────────────────────────────────────────────
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

# ─────────────────────────────────────────────────────────────
# Разбор аргументов
# ─────────────────────────────────────────────────────────────
TARGET="all"
SOURCE="dev"
REINDEX=0
ASSUME_YES=0
FULL=0

for arg in "$@"; do
  case "$arg" in
    all|main|prices) TARGET="$arg" ;;
    --from=prod)     SOURCE="prod" ;;
    --from=dev)      SOURCE="dev" ;;
    --reindex)       REINDEX=1 ;;
    --full)          FULL=1 ;;
    --yes|-y)        ASSUME_YES=1 ;;
    *)
      echo "Неизвестный аргумент: '$arg'" >&2
      echo "Использование: $0 [all|main|prices] [--from=dev|prod] [--full] [--reindex] [--yes]" >&2
      exit 2
      ;;
  esac
done

# ─────────────────────────────────────────────────────────────
# Таблицы, чьи ДАННЫЕ локально не нужны
# ─────────────────────────────────────────────────────────────
# Структура этих таблиц переносится (иначе приложение упадёт на запросах),
# а содержимое — нет. Замер боевой базы 2026-08-09: из 6633 MB на них
# приходилось 6395 MB, то есть 96 %. Полезных данных — около 240 MB.
#
#   pulse_aggregates   4536 MB   мониторинг Laravel Pulse
#   pulse_entries      1323 MB   он же
#   erp_bus_messages    454 MB   лог сообщений шины 1С
#   erp_processed_messages 82 MB дедупликация входящих ERP
#
# Pulse удалён вместе с таблицами 2026-08-12 — 5,8 GB из этих 6395 MB больше
# не существует. Остальные позиции остаются: тянуть их целиком — значит ждать
# ради данных, которые локально бесполезны. `--full` отключает фильтр, если
# понадобится точная копия.
SKIP_DATA_TABLES=(
  telescope_entries telescope_entries_tags telescope_monitoring
  erp_bus_messages erp_processed_messages
  failed_jobs jobs job_batches
  sessions cache cache_locks
)

# individual_prices в БД цен — 8,7 GB реальных данных. Локально они нужны
# редко, поэтому по умолчанию тоже пропускаем; `--full` вернёт.
SKIP_DATA_TABLES_PRICES=( individual_prices )

# ─────────────────────────────────────────────────────────────
# Параметры источника
# ─────────────────────────────────────────────────────────────
if [[ "$SOURCE" == "prod" ]]; then
  SSH_HOST="${PROD_DB_SSH_HOST:-ladmin@93.94.150.16}"
  DB_MAIN="${PROD_DB_NAME_MAIN:-pecado}"
  DB_PRICES="${PROD_DB_NAME_PRICES:-pecado_prices}"
  REMOTE_USER="${PROD_DB_REMOTE_USER:-pecado}"
  REMOTE_PASS="${PROD_DB_REMOTE_PASS:-secret}"
  RC_MAIN="${PROD_DB_REMOTE_CONTAINER_MAIN:-pecado-mysql}"
  RC_PRICES="${PROD_DB_REMOTE_CONTAINER_PRICES:-pecado-mysql-prices}"
  # У БД цен на проде может быть своя учётка
  REMOTE_USER_PRICES="${PROD_DB_PRICES_USER:-$REMOTE_USER}"
  REMOTE_PASS_PRICES="${PROD_DB_PRICES_PASS:-$REMOTE_PASS}"
else
  # ⚠️ Только dev: 93.94.150.16 — это prod, туда ходим лишь по --from=prod.
  SSH_HOST="${DEV_DB_SSH_HOST:-ladmin@dev.pecado.ru}"
  DB_MAIN="${DEV_DB_NAME_MAIN:-pecado}"
  DB_PRICES="${DEV_DB_NAME_PRICES:-pecado_prices}"
  REMOTE_USER="${DEV_DB_REMOTE_USER:-pecado}"
  REMOTE_PASS="${DEV_DB_REMOTE_PASS:-secret}"
  RC_MAIN="${DEV_DB_REMOTE_CONTAINER_MAIN:-pecado-mysql}"
  RC_PRICES="${DEV_DB_REMOTE_CONTAINER_PRICES:-pecado-mysql-prices}"
  REMOTE_USER_PRICES="${DEV_DB_PRICES_USER:-$REMOTE_USER}"
  REMOTE_PASS_PRICES="${DEV_DB_PRICES_PASS:-$REMOTE_PASS}"
fi

# Импортируем под root: дамп прода содержит объекты с DEFINER от
# привилегированного пользователя, и обычному `pecado` не хватает прав
# (ERROR 1227: you need SYSTEM_USER privilege). DEFINER мы вырезаем ниже,
# но root снимает и остальные подобные грабли — например, SET @@SESSION.sql_log_bin.
LOCAL_DB_USER="${LOCAL_DB_ROOT_USER:-root}"
LOCAL_DB_PASS="${LOCAL_DB_ROOT_PASS:-secret}"

# ─────────────────────────────────────────────────────────────
# Утилиты
# ─────────────────────────────────────────────────────────────
log()  { printf '\033[1;34m[db-pull]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[db-pull]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[db-pull]\033[0m %s\n' "$*" >&2; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { err "Не найдена команда: $1"; exit 1; }
}

require_cmd ssh
require_cmd docker

# ─────────────────────────────────────────────────────────────
# Предохранитель для боевой базы
# ─────────────────────────────────────────────────────────────
if [[ "$SOURCE" == "prod" && "$ASSUME_YES" -eq 0 ]]; then
  warn "════════════════════════════════════════════════════════════"
  warn " Источник — БОЕВОЙ сервер ($SSH_HOST)."
  warn " Локальные БД будут ПЕРЕЗАПИСАНЫ копией прода с реальными"
  warn " персональными данными клиентов."
  warn "════════════════════════════════════════════════════════════"
  read -r -p "Продолжить? [y/N] " answer
  [[ "$answer" =~ ^[yYдД]$ ]] || { log "Отменено."; exit 0; }
fi

# ─────────────────────────────────────────────────────────────
# Проверка, что локальный контур не смотрит в боевой
# ─────────────────────────────────────────────────────────────
# Импорт прод-базы + локальный worker, случайно подключённый к боевому
# RabbitMQ, = сообщения в 1С от машины разработчика. Проверяем до импорта.
check_local_env_is_safe() {
  local problems=()
  local host

  host="${RABBITMQ_HOST:-}"
  [[ "$host" =~ (93\.94\.150\.16|10\.2\.2\.101|pecado\.ru) ]] && problems+=("RABBITMQ_HOST=$host")

  host="${AWS_ENDPOINT:-${MINIO_ENDPOINT:-}}"
  [[ "$host" =~ (93\.94\.150\.16|10\.2\.2\.101|pecado\.ru) ]] && problems+=("S3/MinIO endpoint=$host")

  [[ "${MAIL_MAILER:-}" == "smtp" && ! "${MAIL_HOST:-}" =~ (mailpit|localhost|127\.0\.0\.1) ]] \
    && problems+=("MAIL_HOST=${MAIL_HOST:-?} — письма уйдут наружу")

  if (( ${#problems[@]} > 0 )); then
    err "Локальный .env смотрит на боевой контур:"
    printf '  - %s\n' "${problems[@]}" >&2
    err "Импорт прод-базы при таких настройках может отправить данные в 1С или клиентам."
    err "Поправьте .env и повторите (либо --yes, если уверены)."
    [[ "$ASSUME_YES" -eq 1 ]] || exit 1
  fi
}
[[ "$SOURCE" == "prod" ]] && check_local_env_is_safe

# ─────────────────────────────────────────────────────────────
# Проверка SSH-доступа
# ─────────────────────────────────────────────────────────────
log "Проверяю SSH-доступ к ${SSH_HOST}…"
if ! ssh -o BatchMode=yes -o ConnectTimeout=8 "$SSH_HOST" "echo ok" >/dev/null 2>&1; then
  err "SSH к ${SSH_HOST} не проходит."
  err "Проверьте: включён ли VPN / находитесь ли в офисной сети (LAN 10.2.2.0/24),"
  err "ключи в ~/.ssh, запись в ~/.ssh/config."
  exit 1
fi

# ─────────────────────────────────────────────────────────────
# Импорт одной БД: удалённый сервер → локальный контейнер
# ─────────────────────────────────────────────────────────────
# args: <remote_container> <remote_db> <local_container> <local_db>
pull_one() {
  local remote_container="$1" remote_db="$2" container="$3" local_db="$4"
  # Пятый/шестой аргументы — учётка на удалённом сервере. У БД цен она своя.
  local ruser="${5:-$REMOTE_USER}" rpass="${6:-$REMOTE_PASS}"

  log "→ ${remote_db}: дамп с ${SOURCE} (${remote_container}) → ${container}/${local_db}"

  if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
    err "Контейнер ${container} не запущен. Сначала: make up"
    exit 1
  fi

  # Пересоздаём схему: дамп содержит DROP TABLE только для тех таблиц, что в нём
  # есть. Без этого от прошлого импорта (или от прерванного) остаются лишние
  # таблицы, и картина перестаёт совпадать с боевой.
  docker exec -i "$container" mysql -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" \
    -e "DROP DATABASE IF EXISTS \`${local_db}\`;
        CREATE DATABASE \`${local_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON \`${local_db}\`.* TO 'pecado'@'%';" 2>/dev/null \
    || warn "Не удалось пересоздать схему ${local_db} — импортирую поверх"

  # Какие таблицы этой БД тянем без данных
  local -a skip=()
  if [[ "$FULL" -eq 0 ]]; then
    if [[ "$local_db" == *prices* ]]; then
      skip=("${SKIP_DATA_TABLES_PRICES[@]}")
    else
      skip=("${SKIP_DATA_TABLES[@]}")
    fi
  fi

  local ignore_args=""
  for t in "${skip[@]}"; do
    ignore_args+=" --ignore-table=${remote_db}.${t}"
  done

  # mysqldump запускается внутри удалённого контейнера через docker exec — на
  # хосте mysql-client не установлен. Дамп идёт потоком через gzip, на диск
  # ничего не оседает.
  # --single-transaction — консистентный снимок без блокировок (InnoDB).
  # --no-tablespaces — чтобы не требовать PROCESS-привилегию.
  # --routines/--triggers — переносим хранимки и триггеры.
  # --column-statistics=0 — для совместимости MySQL 8.
  # sed вырезает DEFINER: иначе ERROR 1227, локальному пользователю не хватает
  # прав воссоздать объект от имени привилегированного владельца с прода.
  # -h 127.0.0.1 обязателен: пользователь на проде заведён как `user`@`%`, и
  # подключение через unix-сокет отдаёт ERROR 1045 для `user`@`localhost`.
  # TCP-подключение попадает под маску `%`.
  local base_opts="-h 127.0.0.1 --single-transaction --quick --no-tablespaces --column-statistics=0"

  # Проход 1: полная структура (включая таблицы, чьи данные пропускаем, —
  # иначе приложение упадёт на первом же запросе к ним).
  log "  … структура"
  ssh "$SSH_HOST" "docker exec -i ${remote_container} mysqldump \
      -u${ruser} -p${rpass} ${base_opts} \
      --no-data --routines --triggers ${remote_db} | gzip -c" \
    | gunzip -c \
    | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' \
    | docker exec -i "$container" mysql \
        -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" "$local_db"

  # Проход 2: данные, кроме пропускаемых таблиц.
  if (( ${#skip[@]} > 0 )); then
    log "  … данные (без ${#skip[@]} тяжёлых таблиц: ${skip[*]})"
  else
    log "  … данные (полностью)"
  fi
  ssh "$SSH_HOST" "docker exec -i ${remote_container} mysqldump \
      -u${ruser} -p${rpass} ${base_opts} \
      --no-create-info ${ignore_args} ${remote_db} | gzip -c" \
    | gunzip -c \
    | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' \
    | docker exec -i "$container" mysql \
        -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" "$local_db"

  local size
  size=$(docker exec "$container" mysql -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" -N \
    -e "SELECT ROUND(SUM(data_length+index_length)/1024/1024) FROM information_schema.tables WHERE table_schema='${local_db}';" 2>/dev/null)
  log "✓ ${remote_db} импортирована в ${container} (~${size} MB)"
}

# ─────────────────────────────────────────────────────────────
# Пост-обработка (только когда тащили основную БД)
# ─────────────────────────────────────────────────────────────
post_main() {
  log "Чищу кэши Laravel…"
  docker exec pecado-app php artisan optimize:clear || true

  if [[ "$SOURCE" == "prod" ]]; then
    log "Обнуляю токены интеграций в локальной копии…"
    # Боевые токены клиентских API и вебхуков в локальной базе не нужны и
    # опасны: случайный запрос уйдёт от имени боевой интеграции.
    docker exec pecado-mysql mysql -u"$LOCAL_DB_USER" -p"$LOCAL_DB_PASS" pecado -e "
      UPDATE personal_access_tokens SET token = SHA2(CONCAT(RAND(), id), 256);
    " 2>/dev/null || warn "Не удалось обнулить personal_access_tokens (таблицы может не быть)"
  fi

  if [[ "$REINDEX" -eq 1 ]]; then
    log "Переиндексация Meilisearch (Product)… это надолго и греет CPU"
    docker exec pecado-app php artisan scout:flush "App\\Models\\Product" || true
    docker exec pecado-app php artisan scout:import "App\\Models\\Product" || true
  else
    log "Переиндексация Meilisearch пропущена (добавьте --reindex, если нужна)"
  fi
}

# ─────────────────────────────────────────────────────────────
# Главная логика
# ─────────────────────────────────────────────────────────────
case "$TARGET" in
  all)
    pull_one "$RC_MAIN"   "$DB_MAIN"   "pecado-mysql"        "pecado"
    pull_one "$RC_PRICES" "$DB_PRICES" "pecado-mysql-prices" "pecado_prices" "$REMOTE_USER_PRICES" "$REMOTE_PASS_PRICES"
    post_main
    ;;
  main)
    pull_one "$RC_MAIN" "$DB_MAIN" "pecado-mysql" "pecado"
    post_main
    ;;
  prices)
    pull_one "$RC_PRICES" "$DB_PRICES" "pecado-mysql-prices" "pecado_prices" "$REMOTE_USER_PRICES" "$REMOTE_PASS_PRICES"
    ;;
esac

log "Готово. Источник: ${SOURCE}."
