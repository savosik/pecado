#!/usr/bin/env bash
#
# Настройка локальной машины под быстрый цикл разработки Pecado.
#
#   sudo scripts/local-tune.sh
#
# Скрипт идемпотентный: повторный запуск ничего не ломает. Каждый изменённый
# системный файл сохраняется рядом с суффиксом .bak-ГГГГММДД-ЧЧММСС.
#
# Что делает:
#   1. Ротация docker-логов (сейчас её нет вообще) + cgroup-parent=docker.slice
#   2. Сжимает /swapfile2 с 12 GB до 4 GB — освобождает 8 GB на корне
#   3. Гасит thermald (это Intel-демон, на Ryzen 4600H бесполезен)
#   4. Создаёт docker.slice: потолок 6 из 12 потоков на ВСЕ контейнеры
#   5. vm.swappiness 100 → 60
#   6. Убирает mysqld из earlyoom --prefer (убийство БД посреди записи хуже свопа)
#   7. Отключает службы автозапуска, согласованные с пользователем
#
# Файл локальный, в .gitignore — на dev и prod не уезжает.

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Запускать через sudo: sudo $0" >&2
  exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
CHANGED=0
# Корень проекта — от расположения скрипта, а не хардкодом: под sudo
# домашний каталог другой.
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

c_ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
c_skip() { printf '\033[1;33m  ·\033[0m %s\n' "$*"; }
c_head() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }

backup() {
  [[ -f "$1" ]] && cp -a "$1" "$1.bak-$STAMP" && c_ok "бэкап: $1.bak-$STAMP"
  return 0
}

# ─────────────────────────────────────────────────────────────
# 1. Docker: ротация логов + cgroup-parent
# ─────────────────────────────────────────────────────────────
c_head "1/7  Docker: ротация логов и cgroup-parent"

DOCKER_JSON=/etc/docker/daemon.json
NEED_DOCKER_RESTART=0

if [[ -f "$DOCKER_JSON" ]] && grep -q '"cgroup-parent"' "$DOCKER_JSON" 2>/dev/null; then
  c_skip "daemon.json уже настроен"
else
  backup "$DOCKER_JSON"
  mkdir -p /etc/docker
  cat > "$DOCKER_JSON" <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "cgroup-parent": "docker.slice"
}
JSON
  c_ok "создан $DOCKER_JSON (логи 10m×3, cgroup-parent=docker.slice)"
  NEED_DOCKER_RESTART=1
  CHANGED=1
fi

# ─────────────────────────────────────────────────────────────
# 2. Своп: /swapfile2 12 GB → 4 GB
# ─────────────────────────────────────────────────────────────
c_head "2/7  Своп: сжатие /swapfile2 до 4 GB"

SWAP2=/swapfile2
CUR_SIZE=$(stat -c%s "$SWAP2" 2>/dev/null || echo 0)
TARGET=$((4 * 1024 * 1024 * 1024))

if [[ "$CUR_SIZE" -le "$TARGET" ]]; then
  c_skip "/swapfile2 уже $(numfmt --to=iec "$CUR_SIZE") — не трогаем"
else
  # Проверяем, что памяти хватит принять то, что сейчас лежит в этом свопе.
  USED_KB=$(awk -v f="$SWAP2" '$1==f {print $4}' /proc/swaps 2>/dev/null || echo 0)
  AVAIL_KB=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
  if (( USED_KB > AVAIL_KB )); then
    c_skip "ПРОПУСК: в /swapfile2 лежит $((USED_KB/1024)) MB, а свободно только $((AVAIL_KB/1024)) MB."
    c_skip "         Закройте Chrome/VS Code и запустите скрипт повторно."
  else
    swapoff "$SWAP2"
    rm -f "$SWAP2"
    fallocate -l 4G "$SWAP2"
    chmod 600 "$SWAP2"
    mkswap "$SWAP2" >/dev/null
    swapon "$SWAP2"
    c_ok "/swapfile2: 12 GB → 4 GB (освобождено 8 GB)"
    CHANGED=1
  fi
fi

# ─────────────────────────────────────────────────────────────
# 3. thermald — Intel-демон на AMD
# ─────────────────────────────────────────────────────────────
c_head "3/7  thermald"

if systemctl is-enabled thermald &>/dev/null; then
  systemctl disable --now thermald
  c_ok "thermald отключён (Intel-демон, на Ryzen не применим)"
  CHANGED=1
else
  c_skip "thermald уже отключён"
fi

# ─────────────────────────────────────────────────────────────
# 4. docker.slice — глобальный потолок на все контейнеры
# ─────────────────────────────────────────────────────────────
c_head "4/7  docker.slice: потолок 6 из 12 потоков"

