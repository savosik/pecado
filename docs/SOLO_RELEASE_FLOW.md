# Soло-flow: как выкатывать изменения на prod (без PR)

> **Аудитория:** разработчик, работающий один.
> **Контекст:** PR-flow для соло избыточен. Прод защищён через GitHub Environment `production` (manual approve) + ruleset (block force-push). Этого достаточно — случайно ничего не задеплоится.
> **Связанные документы:** [PROD_WORKFLOW.md](./PROD_WORKFLOW.md), [CICD_PROD_DEPLOYMENT.md](./CICD_PROD_DEPLOYMENT.md), [CICD_DEV_DEPLOYMENT.md](./CICD_DEV_DEPLOYMENT.md).

---

## Регулярный цикл (4 строки)

```bash
# 1. Утром / с новой машины — догнать свежий dev
git pull

# 2. Кодишь → проверяешь локально http://loc.pecado.ru (Vite HMR)

# 3. Коммит и push в dev
git add <конкретные файлы>
git commit -m "feat(scope): что сделал"
git push origin dev

# 4. Когда готов к проду — релиз одной строкой:
git push origin dev:main
```

После шага 4 → CI на main стартует. См. ниже.

---

## После `git push origin dev:main`

1. Открой https://github.com/savosik/pecado/actions
2. Найди свежий run **«Deploy to Production»**
3. Жди до **approve gate** (~2–3 мин с `[fast]`, ~5–7 мин без)
4. Жми **«Start all waiting jobs»** → подтверди deploy в `production`
5. Ещё ~5 мин — прод обновлён, проверяй на https://pecado.ru

Total: **~10–15 мин** от push до прода.

---

## Конвенция коммитов

Conventional Commits — обязательно:

