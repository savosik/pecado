#!/usr/bin/env bash
#
# Надзиратель за Docker: что зря занимает место и память.
#
#   scripts/docker-audit.sh          # отчёт
#   scripts/docker-audit.sh --clean  # отчёт + чистка безопасного мусора
#
# «Безопасный мусор» — build cache, образы без контейнеров, контейнеры
# старше 30 дней. Volumes НИКОГДА не чистятся автоматически: в них лежат
# базы, и отличить нужную от брошенной скрипт не может.

set -euo pipefail

PROJECT="${PECADO_PROJECT_PREFIX:-pecado}"
CLEAN=0
[[ "${1:-}" == "--clean" ]] && CLEAN=1

b()    { printf '\033[1m%s\033[0m\n' "$*"; }
head_() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
dim()  { printf '\033[2m%s\033[0m\n' "$*"; }

command -v docker >/dev/null || { echo "docker не найден"; exit 1; }

# ─────────────────────────────────────────────────────────────
# Место на диске
# ─────────────────────────────────────────────────────────────
head_ "Диск"
df -h / | awk 'NR==1 || /\//{printf "  %s\n", $0}'
echo
docker system df | sed 's/^/  /'

# ─────────────────────────────────────────────────────────────
# Кто сейчас ест ресурсы
# ─────────────────────────────────────────────────────────────
head_ "Запущенные контейнеры (по памяти)"
docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' \
  | sort -t$'\t' -k3 -h -r \
  | awk -F'\t' -v p="$PROJECT" '
      { tag = index($1, p) == 1 ? "" : "  ← чужой стек"
        printf "  %-24s %8s  %-22s%s\n", $1, $2, $3, tag }'

# ─────────────────────────────────────────────────────────────
# Чужие стеки — главный кандидат на выключение
# ─────────────────────────────────────────────────────────────
FOREIGN=$(docker ps --format '{{.Names}}' | grep -v "^${PROJECT}" || true)
if [[ -n "$FOREIGN" ]]; then
  COUNT=$(echo "$FOREIGN" | wc -l)
  head_ "Чужие стеки: $COUNT контейнеров"
  echo "$FOREIGN" | sed 's/^/  /' | paste -sd' ' - | fold -s -w 76 | sed 's/^/  /'
  dim "  → погасить всё кроме ${PROJECT}:  make focus"
fi

# ─────────────────────────────────────────────────────────────
# Мёртвые контейнеры
# ─────────────────────────────────────────────────────────────
head_ "Остановленные контейнеры"
DEAD=$(docker ps -a --filter status=exited --filter status=created --format '{{.Names}}\t{{.Status}}' || true)
if [[ -z "$DEAD" ]]; then
  echo "  нет"
else
  echo "$DEAD" | awk -F'\t' '{printf "  %-30s %s\n", $1, $2}'
  dim "  → docker container prune -f"
fi

# ─────────────────────────────────────────────────────────────
# Образы без контейнеров
# ─────────────────────────────────────────────────────────────
head_ "Образы без контейнеров"
# Контейнер может ссылаться на образ и как "repo", и как "repo:latest", и по
# ID — поэтому список используемых нормализуем: добавляем вариант без :latest.
USED=$(docker ps -a --format '{{.Image}}' | sed 's/:latest$//' | sort -u)
ORPHAN=0
while IFS=$'\t' read -r repo size id; do
  [[ -z "$repo" ]] && continue
  bare="${repo%:latest}"
  if ! grep -qxF "$bare" <<<"$USED" && ! grep -qF "${id:7:12}" <<<"$USED"; then
    printf "  %-10s %s\n" "$size" "$repo"
    ORPHAN=1
  fi
done < <(docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}\t{{.ID}}' | grep -v '^<none>')
[[ "$ORPHAN" -eq 0 ]] && echo "  нет"
[[ "$ORPHAN" -eq 1 ]] && dim "  → docker image prune -af"

# ─────────────────────────────────────────────────────────────
# Volumes — только показываем, не трогаем
# ─────────────────────────────────────────────────────────────
head_ "Volumes без контейнеров"
DANGLING=$(docker volume ls -qf dangling=true || true)
if [[ -z "$DANGLING" ]]; then
  echo "  нет"
else
  echo "$DANGLING" | while read -r v; do
    created=$(docker volume inspect "$v" --format '{{.CreatedAt}}' 2>/dev/null | cut -dT -f1)
    printf "  %-12s %s\n" "${created:-?}" "$v"
  done
  echo
  dim "  ⚠ Здесь могут лежать базы старых проектов. Автоматически НЕ чистятся."
  dim "    Размеры:  docker run --rm -v /var/lib/docker/volumes:/v:ro alpine du -sh /v/*/_data"
  dim "    Удалить:  docker volume prune -f"
fi

# ─────────────────────────────────────────────────────────────
# Чистка
# ─────────────────────────────────────────────────────────────
if [[ "$CLEAN" -eq 1 ]]; then
  head_ "Чистка безопасного мусора"
  docker container prune -f --filter until=720h 2>&1 | tail -1 | sed 's/^/  контейнеры: /'
  docker image     prune -af --filter until=720h 2>&1 | tail -1 | sed 's/^/  образы:     /'
  docker builder   prune -af --filter until=168h 2>&1 | tail -1 | sed 's/^/  build cache:/'
  echo
  df -h / | tail -1 | sed 's/^/  /'
fi

echo