SLICE=/etc/systemd/system/docker.slice
if [[ -f "$SLICE" ]] && grep -q "CPUQuota=600%" "$SLICE" 2>/dev/null; then
  c_skip "docker.slice уже настроен"
else
  backup "$SLICE"
  cat > "$SLICE" <<'UNIT'
# Потолок для ВСЕХ docker-контейнеров (включая стеки чужих проектов).
#
# Зачем: лимиты в docker-compose.override.yml покрывают только Pecado, а
# abcurd/hcsimple/laravel не ограничены ничем. Без общего потолка тяжёлая
# сборка забирает все 12 потоков, машина уходит в троттлинг, курсор залипает.
#
# CPUQuota=600% — шесть из двенадцати потоков. Остальные шесть гарантированно
# остаются рабочему столу. CPUWeight=50 (против 100 по умолчанию) отдаёт
# приоритет десктопу при конкуренции.
#
# MemoryHigh без MemoryMax — мягкое давление: ядро начинает выдавливать
# страницы, но не убивает процесс. Жёсткий MemoryMax на общий slice убил бы
# MySQL посреди записи.

[Unit]
Description=Docker containers slice (ограничение ресурсов)
Before=slices.target

[Slice]
CPUQuota=600%
CPUWeight=50
MemoryHigh=8G
IOWeight=50
UNIT
  systemctl daemon-reload
  systemctl start docker.slice
  c_ok "создан docker.slice (CPUQuota=600%, MemoryHigh=8G)"
  NEED_DOCKER_RESTART=1
  CHANGED=1
fi

# ─────────────────────────────────────────────────────────────
# 5. vm.swappiness 100 → 60
# ─────────────────────────────────────────────────────────────
c_head "5/7  vm.swappiness"

ZRAM_CONF=/etc/sysctl.d/99-zram-desktop.conf
if grep -q "^vm.swappiness = 60" "$ZRAM_CONF" 2>/dev/null; then
  c_skip "swappiness уже 60"
else
  backup "$ZRAM_CONF"
  sed -i 's/^vm.swappiness = .*/vm.swappiness = 60/' "$ZRAM_CONF"
  sysctl -q -w vm.swappiness=60
  c_ok "vm.swappiness: 100 → 60 (zram забит на 95%, дисковый своп добавляет задержек)"
  CHANGED=1
fi

# ─────────────────────────────────────────────────────────────
# 6. earlyoom: не убивать mysqld
# ─────────────────────────────────────────────────────────────
c_head "6/7  earlyoom"

EARLYOOM=/etc/default/earlyoom
if grep -q "mysqld" "$EARLYOOM" 2>/dev/null; then
  backup "$EARLYOOM"
  sed -i 's/|mysqld)\$/)$/' "$EARLYOOM"
  systemctl restart earlyoom
  c_ok "mysqld убран из --prefer (убийство БД посреди записи хуже свопа)"
  CHANGED=1
else
  c_skip "earlyoom уже без mysqld"
fi

# ─────────────────────────────────────────────────────────────
# 7. Автозапуск: удалённый доступ и мелкий хлам
# ─────────────────────────────────────────────────────────────
c_head "7/7  Службы автозапуска"

# Согласовано с пользователем. Печать (cups/avahi) и Bluetooth НЕ трогаем.
for svc in anydesk.service gnome-remote-desktop.service kerneloops.service ubuntu-fan.service; do
  if systemctl is-enabled "$svc" &>/dev/null; then
    systemctl disable --now "$svc" 2>/dev/null || true
    c_ok "отключён $svc"
    CHANGED=1
  else
    c_skip "$svc уже отключён"
  fi
done

# ─────────────────────────────────────────────────────────────
# Итог
# ─────────────────────────────────────────────────────────────
if [[ "$NEED_DOCKER_RESTART" -eq 1 ]]; then
  c_head "Требуется перезапуск Docker"
  echo "  daemon.json изменён. Контейнеры переедут в docker.slice только после"
  echo "  ПЕРЕСОЗДАНИЯ (перезапуска мало — cgroup задаётся при создании)."
  echo
  echo "  Выполните от своего пользователя (не под sudo):"
  echo
  echo "      sudo systemctl restart docker"
  echo "      cd $PROJECT_ROOT && docker compose up -d --force-recreate"
  echo
  echo "  Данные в volumes сохранятся. Стеки других проектов переедут в slice"
  echo "  сами при следующем их запуске."
fi

c_head "Готово"
if [[ "$CHANGED" -eq 0 ]]; then
  echo "  Изменений не потребовалось — система уже настроена."
else
  echo "  Проверка результата:"
  echo "      systemctl show docker.slice -p CPUQuota,MemoryHigh"
  echo "      sysctl vm.swappiness"
  echo "      free -h && df -h /"
fi
