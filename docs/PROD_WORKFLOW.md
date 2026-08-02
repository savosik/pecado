# Workflow: разработка → dev → main → prod

> **Аудитория:** разработчик(и) проекта Pecado.
> **Цель:** объяснить максимально простыми словами, что я делаю на каждом этапе и какие правила нельзя нарушать.
> **Связанные документы:** [CICD_DEV_DEPLOYMENT.md](./CICD_DEV_DEPLOYMENT.md) · [CICD_PROD_DEPLOYMENT.md](./CICD_PROD_DEPLOYMENT.md)

---

## 1. Большая картина

> ⚠️ **У dev и prod свои внешние IP:** prod — `93.94.150.16` (`pecado.ru`), dev — `93.94.150.74` (`dev.pecado.ru`), оба на стандартных портах (см. [DEV_SERVER_CREDENTIALS.md](./DEV_SERVER_CREDENTIALS.md)). Временная схема с портами `8022/8080/8443/25672` больше не действует.

```
┌─────────────────────────────────────────────────────────────────┐
│  LOCAL — твой ноутбук                                           │
│  • docker compose up -d                                          │
│  • кодишь, запускаешь тесты локально                             │
│  • домен: http://loc.pecado.ru:8085                              │
└──────────────────────────────┬──────────────────────────────────┘
                               │ git push origin dev
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  DEV — m-s-site (10.2.2.100), внешний IP 93.94.150.74           │
│  • стандартные порты: 22 (ssh), 80/443 (http/s), 15672 (mq)     │
│  • CI: тесты + сборка (или fast-lane по [fast])                 │
│  • self-hosted runner деплоит rsync'ом                          │
└──────────────────────────────┬──────────────────────────────────┘
                               │ Pull Request: dev → main
                               │ (review → approve → merge)
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  PROD — m-s-web (10.2.2.101), внешний IP 93.94.150.16           │
│  • стандартные порты: 22/80/443/15672                           │
│  • CI: тесты + сборка (без fast-lane!)                          │
│  • Manual approve в GitHub Environment `production`             │
│  • деплой с maintenance mode + бэкап БД                         │
│  • домен: https://pecado.ru                                     │
└─────────────────────────────────────────────────────────────────┘
```

**Главный принцип:** код **никогда** не попадает в `main` напрямую. Только через **Pull Request из `dev`**, и только после того, как dev-сервер прожил с этим кодом хотя бы немного и не упал.

---

## 2. Ветки и их роли

| Ветка | Что в ней лежит | Куда деплоится | Кто пишет |
|---|---|---|---|
| `main` | **Только рабочий, оттестированный код** | pecado.ru (prod) | **Никто напрямую**. Только PR из `dev` |
| `dev` | Текущая разработка, все фичи | dev-сервер `m-s-site` (`dev.pecado.ru`) | Разработчик (push или merge feature/) |
| `feature/<имя>` | Большие/рискованные фичи | никуда | Разработчик; потом merge в `dev` |

> **`main` ≠ «мейн ветка для разработки».** Это «релизная» ветка. В ней должен быть код, который **прямо сейчас** крутится на проде (или уйдёт туда через 5 минут).

---

## 3. Ежедневный цикл

### 3.1 Маленькая правка (типичный день)

```bash
# 1. Локально
git checkout dev
git pull origin dev

# 2. Кодишь, тестируешь
docker exec pecado-app composer test
docker exec pecado-app composer lint

# 3. Коммит
git add .
git commit -m "fix(cart): убрал дублирующий запрос корзины"

# 4. Push в dev — автодеплой на dev.pecado.ru
git push origin dev
```

→ GitHub Actions запускает [deploy-dev.yml](../.github/workflows/deploy-dev.yml) → если код только в `*.md`/`*.css` или коммит-сообщение содержит `[fast]`, тесты пропускаются → деплой → dev.pecado.ru обновлён.

### 3.2 Ты потестил на dev, всё ок — выкатываем в прод

```bash
# Через GitHub CLI
gh pr create --base main --head dev \
  --title "Release: исправление корзины + перформанс экспортов" \
  --body "$(cat <<'EOF'
## Что в релизе
- fix(cart): убрал дублирующий запрос корзины (#XXX)
- perf(exports): сократить TTL кэша до 5 мин

## Тестирование
- [x] Прокликал на dev.pecado.ru
- [x] CI на dev зелёный
- [x] Миграций нет / миграции прогнал на dev

## Откат
- revert PR в main + auto-redeploy
EOF
)"
```

Или вручную через UI GitHub: **Compare & Pull Request: dev → main**.

После создания PR:

