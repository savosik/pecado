#!/bin/bash
#
# Ежедневный бэкап обеих БД prod:
#   • локально — /media/backups/mysql/daily/ (отдельный диск /dev/sdb, чтобы не забивать системный sda2)
#   • в облако — Yandex Object Storage, бакет pecado-backup (класс ICE)
#
# ВНИМАНИЕ: это эталонная копия для версионирования и ревью. Боевой скрипт лежит
# на prod-сервере в /srv/scripts/backup-db.sh — вне /srv/pecado, поэтому rsync --delete
# при деплое его не трогает и CI сюда НЕ доставляет. Правки нужно копировать руками:
#   scp scripts/prod-backup-db.sh ladmin@93.94.150.16:/tmp/b.sh
#   ssh ladmin@93.94.150.16 'sudo cp /tmp/b.sh /srv/scripts/backup-db.sh && sudo chmod 755 /srv/scripts/backup-db.sh'
#
# Запуск на сервере: cron от ladmin в 03:00, лог — /var/log/pecado-backup.log
# Креды S3 и конфиг rclone — docs/PROD_SERVER_CREDENTIALS.md §15.1
# Схема бэкапов и восстановление — docs/CICD_PROD_DEPLOYMENT.md §6 шаг 5
set -euo pipefail

BACKUP_DIR="/media/backups/mysql/daily"
DATE=$(date +%Y-%m-%d_%H%M)
DAY_OF_MONTH=$(date +%d)

RCLONE="/srv/scripts/bin/rclone"
REMOTE="yandex:pecado-backup"
# Крупные чанки — меньше операций к холодному хранилищу, они там платные.
RCLONE_OPTS=(--config /home/ladmin/.config/rclone/rclone.conf
             --s3-chunk-size 64M --s3-upload-concurrency 2
             --retries 3 --stats 0)

log() { echo "[$(date +%Y-%m-%dT%H:%M:%S%z)] $*"; }

mkdir -p "$BACKUP_DIR"
cd /srv/pecado
DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

MAIN="$BACKUP_DIR/pecado_${DATE}.sql.gz"
PRICES="$BACKUP_DIR/pecado_prices_${DATE}.sql.gz"

# --- 1. Дампы ---
$DC exec -T mysql sh -c "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --quick pecado" \
  | gzip > "$MAIN"

$DC exec -T mysql-prices sh -c "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --quick pecado_prices" \
  | gzip > "$PRICES"

# --- 2. Проверка целостности ---
# Битый архив в облаке хуже, чем его отсутствие: создаёт ложное чувство защиты.
for f in "$MAIN" "$PRICES"; do
    if ! gzip -t "$f" 2>/dev/null; then
        log "ERROR: архив повреждён — $f. В облако не отправляем."
        exit 1
    fi
done
log "Дампы сняты и проверены: $(du -h "$MAIN" | cut -f1) + $(du -h "$PRICES" | cut -f1)"

# --- 3. Отгрузка в Yandex Object Storage ---
# Недоступность облака не должна ронять локальный бэкап — логируем и идём дальше.
upload_ok=true
for f in "$MAIN" "$PRICES"; do
    if $RCLONE copy "${RCLONE_OPTS[@]}" "$f" "$REMOTE/daily/"; then
        log "→ S3 daily/$(basename "$f") OK"
    else
        log "ERROR: не удалось отгрузить $(basename "$f") в S3"
        upload_ok=false
    fi
done

# 1-го числа дублируем снимок в monthly/ — история на случай, когда порча
# данных обнаруживается позже, чем через 30 дней.
if [ "$DAY_OF_MONTH" = "01" ]; then
    for f in "$MAIN" "$PRICES"; do
        $RCLONE copy "${RCLONE_OPTS[@]}" "$f" "$REMOTE/monthly/" \
            && log "→ S3 monthly/$(basename "$f") OK" \
            || log "ERROR: не удалось отгрузить $(basename "$f") в monthly/"
    done
fi

# --- 4. Retention ---
# Локально: основная БД — 30 дней, БД цен — 5 последних архивов (таблицы большие).
find "$BACKUP_DIR" -type f -name "pecado_*.sql.gz" ! -name "pecado_prices_*" -mtime +30 -delete
ls -1t "$BACKUP_DIR"/pecado_prices_*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f

# В облаке: 30 дней совпадает с минимальным сроком хранения класса ICE —
# удалять раньше бессмысленно, оплата всё равно списывается за 30 суток.
# Чистим только если сегодняшняя загрузка прошла: иначе рискуем срезать
# историю, не получив взамен свежую копию.
if [ "$upload_ok" = true ]; then
    $RCLONE delete "${RCLONE_OPTS[@]}" --min-age 30d "$REMOTE/daily/"    || log "WARN: retention daily/ не отработал"
    $RCLONE delete "${RCLONE_OPTS[@]}" --min-age 365d "$REMOTE/monthly/" || log "WARN: retention monthly/ не отработал"
fi

if [ "$upload_ok" = true ]; then
    log "Backup OK: ${DATE} (локально + S3)"
else
    log "Backup PARTIAL: ${DATE} (локально OK, S3 с ошибками)"
    exit 1
fi
