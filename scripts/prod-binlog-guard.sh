#!/bin/bash
#
# Страховка по объёму бинлога основной БД.
#
# Зачем: в MySQL 8.0 нет жёсткого потолка на суммарный размер журнала —
# binlog_space_limit появился только в 8.4. Ретенция по времени (3 суток,
# docker/mysql/my.cnf) закрывает штатный режим, но не закрывает всплеск:
# массовый перелив каталога или остатков из 1С при binlog_format=ROW пишет
# в журнал каждую задетую строку и способен выдать гигабайты за час.
#
# Замер на проде 2026-07-16 (до введения ретенции): 5.55 GB журнала при 1.45 GB
# данных, рост ~240 MB/сутки. С ретенцией в 3 суток ожидаемый объём ~0.7 GB,
# поэтому потолок в 4 GB — это ~6x штатного запаса. Срабатывание = аномалия,
# о которой нужно знать, а не рутина.
#
# ВНИМАНИЕ: эталонная копия для ревью. Боевой скрипт — /srv/scripts/binlog-guard.sh,
# CI его туда НЕ доставляет (как и backup-db.sh). Правки копировать руками:
#   scp scripts/prod-binlog-guard.sh ladmin@93.94.150.16:/tmp/g.sh
#   ssh ladmin@93.94.150.16 'sudo cp /tmp/g.sh /srv/scripts/binlog-guard.sh && sudo chmod 755 /srv/scripts/binlog-guard.sh'
#
# Cron от ladmin каждые 15 минут, лог — /var/log/pecado-binlog-guard.log:
#   */15 * * * * /srv/scripts/binlog-guard.sh >> /var/log/pecado-binlog-guard.log 2>&1
set -euo pipefail

LIMIT_MB=4096          # потолок суммарного объёма журнала
KEEP_HOURS=6           # сколько часов журнала оставить при аварийной чистке

log() { echo "[$(date +%Y-%m-%dT%H:%M:%S%z)] $*"; }

cd /srv/pecado
DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
MYSQL="exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\""

SIZE_MB=$($DC exec -T mysql sh -c \
    "ls -l /var/lib/mysql/binlog.0* 2>/dev/null | awk '{s+=\$5} END {printf \"%d\", s/1048576}'")

# Пустой ответ = контейнер лежит или журнал не найден. Молчать нельзя:
# guard, который тихо ничего не делает, хуже отсутствующего.
if [ -z "$SIZE_MB" ]; then
    log "ERROR: не удалось измерить объём бинлога (контейнер mysql недоступен?)"
    exit 1
fi

if [ "$SIZE_MB" -lt "$LIMIT_MB" ]; then
    exit 0
fi

log "WARN: бинлог занял ${SIZE_MB} MB при потолке ${LIMIT_MB} MB — аварийная чистка"

# PURGE удаляет журналы старше указанного момента. Оставляем KEEP_HOURS:
# свежий журнал ещё может понадобиться для PITR, а место освобождает и он.
#
# Момент считаем через NOW() самой MySQL, а не через date(1) на хосте: хост живёт
# в +0300, контейнер — в UTC (@@system_time_zone = UTC), и подстановка хостового
# времени в строку молча срезала бы лишние три часа журнала.
$DC exec -T mysql sh -c "$MYSQL -e \"PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL ${KEEP_HOURS} HOUR)\""

AFTER_MB=$($DC exec -T mysql sh -c \
    "ls -l /var/lib/mysql/binlog.0* 2>/dev/null | awk '{s+=\$5} END {printf \"%d\", s/1048576}'")
log "Чистка выполнена: ${SIZE_MB} MB → ${AFTER_MB} MB (оставлено ${KEEP_HOURS}ч журнала)"

# Ненулевой код — чтобы cron прислал письмо: срабатывание guard'а означает,
# что что-то залило журнал ненормальным объёмом, и это стоит расследовать.
exit 1
