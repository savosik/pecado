#!/usr/bin/env bash
#
# Оставляет запущенным только один стек, остальные гасит.
#
#   scripts/focus.sh              # оставить pecado, погасить остальные
#   scripts/focus.sh abcurd       # оставить abcurd
#   scripts/focus.sh --restore    # поднять обратно то, что гасили в прошлый раз
#   scripts/focus.sh --list       # показать, что сейчас запущено
#
# Это замена «чистильщикам оперативки»: реальную память освобождает не
# сброс кэша, а остановка того, что не нужно прямо сейчас.
#
# Погашенные контейнеры запоминаются, чтобы --restore поднял ровно их.

set -euo pipefail

STATE_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/pecado"
STATE_FILE="$STATE_DIR/focus-stopped"
mkdir -p "$STATE_DIR"

ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
info() { printf '\033[1;34m▶\033[0m %s\n' "$*"; }
dim()  { printf '\033[2m%s\033[0m\n' "$*"; }

mem_free() { awk '/MemAvailable/ {printf "%.1f GiB", $2/1024/1024}' /proc/meminfo; }

case "${1:-}" in
  --list)
    info "Запущено сейчас:"
    docker ps --format '{{.Names}}' | sed 's/-[^-]*$//;s/_[^_]*$//' | sort | uniq -c \
      | awk '{printf "  %2d  %s\n", $1, $2}'
    echo
    dim "  Свободно памяти: $(mem_free)"
    exit 0
    ;;

  --restore)
    if [[ ! -s "$STATE_FILE" ]]; then
      echo "Нечего восстанавливать — список пуст."
      exit 0
    fi
    info "Поднимаю обратно:"
    while read -r c; do
      [[ -z "$c" ]] && continue
      if docker start "$c" >/dev/null 2>&1; then
        ok "$c"
      else
        printf '  ✗ %s (не удалось — возможно, удалён)\n' "$c"
      fi
    done < "$STATE_FILE"
    : > "$STATE_FILE"
    echo
    dim "  Свободно памяти: $(mem_free)"
    exit 0
    ;;
esac

KEEP="${1:-pecado}"

MEM_BEFORE=$(mem_free)

# Контейнеры не из целевого стека. Префикс определяем и по дефису, и по
# подчёркиванию — разные проекты именуют по-разному (pecado-app, laravel_app).
VICTIMS=$(docker ps --format '{{.Names}}' | grep -vE "^${KEEP}[-_]?" || true)

if [[ -z "$VICTIMS" ]]; then
  info "Кроме стека «${KEEP}» ничего не запущено."
  dim "  Свободно памяти: $MEM_BEFORE"
  exit 0
fi

COUNT=$(echo "$VICTIMS" | wc -l)
info "Гашу $COUNT контейнеров (оставляю «${KEEP}»)"

echo "$VICTIMS" > "$STATE_FILE"

# shellcheck disable=SC2086
docker stop $(echo "$VICTIMS" | paste -sd' ' -) >/dev/null

echo "$VICTIMS" | sed 's/^/  ✓ /'

echo
dim "  Память: $MEM_BEFORE → $(mem_free)"
dim "  Вернуть обратно: scripts/focus.sh --restore"
