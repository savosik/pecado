#!/usr/bin/env bash
#
# Релиз: локальная верификация → dev → прод, с ожиданием результата.
#
#   make release              # полный цикл с подтверждениями
#   make release SKIP_VERIFY=1  # если verify уже прогнан вручную
#
# Зачем скрипт, а не два git push:
#   1. Прод-CI гоняет тесты БЕЗ fast-lane — падение там дороже, чем на dev.
#   2. Упавший прод-деплой оставляет pecado.ru под maintenance (503) до
#      ручного `php artisan up`. Скрипт не завершится, пока не убедится,
#      что сайт отвечает 200 — и прямо скажет, что делать, если нет.
#
# Схема веток соло-flow: dev — рабочая, main — релизная, PR нет.
# См. docs/SOLO_RELEASE_FLOW.md.

set -uo pipefail

cd "$(git rev-parse --show-toplevel)"

RED=$'\033[1;31m'; GRN=$'\033[1;32m'; YEL=$'\033[1;33m'; BLU=$'\033[1;34m'
DIM=$'\033[2m'; RST=$'\033[0m'

step() { printf '\n%s▶ %s%s\n' "$BLU" "$*" "$RST"; }
ok()   { printf '%s✓%s %s\n' "$GRN" "$RST" "$*"; }
warn() { printf '%s!%s %s\n' "$YEL" "$RST" "$*"; }
die()  { printf '\n%s✗ %s%s\n' "$RED" "$*" "$RST"; exit 1; }

ask() {
  local prompt="$1"
  read -r -p "$prompt [y/N] " a
  [[ "$a" =~ ^[yYдД]$ ]]
}

command -v gh >/dev/null || die "Нужен gh CLI (для ожидания результатов CI)."

# ─────────────────────────────────────────────────────────────
# 0. Предполётные проверки
# ─────────────────────────────────────────────────────────────
step "Предполётные проверки"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
[[ "$BRANCH" == "dev" ]] || die "Релиз идёт только из ветки dev (сейчас: $BRANCH)."

if [[ -n "$(git status --porcelain)" ]]; then
  git status --short
  die "Рабочее дерево грязное — закоммитьте или спрячьте изменения."
fi

git fetch origin --quiet
BEHIND=$(git rev-list --count HEAD..origin/dev 2>/dev/null || echo 0)
[[ "$BEHIND" -eq 0 ]] || die "Локальная dev отстаёт от origin/dev на $BEHIND коммит(ов). Сначала git pull."

AHEAD=$(git rev-list --count origin/dev..HEAD 2>/dev/null || echo 0)
if [[ "$AHEAD" -eq 0 ]]; then
  warn "Нечего пушить в dev — origin/dev уже совпадает с локальной."
else
  ok "К отправке: $AHEAD коммит(ов)"
  git log --oneline origin/dev..HEAD | sed 's/^/    /'
fi

MAIN_DIFF=$(git rev-list --count origin/main..HEAD 2>/dev/null || echo 0)
ok "Прод отстаёт от текущей ветки на $MAIN_DIFF коммит(ов)"

# ─────────────────────────────────────────────────────────────
# 1. Локальная верификация
# ─────────────────────────────────────────────────────────────
if [[ "${SKIP_VERIFY:-0}" == "1" ]]; then
  warn "Верификация пропущена (SKIP_VERIFY=1)"
else
  step "Локальная верификация (make verify)"
  make --no-print-directory verify || die "Верификация не прошла. Релиз остановлен."
fi

# ─────────────────────────────────────────────────────────────
# 2. Push в dev
# ─────────────────────────────────────────────────────────────
if [[ "$AHEAD" -gt 0 ]]; then
  step "Отправка в dev"
  ask "Пушим $AHEAD коммит(ов) в origin/dev?" || die "Отменено."
  git push origin dev || die "Push в dev не прошёл."
  ok "Отправлено"

  step "Жду deploy-dev"
  sleep 8   # даём GitHub время зарегистрировать запуск
  if ! gh run watch --exit-status "$(gh run list --branch dev --limit 1 --json databaseId --jq '.[0].databaseId')"; then
    die "deploy-dev упал. Прод не трогаем. Логи: gh run view --log-failed"
  fi
  ok "dev задеплоен"
fi

# ─────────────────────────────────────────────────────────────
# 3. Прод
# ─────────────────────────────────────────────────────────────
if [[ "$MAIN_DIFF" -eq 0 ]]; then
  ok "Прод уже на этой версии — релиз не нужен."
  exit 0
fi

step "Релиз на ПРОД (pecado.ru)"
printf '  Уедет %s коммит(ов):\n' "$MAIN_DIFF"
git log --oneline origin/main..HEAD | sed 's/^/    /'
echo
ask "Выкатываем на боевой сайт?" || { warn "Прод-релиз отменён. dev уже обновлён."; exit 0; }

git push origin dev:main || die "Push в main не прошёл."
ok "Отправлено в main"

step "Жду deploy-prod (обычно 4-5 минут)"
sleep 8
PROD_RUN=$(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
PROD_OK=1
gh run watch --exit-status "$PROD_RUN" || PROD_OK=0

# ─────────────────────────────────────────────────────────────
# 4. Проверка, что сайт действительно поднялся
# ─────────────────────────────────────────────────────────────
# Даже при зелёном workflow сайт может остаться под maintenance — поэтому
# проверяем HTTP, а не только статус CI.
step "Проверяю, что pecado.ru отвечает"

for i in {1..12}; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://pecado.ru/up || echo 000)
  if [[ "$CODE" == "200" ]]; then
    ok "pecado.ru/up → 200. Релиз завершён."
    exit 0
  fi
  printf '  попытка %2d/12: HTTP %s\n' "$i" "$CODE"
  sleep 10
done

echo
if [[ "$PROD_OK" -eq 0 ]]; then
  printf '%s✗ Прод-деплой упал И сайт не отвечает.%s\n' "$RED" "$RST"
else
  printf '%s✗ Workflow зелёный, но сайт не отдаёт 200.%s\n' "$RED" "$RST"
fi
cat <<TXT

  Скорее всего сайт остался под maintenance. Снять вручную:

      ssh ladmin@93.94.150.16 "cd /srv/pecado && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan up"

  Из офиса (LAN) тот же адрес — 10.2.2.101.

  503 с заголовком 'x-powered-by: PHP' — контейнеры живы, дело в artisan down.
  503 без него — упал сам контейнер, смотреть 'docker compose ps' на сервере.

  Логи деплоя:  gh run view $PROD_RUN --log-failed
TXT
exit 1