| Тип | Когда |
|---|---|
| `feat(scope):` | новая фича |
| `fix(scope):` | багфикс |
| `chore(scope):` | служебные правки (gitignore, версии deps, etc) |
| `docs(scope):` | документация |
| `refactor(scope):` | рефакторинг без изменения поведения |
| `perf(scope):` | оптимизация |
| `ci(scope):` | правки workflow / runner / .github/* |
| `test(scope):` | добавление/правка тестов |

Скоупы: `cart`, `cabinet`, `exports`, `erp`, `kanban-api`, `prod-infra`, `footer`, и т.д. — что больше всего изменилось.

`[fast]` в конце сообщения пропускает тесты **только на dev** (deploy-dev.yml). На проде (deploy-prod.yml) тесты гоняются **всегда**.

```
✅ feat(cart): кнопка сканера штрихкода в пустом состоянии корзины [fast]
✅ fix(cabinet): единое московское время на странице заказа
✅ chore(footer): bump version v0.1.0 → v0.1.1 [fast]

❌ added new feature                           # не conventional
❌ wip                                         # debug-коммит, не для main
❌ feat: исправил баг                          # mismatch type
```

---

## Что защищает прод

| Защита | Где | Что делает |
|---|---|---|
| **GitHub Environment `production`** | Settings → Environments | Требует ручной approve перед каждым деплоем |
| **Ruleset «main: block force-push & deletion»** | Settings → Rules | Запрещает `git push --force` в main, запрещает удаление ветки |
| **CI test jobs** | `.github/workflows/deploy-prod.yml` | Деплой не идёт без зелёных Lint & Tests + Build Frontend |
| **Pre-deploy backup БД** | в workflow | snapshot main + prices DBs в `/media/backups/mysql/pre-deploy/` (отдельный диск `sdb`) перед каждым деплоем; retention: основная БД — 10 последних, цены — 5 последних |
| **Maintenance mode** | в workflow | `php artisan down` перед миграциями, `up` после |
| **Health check** | в workflow | `curl https://pecado.ru/up` через loopback (обход hairpin NAT). Если упало — workflow красный |

---

## Откат на проде (если v0.X.Y поломала что-то)

### Сценарий A: код виноват

```bash
# В ветке dev (мы всегда там сидим)
git revert HEAD              # создаст НОВЫЙ коммит, отменяющий последний
git push origin dev          # CI на dev (быстро, fast-lane по [fast] если только code-revert)
git push origin dev:main     # CI на main → approve → откат на проде
```

`git revert` — это **не** force-push, а обычный новый коммит сверху. История чистая, ruleset не возражает.

### Сценарий B: миграция испортила БД

1. SSH на prod: `ssh ladmin@93.94.150.16`
2. `cd /srv/pecado && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan down`
3. Восстановить БД из `/media/backups/mysql/pre-deploy/<timestamp>.sql.gz` (см. [CICD_PROD_DEPLOYMENT.md §10](./CICD_PROD_DEPLOYMENT.md))
4. Revert миграции: `git revert <SHA-коммита-с-миграцией>` → push → деплой
5. После того как новый деплой пройдёт: `php artisan up`

### Сценарий C: критичная ошибка, нужен hotfix без полного релиза

```bash
git checkout main           # переключаемся на main (создастся локально из origin/main)
git pull
git checkout -b hotfix/critical-bug
# ... минимальная правка ...
git add . && git commit -m "fix(scope): срочный фикс"
git push -u origin hotfix/critical-bug
# через GitHub UI или gh: PR hotfix/critical-bug → main → merge → CI → approve

# ВАЖНО: после merge — обязательно слить main обратно в dev,
# чтобы dev не отстал
git checkout dev
git pull
git merge origin/main
git push origin dev
```

---

## Что я НЕ делаю в main

- ❌ `git push origin main --force` или `--force-with-lease` — ruleset запретит
- ❌ Прямой коммит в локальную main + push (в принципе возможен, но flow остаётся: правка в dev → push dev:main)
- ❌ Push незаконченных коммитов («WIP», «debug», `dd()` в коде) — они навсегда останутся в истории main
- ❌ `git push origin main:dev` — это «откат dev на состояние main», ломает текущую работу

---

## Что я НЕ делаю на сервере (на prod-VM по SSH)

- ❌ Правка файлов в `/srv/pecado/*.php` через `nano` / `vim` / `sed` — следующий CI rsync затрёт. Любая правка кода → через `local → dev → main → CI`.
- ❌ `php artisan migrate` руками — миграции применяет workflow в правильном порядке (с backup'ом)
- ❌ `composer install` руками — то же
- ❌ `docker compose down` без необходимости — обрывает все очереди

✅ Что **МОЖНО** на prod-VM:
- Чтение логов: `docker compose logs -f app/worker/nginx`
- Tinker для разовых операций (создание юзеров, исправление справочников): `docker compose exec app php artisan tinker`
- Правка `/srv/pecado/.env` (он в `.gitignore` и в `--exclude` rsync — не затрётся). После правки: `php artisan config:clear && config:cache`.
- `docker compose restart` если контейнер залип

См. также `feedback_no_ssh_edits.md` в auto-memory.

---

## Локальная разработка

### Поднять стек

```bash
make up                  # docker compose up -d (включая Caddy)
# либо вручную:
docker compose up -d
```

После старта будет работать:
- Сайт: http://loc.pecado.ru (через Caddy + nginx)
- Vite HMR на `:5174` (автоматический)
- Контейнеры: `pecado-app, pecado-nginx, pecado-worker, pecado-mysql, pecado-mysql-prices, pecado-redis, pecado-rabbitmq, pecado-meilisearch, pecado-minio, pecado-mailpit, pecado-node, pecado-caddy`

### Vite HMR

После любой правки в `resources/js/**/*.jsx` или `resources/css/**/*.css` — Vite **автоматически** обновляет страницу без перезагрузки. Если HMR не работает:

```bash
# проверить что node-контейнер запущен
docker ps | grep pecado-node

