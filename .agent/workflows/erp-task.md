---
description: реализует интеграционную задачу ERP используя spec-driven development, канбан на папках и автоматическое обновление всей документации
---

# Workflow: Интеграционная задача ERP

> Spec-driven development: **сначала спецификация → потом код**.
> Используй этот workflow когда пользователь описывает задачу связанную с обменом данными 1С ↔ Сайт, изменением payload-ов, добавлением новых событий, или изменением бизнес-правил интеграции.

---

## Фаза 1: Анализ задачи

1. **Прочитай текущую спецификацию** для понимания контекста:
   - Bundled AsyncAPI YAML: `docs/asyncapi/pecado-erp-integration.yaml`
   - Текущие JSON Schema: `app/Services/Erp/Schemas/*.json`
   - Бизнес-правила MkDocs: `docs-erp/content/rules/*.md`
   - Changelog: `docs-erp/content/changelog.md`
   - Тест-план: `docs-erp/content/tests/*.md`

2. **Определи scope изменений:**
   - Какие события затронуты? (новые / изменение существующих)
   - Какие JSON Schema нужно создать/изменить?
   - Какие бизнес-правила затронуты?
   - Нужны ли новые очереди / routing keys?

3. **Задай уточняющие вопросы** пользователю если не хватает информации.

---

## Фаза 2: Создание задачи в канбане

Создай файл задачи в `docs/tasks/backlog/` с именем `YYYY-MM-DD_краткое-описание.md`.

Формат файла:

```markdown
# Краткое описание задачи

**Приоритет:** высокий | средний | низкий
**Создано:** YYYY-MM-DD
**Затронутые события:** event1.created, event2.updated
**Затронутые схемы:** event1.created.json, event2.updated.json

## Описание

<подробное описание задачи от пользователя + контекст>

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: `app/Services/Erp/Schemas/<file>.json`
- [ ] AsyncAPI YAML (включая обновление `version`): `docs/asyncapi/pecado-erp-integration.yaml`
- [ ] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [ ] Бизнес-правила: `docs-erp/content/rules/<entity>.md`
- [ ] Тест-план: `docs-erp/content/tests/phase-<N>-<name>.md`
- [ ] Changelog: `docs-erp/content/changelog.md`
- [ ] Сборка: `mkdocs build`

### Код
- [ ] Handler/Job: `app/Services/Erp/Handlers/Handle<Event>.php`
- [ ] Миграция БД (если нужно)
- [ ] Роутинг RabbitMQ (если нужно)

### Тесты
- [ ] Feature-тест: `tests/Feature/Erp/<Test>.php`

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
- [ ] Код закоммичен и запушен
```

---

## Фаза 3: Спецификация (Spec-First)

**Порядок строго последовательный. Не переходи к следующему шагу пока не закончен предыдущий.**

### 3.1 — JSON Schema

Создай или обнови файл(ы) в `app/Services/Erp/Schemas/`:

- Входящие (1С → Сайт): `event_name.json`
- Исходящие (Сайт → 1С): `event_name.to_erp.json`

Конвенции:
- `"type": "object"`, `"required": [...]`, `"properties": {...}`
- Обязательные мета-поля: `event` (const), `message_id` (string)
- UUID поля: `"type": "string", "format": "uuid"`
- Nullable: `"type": ["string", "null"]`

### 3.2 — AsyncAPI YAML

Обнови `docs/asyncapi/pecado-erp-integration.yaml`:

- **ВАЖНО: Обнови поле `version` в блоке `info` (совпадает с версией в changelog)**
- Добавь/обнови channel, message, schema
- Используй `$ref` на JSON Schema файлы где возможно
- Добавь `description` на русском языке

### 3.3 — Валидация

```bash
// turbo
docker exec pecado-node npm run asyncapi:validate
```

Если валидация не проходит — исправь YAML и повтори.

### 3.4 — Сборка AsyncAPI

```bash
// turbo
docker exec pecado-node npm run asyncapi:build
```

> **ВАЖНО:** Сборка генерирует `docs/asyncapi/html/` и `docs/asyncapi/pecado-erp-bundled.yaml`.
> Эти файлы **трекаются в git** и деплоятся через rsync. Не забудь включить их в коммит (`git add -A`).

---

## Фаза 4: Документация (MkDocs)

### 4.1 — Бизнес-правила

Обнови соответствующий файл в `docs-erp/content/rules/`:

