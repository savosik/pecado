#!/usr/bin/env bash
#
# Запускает тяжёлую команду, временно приглушив фоновые контейнеры.
#
#   scripts/with-cap.sh <команда…>
#
# Зачем не systemd-run: обёртка `systemd-run --scope … docker exec …` ограничила
# бы только процесс docker-клиента, а работа идёт внутри контейнера — в своей
# cgroup под dockerd. Единственный способ ограничить контейнер без root —
# `docker update`, он меняет лимиты на лету.
#
# Общий потолок на ВСЕ контейнеры задаётся отдельно, через docker.slice
# (см. scripts/local-tune.sh, требует sudo). Этот скрипт — то, что можно
# сделать без root: на время прогона отдать процессорное время тому
# контейнеру, который реально работает.
#
# Лимиты возвращаются обратно даже при Ctrl+C или падении команды.

set -uo pipefail

[[ $# -gt 0 ]] || { echo "Использование: $0 <команда…>" >&2; exit 2; }

# Кого приглушаем на время работы и до скольких ядер.
# app не трогаем — именно он выполняет тесты и сборку.
declare -A THROTTLE=(
  [pecado-worker]=1
  [pecado-meilisearch]=1
  [pecado-mysql]=1
)

declare -A SAVED=()

restore() {
  local c
  for c in "${!SAVED[@]}"; do
    docker update --cpus="${SAVED[$c]}" "$c" >/dev/null 2>&1 || true
  done
}
trap restore EXIT INT TERM

for c in "${!THROTTLE[@]}"; do
  docker ps --format '{{.Names}}' | grep -qx "$c" || continue
  nano=$(docker inspect "$c" --format '{{.HostConfig.NanoCpus}}' 2>/dev/null || echo 0)
  [[ "$nano" -gt 0 ]] || continue
  # LC_ALL=C обязателен: в русской локали awk напечатает "2,00" с запятой,
  # и docker update такое значение отвергнет — лимиты не вернутся на место.
  SAVED[$c]=$(LC_ALL=C awk -v n="$nano" 'BEGIN{printf "%.2f", n/1000000000}')
  docker update --cpus="${THROTTLE[$c]}" "$c" >/dev/null 2>&1 || true
done

"$@"