1. **CI прогоняет тесты** (полный test suite, без fast-lane).
2. **Сам себе делаешь review** (или просишь напарника).
3. **Merge** (предпочтительно "Create a merge commit", чтобы видеть граф релизов).

### 3.3 Что происходит после merge в main

1. GitHub запускает [deploy-prod.yml](../.github/workflows/deploy-prod.yml).
2. Тесты + сборка фронтенда + сборка MkDocs.
3. **Workflow останавливается** на этапе деплоя — ждёт твоего нажатия «Approve» в GitHub Environment `production`.
4. Ты заходишь в **Actions → Deploy to Production → Review deployments** → жмёшь Approve.
5. Делается **бэкап БД** (snapshot перед деплоем).
6. Включается `php artisan down` (maintenance mode — пользователи видят страницу «обслуживание»).
7. Композер, кеши, миграции, сидеры, RabbitMQ topology, перезапуск контейнеров.
8. `php artisan up` → сайт снова доступен.
9. **Health check**: `curl https://pecado.ru/up` должен вернуть 200.
10. Готово.

Время одного прод-деплоя: **5-10 минут** (зависит от количества миграций).

### 3.4 Большая/рискованная фича

```bash
# 1. От dev — feature ветка
git checkout dev
git pull
git checkout -b feature/новая-выгрузка-1с

# 2. Работаешь, push в feature
git push -u origin feature/новая-выгрузка-1с

# 3. Создаёшь PR feature → dev (опционально, можно прямо merge локально)
gh pr create --base dev --head feature/новая-выгрузка-1с

# 4. Merge в dev → авто-деплой на dev → тестируешь
# 5. Когда уверен — PR dev → main
```

---

## 4. Что **НЕЛЬЗЯ** делать

### 🚫 Никогда не пушить в `main` напрямую

```bash
# ❌ ЗАПРЕЩЕНО
git checkout main
git push origin main

# ✅ ПРАВИЛЬНО
gh pr create --base main --head dev
```

Branch protection не даст это сделать, но всё равно — **это правило №1**.

### 🚫 Никогда не делать `git push --force` в `main`

```bash
# ❌ ЗАПРЕЩЕНО (уничтожит историю прод-релизов)
git push origin main --force

# ✅ Если нужно откатить — через revert
git revert -m 1 HEAD
git push origin main
```

> Единственный раз, когда `main --force` оправдан — это **первоначальная синхронизация main с dev** до настройки branch protection. После — никогда.

### 🚫 Никогда не редактировать старые миграции

И на dev, и на проде есть реальные данные. Старые миграции уже применены. **Только новые миграции** — даже если старая «глупо названа» или «лежит не там».

### 🚫 Никогда не использовать `[fast]` для prod-деплоя

Fast-lane (пропуск тестов по `[fast]` в commit message) есть **только в `deploy-dev.yml`**. В `deploy-prod.yml` тесты запускаются всегда, даже если правишь только `*.md`.

### 🚫 Никогда не коммитить `.env`

Файл лежит **только на сервере**. `.gitignore` должен это гарантировать. Если случайно закоммитил пароли — **сразу ротировать их все**.

### 🚫 Никогда не запускать `php artisan migrate` руками на проде

Миграции применяются **только через CI/CD**. Иначе:
- расхождение между ветками и реальной БД;
- если миграция упадёт — вы остаётесь в подвешенном состоянии без maintenance mode;
- бэкап перед миграцией не делается.

### 🚫 Никогда не править файлы прямо на проде через SSH

```bash
# ❌ ЗАПРЕЩЕНО
ssh deploy@pecado.ru
cd /srv/pecado
nano app/Models/Product.php   # быстрая правка

# ✅ ПРАВИЛЬНО
# Локально → dev → CI → main → CI → prod
```

При следующем деплое твоя правка просто будет затёрта `rsync --delete`. И ты не поймёшь почему «было же исправлено».

### 🚫 Никогда не восстанавливать БД из бэкапа без maintenance mode

Иначе пользователи могут писать в старую базу во время восстановления → потеря данных.

```bash
# ✅ Правильный порядок
php artisan down
gunzip < backup.sql.gz | mysql ...
php artisan up
```

---

## 5. Что **ОБЯЗАТЕЛЬНО** проверять перед PR в main

Чек-лист перед мержем `dev → main`:

- [ ] **CI на dev зелёный** (тесты прошли, не было fast-lane на критичные изменения).
- [ ] **dev.pecado.ru работает** — открыл, потыкал, ничего не сломалось визуально.
- [ ] **Миграции протестированы** — на dev они уже применились без ошибок.
- [ ] **Новые `.env` переменные документированы** — есть в `.env.example` И в `CICD_PROD_DEPLOYMENT.md`.
- [ ] **AsyncAPI/MkDocs/JSON Schema актуальны** (если трогал ERP-интеграцию).
- [ ] **Интеграционные тесты RabbitMQ есть** (если трогал шину 1С).
- [ ] **Нет debug-кода** (`dd()`, `Log::debug()` без надобности, console.log).
- [ ] **Откат продумал** — если что, как буду возвращать (revert? restore БД?).

---

## 6. Откат (rollback)

### Способ A: Revert PR (рекомендуется в 99% случаев)

```bash
# Локально
git checkout main
git pull origin main
git revert -m 1 HEAD          # -m 1 для merge-коммита
git push origin main
# → запускается deploy-prod.yml → нужен approve → откат
```

### Способ B: Если поломали данные миграцией

1. **Сразу включить maintenance** на сервере: `ssh` → `php artisan down`.
2. **Восстановить БД** из `/media/backups/mysql/pre-deploy/<timestamp>.sql.gz`.
3. **Revert PR в main** (как Способ A).
4. После деплоя revert'a — `php artisan up`.

### Способ C: Чрезвычайный (все упало, прод недоступен)

```bash
# SSH на прод
ssh deploy@pecado.ru
cd /srv/pecado

# Откатиться на коммит назад (узнать SHA через git log)
git fetch origin
git checkout <SHA_предыдущего_коммита>

# Пересобрать
sg docker -c "docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app composer install --no-dev --optimize-autoloader"
sg docker -c "docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan optimize"
sg docker -c "docker compose -f docker-compose.yml -f docker-compose.prod.yml restart app worker nginx"

# После — обязательно revert в main, чтобы синхронизировать GitHub с реальностью
```

---

## 7. Особые случаи

### 7.1 Hotfix на проде (срочная правка, не идущая через dev)

Иногда нужно срочно починить прод, но dev не готов к выпуску. Тогда:

```bash
# 1. От main создаём hotfix-ветку
git checkout main
git pull
git checkout -b hotfix/critical-bug

# 2. Минимальная правка
# 3. Push + PR прямо в main
git push -u origin hotfix/critical-bug
gh pr create --base main --head hotfix/critical-bug --title "hotfix: critical bug"

# 4. Merge → деплой на prod
# 5. ВАЖНО: после деплоя смержить main обратно в dev,
#    чтобы dev не отставал от main
git checkout dev
git pull
git merge origin/main
git push origin dev
```

### 7.2 Освежить dev перед большой работой

Если давно не трогал репо и хочешь начать чистый день:

```bash
git fetch origin
git checkout dev
git reset --hard origin/dev   # ⚠️ только если нет локальных коммитов!
docker compose down && docker compose up -d
docker exec pecado-app composer install
docker exec pecado-app php artisan migrate
```

### 7.3 Большой релиз с миграцией данных

Если миграция тяжёлая (массовое обновление миллионов строк):

1. На dev прогнать **с реальным объёмом данных**, замерить время.
2. Если миграция > 1 минуты — **разбить на части** или сделать **фоновой** (через Horizon job).
3. На прод **обязательно**: бэкап БД (auto), maintenance mode (auto), но ещё **тестовый прогон в CI** на копии прод-данных, если есть staging.

### 7.4 Новые секреты в `.env`

Если добавил новую переменную (`NEW_API_KEY=...`):

1. Добавить в `.env.example` (с пустым значением или плейсхолдером).
2. Документировать в `CICD_PROD_DEPLOYMENT.md` (раздел 3.3).
3. **Перед** мержем PR в main — зайти на прод-сервер по SSH и руками добавить значение в `/srv/pecado/.env`.
4. После деплоя CI прогонит `php artisan config:cache` — и значение подхватится.

> ⚠️ Если забыл добавить переменную в `.env` на проде — `config:cache` всё равно отработает, но приложение упадёт с ошибкой при первом запросе. **Сначала `.env` на проде, потом merge в main.**

---

## 8. Чеклист первоначальной настройки prod (one-time)

В порядке выполнения:

### 8.1 GitHub
- [ ] Синхронизировать `main = dev`: `git push origin dev:main --force-with-lease` (один раз!)
- [ ] **Settings → Branches → Add rule** для `main`: require PR, require status checks, no force push, no deletions
- [ ] **Settings → Environments → New environment `production`**: required reviewers (себя), deployment branches = только `main`