| Сущность | Файл |
|---|---|
| Партнёры | `rules/partners.md` |
| Каталог | `rules/catalog.md` |
| Цены | `rules/prices.md` |
| Остатки | `rules/stocks.md` |
| Контрагенты | `rules/contractors.md` |
| Заказы | `rules/orders.md` |
| Возвраты | `rules/returns.md` |
| Реализации | `rules/shipments.md` |
| Баланс | `rules/balances.md` |
| Индивидуальные цены | `rules/individual-prices.md` |
| Ценообразование | `rules/pricing-model.md` |
| Атрибуты товаров | `rules/product-attributes.md` |
| Клиентские сегменты | `rules/client-segments.md` |
| Нормализация данных | `rules/data-normalization.md` |

**Правила:**
- НЕ дублировать JSON-примеры и таблицы полей — ссылаться на JSON Schema: `> Структура payload → [JSON Schema](/docs/erp/schemas/<file>.json)`
- Описывать только **бизнес-правила**, версионные изменения и критерии приёмки
- Помечать версионные изменения: `**(vN)** описание`

### 4.2 — Тест-план

Обнови или добавь тест-кейсы в `docs-erp/content/tests/`:

| Фаза | Файл | Описание |
|---|---|---|
| 0 | `phase-0-setup.md` | Подготовка инфраструктуры |
| 1 | `phase-1-inbound.md` | 1С → Сайт |
| 2 | `phase-2-outbound.md` | Сайт → 1С |
| 3 | `phase-3-e2e.md` | Сквозные сценарии |
| 4 | `phase-4-edge.md` | Edge-кейсы |

**Правила:**
- Каждый тест-кейс ОБЯЗАН содержать ссылку на JSON Schema
- Нумерация: `N.M — Название (event.name)` где N = номер фазы
- Зависимости указывать явно: `**Зависимости:** 1.3 (товар), 1.9 (партнёр)`
- Маркировка стороны: 🔵 = 1С отправляет, 🟢 = Сайт отправляет

### 4.3 — Changelog

Добавь запись в `docs-erp/content/changelog.md` в формате Keep a Changelog:

```markdown
## [X.Y.Z] — YYYY-MM-DD

### Добавлено / Изменено / Удалено

- Краткое описание изменения
```

### 4.4 — Сборка MkDocs

```bash
// turbo
~/.local/bin/mkdocs build
```

> **ВАЖНО:** Сборка генерирует `docs-erp/site/`.
> Этот каталог **трекается в git** и деплоится через rsync. Не забудь включить его в коммит (`git add -A`).

---

## Фаза 5: Реализация (код)

### 5.1 — Миграция БД (если нужно)

> ВАЖНО: На dev сервере есть данные. Всегда создавай **новую** миграцию, не редактируй старую.

```bash
docker exec pecado-app php artisan make:migration <migration_name>
```

### 5.2 — Handler / Job

Паттерн обработки входящих событий:
- Handler: `app/Services/Erp/Handlers/Handle<EventName>.php`
- Job: `app/Jobs/Erp/Process<EventName>Job.php` (если тяжёлая обработка)
- Валидация payload через `ErpMessageValidator` + JSON Schema
- Идемпотентность через `message_id` → `erp_processed_messages`

### 5.3 — Outbound (Сайт → 1С)

Паттерн отправки исходящих событий:
- Job: `app/Jobs/Publish<Entity>ToErpJob.php`
- Dispatch из контроллера/сервиса **после** commit транзакции

### 5.4 — Перемещение задачи

Переместить файл задачи:
```bash
mv docs/tasks/backlog/<task>.md docs/tasks/in-progress/
```

---

## Фаза 6: Тесты

### 6.1 — Feature-тест

Создай тест в `tests/Feature/Erp/`:

- Тестируй через `ErpMessageValidator` + handler
- Используй фабрики для создания зависимых сущностей
- Проверяй идемпотентность (повторный `message_id`)
- Проверяй невалидный payload (отсутствие обязательных полей)

```bash
docker exec pecado-app php artisan test --filter=<TestName>
```

---

## Фаза 7: Финализация

### 7.1 — Полная проверка

```bash
// turbo
docker exec pecado-node npm run asyncapi:validate
```

```bash
// turbo
~/.local/bin/mkdocs build 2>&1 | tail -5
```

```bash
docker exec pecado-app php artisan test
```

### 7.2 — Перемещение задачи

```bash
mv docs/tasks/in-progress/<task>.md docs/tasks/done/
```

### 7.3 — Коммит и деплой

Коммит сообщение по конвенции:
- `feat(erp): описание` — новая функциональность
- `fix(erp): описание` — исправление
- `docs(erp): описание` — только документация

```bash
cd /home/savosik/projects/pecado
git add -A
git commit -m "<type>(erp): <описание>"
git push origin dev
```

CI/CD автоматически задеплоит на dev сервер (см. `/deploy` workflow).
