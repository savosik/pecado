#!/bin/bash
#
# Ежедневный бэкап обеих БД prod:
#   • локально — /media/backups/mysql/daily/ (отдельный диск /dev/sdb, чтобы не забивать системный sda2)
#   • в облако — Yandex Object Storage, бакет pecado-backup (класс ICE)
#
# Помимо дампов забирает бинлог основной БД — это даёт point-in-time recovery:
# дамп в 03:00 + накат журнала до нужной секунды. Ретенция журнала (3 суток)
# и его настройки — docker/mysql/my.cnf; страховка по объёму — binlog-guard.sh.
# Восстановление на момент T:
#   zcat pecado_<дата>.sql.gz | mysql pecado          # шапка дампа содержит координаты
#   tar xzf binlog_<дата>.tar.gz
#   mysqlbinlog --start-position=<POS> --stop-datetime="T" binlog.0* | mysql pecado
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
BINLOG="$BACKUP_DIR/binlog_${DATE}.tar.gz"

# --- 1. Дампы ---
# --source-data=2 дописывает в шапку дампа комментарий с координатами бинлога:
#   -- CHANGE MASTER TO MASTER_LOG_FILE='binlog.000098', MASTER_LOG_POS=1949;
# Без них PITR невозможен в принципе — непонятно, с какого места накатывать журнал.
$DC exec -T mysql sh -c "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --quick --source-data=2 pecado" \
  | gzip > "$MAIN"

# База цен восстанавливается из 1С, бинлог там сознательно отключён (skip-log-bin
# в docker/mysql-prices/my.cnf) — PITR ей не нужен и технически невозможен.
$DC exec -T mysql-prices sh -c "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --quick pecado_prices" \
  | gzip > "$PRICES"

# --- 1b. Бинлог основной БД (PITR) ---
# FLUSH BINARY LOGS закрывает активный журнал и начинает новый: в архив попадают
# только дописанные до конца файлы, без гонки с идущей записью. Активный (самый
# свежий после flush) исключаем — его заберёт следующий запуск.
# Забираем всё, что лежит на диске: ретенция (3 суток, docker/mysql/my.cnf) уже
# ограничивает объём, а перекрытие между ночами делает цепочку журналов
# непрерывной даже если один запуск пропущен.
$DC exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'FLUSH BINARY LOGS'"
ACTIVE=$($DC exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -e 'SHOW MASTER STATUS'" | awk '{print $1}')
if [ -z "$ACTIVE" ]; then
    log "ERROR: не удалось определить активный бинлог — архив журнала не снимаем."
    exit 1
fi
$DC exec -T mysql sh -c "cd /var/lib/mysql && tar cf - \$(ls binlog.0* | grep -vx '$ACTIVE')" \
  | gzip > "$BINLOG"

# --- 2. Проверка целостности ---
# Битый архив в облаке хуже, чем его отсутствие: создаёт ложное чувство защиты.
for f in "$MAIN" "$PRICES" "$BINLOG"; do
    if ! gzip -t "$f" 2>/dev/null; then
        log "ERROR: архив повреждён — $f. В облако не отправляем."
        exit 1
    fi
done
log "Дампы сняты и проверены: $(du -h "$MAIN" | cut -f1) + $(du -h "$PRICES" | cut -f1) + бинлог $(du -h "$BINLOG" | cut -f1)"

# --- 3. Отгрузка в Yandex Object Storage ---
# Недоступность облака не должна ронять локальный бэкап — логируем и идём дальше.
upload_ok=true
for f in "$MAIN" "$PRICES" "$BINLOG"; do
    if $RCLONE copy "${RCLONE_OPTS[@]}" "$f" "$REMOTE/daily/"; then
        log "→ S3 daily/$(basename "$f") OK"
    else
        log "ERROR: не удалось отгрузить $(basename "$f") в S3"
        upload_ok=false
    fi
done

# 1-го числа дублируем снимок в monthly/ — история на случай, когда порча
# данных обнаруживается позже, чем через 30 дней.
# Бинлог в monthly не кладём осознанно: PITR от годовалого дампа потребовал бы
# непрерывной цепочки журналов за весь год, а мы держим трое суток. Для месячных
# снимков осмыслено только состояние на момент дампа.
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
# Архивы бинлога — 30 дней, синхронно с дампами основной БД: журнал старше
# самого древнего дампа для восстановления бесполезен.
find "$BACKUP_DIR" -type f -name "binlog_*.tar.gz" -mtime +30 -delete

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