### 8.2 Сервер
- [ ] Купить VPS (4 vCPU / 8 GB RAM / 80 GB SSD), Ubuntu 24.04 LTS
- [ ] Настроить DNS: `pecado.ru` и `www.pecado.ru` → IP сервера
- [ ] Настроить ОС (deploy-юзер, UFW, fail2ban, no-root SSH)
- [ ] Установить Docker
- [ ] Получить SSL через certbot
- [ ] Зарегистрировать self-hosted runner с лейблом `prod-server`

### 8.3 Проект
- [ ] Создать `docker-compose.prod.yml` (см. CICD_PROD_DEPLOYMENT.md §3.1)
- [ ] Создать `docker/nginx/prod.conf` (см. CICD_PROD_DEPLOYMENT.md §3.2)
- [ ] Создать `.github/workflows/deploy-prod.yml` (см. CICD_PROD_DEPLOYMENT.md §4)
- [ ] Создать `/srv/pecado/.env` на проде с сильными паролями
- [ ] Запустить стек, прогнать `migrate`, `seed`, `rabbitmq:setup`, `scout:sync-index-settings`
- [ ] Создать первого админа через `tinker`
- [ ] Настроить cron для бэкапов БД (см. CICD_PROD_DEPLOYMENT.md §6 шаг 5)
- [ ] Проверить `https://pecado.ru/up` → 200

### 8.4 Финальная проверка
- [ ] Сделать тестовый PR (`dev → main`) с минимальной правкой → пройти весь цикл с approve → проверить что прод обновился
- [ ] Проверить откат: revert этого PR → новый деплой → прод снова в исходном состоянии

---

## 9. Где что искать

| Что | Где |
|---|---|
| Текущий dev-workflow | [.github/workflows/deploy-dev.yml](../.github/workflows/deploy-dev.yml) |
| План prod-инфраструктуры | [CICD_PROD_DEPLOYMENT.md](./CICD_PROD_DEPLOYMENT.md) |
| Доступы к dev-серверу | `docs/DEV_SERVER_CREDENTIALS.md` |
| Логи на dev | SSH → `/srv/pecado` → `docker compose logs -f app/worker/nginx` |
| Логи на prod | SSH → `/srv/pecado` → `docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f app` |
| Бэкапы БД на prod | `/media/backups/mysql/daily/` (ежедневные, на отдельном диске `sdb`) и `/media/backups/mysql/pre-deploy/` (перед каждым деплоем). Retention: основная БД — 30 дней daily / 10 последних pre-deploy; БД цен — 5 последних в обоих случаях. |
| Health endpoint | `https://pecado.ru/up` |
| RabbitMQ Management | `https://pecado.ru:15672` через SSH-туннель (наружу закрыт) |

---

## 10. FAQ

**В: Можно ли я закоммичу в dev и тут же сделаю PR в main, не дожидаясь dev-деплоя?**
О: Технически — да. Но смысл dev-сервера в том, чтобы прокликать руками то, что тесты не покрывают. Лучше подождать 5-10 минут.

**В: Что делать, если CI на main упал на этапе тестов?**
О: Тогда merge не повлияет на прод (workflow остановится до approve). Откатить нечего. Нужно: 1) понять, почему упало, 2) исправить в `dev`, 3) повторить PR.

**В: Что делать, если на main CI прошёл, я approve'нул, но деплой на проде упал?**
О: Maintenance mode останется включённым (или нет — зависит от того, на каком шаге упало). Нужно SSH на прод и:
   - Если сайт в maintenance — `php artisan up`.
   - Если миграция применилась частично — восстановить БД из бэкапа.
   - Revert PR в main, новый деплой = откат.

**В: Сколько раз в день нормально деплоить на прод?**
О: Сколько хочешь, но каждый деплой = ~5-10 минут maintenance. Если есть несколько мелких правок — копи их в `dev`, релизь раз в день одним PR. Если правка критичная — релизь сразу.

**В: Почему ради одной правки CSS гонится весь test suite на проде?**
О: Потому что `*.css` мог быть импортирован из JS, который собирается в бандл, который ловится `dorny/paths-filter`. На dev можно по `[fast]`, на проде — нет. Не экономь на этом.

**В: Я добавил новую миграцию, она тяжёлая (5 минут). Что делать?**
О: 1) Сначала прогнать на dev, замерить. 2) Если > 30 секунд — задеплоить **в непиковое время** (ночью). 3) Подумать, можно ли разбить на части или сделать фоновой задачей.

**В: Прод упал среди ночи, я не у компа. Что делать заранее?**
О: 1) Включить уведомления о падении деплоя в Telegram (TODO в workflow). 2) Дать подразделу 1-2 коллегам admin-доступ к GitHub Environment `production` с правом approve. 3) Настроить UptimeRobot для алерта на падение `https://pecado.ru/up`.