# если нет — поднять
docker compose up -d node

# проверить что public/hot существует (значит Vite-сервер активен)
ls public/hot

# если не существует — Vite ещё не успел стартовать; подожди 30 сек
docker logs pecado-node --tail=20
```

### Браузер не видит loc.pecado.ru

Chrome иногда использует DNS-over-HTTPS, игнорирует `/etc/hosts`. Решения:
1. `chrome://settings/security` → выключи **«Use secure DNS»**
2. Открой http://127.0.0.1/ — Caddy вернёт тот же сайт
3. Используй другой браузер (Firefox / Brave)

---

## Локальная проверка перед push

Для **простых** правок (текст, JSX-разметка, CSS) — достаточно глазами в браузере.

Для **сложных** (PHP, новые роуты, миграции, тесты):

```bash
# Запустить тесты локально
docker exec pecado-app composer test

# Pint (форматирование PHP)
docker exec pecado-app composer lint

# PHPStan (статический анализ)
docker exec pecado-app composer analyse
```

Если что-то падает — **не пуши**. CI на dev может (по `[fast]`) пропустить, но на main — поймает. Лучше поймать локально.

---

## Когда что-то идёт не так в CI

| Симптом | Что делать |
|---|---|
| **`Lint & Tests` упал на main** | Скачать лог через `gh run view <id> --log-failed`. Чаще всего — забыл закоммитить миграцию или composer.lock. Фиксим в dev → push → push в main снова. |
| **`Build Frontend` упал** | Опечатка в JSX, проблема с npm deps. Проверить локально: `docker exec pecado-node npm run build`. |
| **`Deploy to Production` зависло на approve** | Ты не в Environment `production` reviewers, либо ты не нажал кнопку. Проверь https://github.com/savosik/pecado/settings/environments/production. |
| **`Deploy` упал на rsync** | self-hosted runner offline. Проверь: `ssh ladmin@93.94.150.16 'sudo /home/ladmin/actions-runner/svc.sh status'`. |
| **`Health Check` упал** | Прод не отдаёт 200. Возможно: контейнеры не поднялись, конфиг nginx сломан, миграция упала. Войти на prod: `docker compose ps` и `docker compose logs app`. |

---

## Если хочешь автоматизации (опционально)

### `gh` CLI с auto-merge

Если когда-нибудь захочешь PR-flow с авто-merge — выпуск Personal Access Token (`repo` + `workflow` scopes), `gh auth login --with-token`, и:

```bash
gh pr create --base main --head dev --fill && gh pr merge --merge --auto
```

Создаёт PR + автомерж когда CI зелёный. Удобно, но избыточно для соло.

### Telegram-уведомления о деплое

В `deploy-prod.yml` уже есть TODO-комментарий в шагах `Notify on success/failure`. Можно подключить Telegram бота через [appleboy/telegram-action@master](https://github.com/appleboy/telegram-action). См. issue для будущего.

---

## Чек-лист «всё настроено правильно»

```bash
# Я на dev
git status -sb            # должно быть: ## dev...origin/dev

# DNS проверки
dig +short pecado.ru     # → 93.94.150.16
dig +short www.pecado.ru # → 93.94.150.16

# Прод доступен
curl -sI https://pecado.ru/up   # → HTTP/2 200

# Локально
curl -sI http://loc.pecado.ru/  # → 200 OK
ls public/hot                    # существует = Vite в dev-режиме
docker ps | wc -l                # >= 12 (+caddy и др.)
```

---

## Главное запомнить

1. **Сижу на `dev`, не переключаюсь на `main`.** Всё через `dev`.
2. **`git push origin dev:main` = релиз.** Одна команда. Дальше approve в браузере.
3. **Прод защищён** Environment'ом и ruleset'ом — случайно ничего не сломается.
4. **Откат = `git revert HEAD && push`**, никаких force-push.
5. **На сервере по SSH — только чтение.** Правки кода → через git.
