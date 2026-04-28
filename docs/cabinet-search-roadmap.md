# Roadmap инициативы «Поиск в личном кабинете»

Документ описывает **порядок** реализации, **состав** PR и **стратегию безопасной доставки** для инициативы по улучшению поиска в личном кабинете. Содержательные требования не дублируются — см. источники.

## Источники правды

| Документ | Назначение |
|---|---|
| [docs/cabinet-search-scenarios.md](cabinet-search-scenarios.md) | Сценарии «капризного клиента» (C-N.M) — требования |
| [docs/tasks/backlog/2026-04-28_cabinet-search-*.md](tasks/backlog/) | Карточки задач (по разделам кабинета) |
| [docs/cabinet-search-prompting.md](cabinet-search-prompting.md) | Плейбук промптов для Claude Code — как закрывать PR последовательно |
| **Этот документ** | План волн + стратегия релиза + соответствие карточкам |

## Принципы безопасной доставки

1. **Backend-first, аддитивно.** Все новые query-параметры — опциональные. Старый фронт без изменений работает на новом бэке. Этап 1 каждой карточки (расширение LIKE) — чисто аддитивен, **без фича-флагов**.
2. **Фича-флаги для смены поведения.** Только там, где меняется выдача (fuzzy через Meilisearch, экспорт, пресеты): через `config('search-cabinet.*')` и `env('CABINET_SEARCH_*')`. Дефолт на prod — `false`, включается после dev-проверки.
3. **Один PR — одна карточка backlog (или один её этап).** Не смешиваем разделы кабинета в одном PR.
4. **Feature-тесты обязательны** для каждого расширения поиска (минимум: запрос → совпадение по новому источнику).
5. **Миграции только новые** (правило проекта, см. CLAUDE.md). Никаких правок старых.
6. **Раскатка**: push в `dev` → CI/CD `.github/workflows/deploy-dev.yml` → проверка на dev-сервере → флаг включается на prod (если есть отдельный prod-deploy) → наблюдение.
7. **План отката**:
   - LIKE-волны (без флага): `git revert` PR и redeploy.
   - Volna-волны (с флагом): `CABINET_SEARCH_*=false` в env + cache:clear — мгновенный откат без revert.
   - Откат миграций: только если PR содержит схему — иначе достаточно отката кода.
8. **DoD каждого PR**:
   - [ ] Pint + PHPStan чистые.
   - [ ] Feature-тесты на новые сценарии поиска.
   - [ ] Карточка backlog обновлена (отмечен этап) и при завершении этапа — перемещена.
   - [ ] Если затрагивает UI — проверено в браузере на dev.
   - [ ] Если фича-флаг — добавлен в `config/search-cabinet.php` + документирован в этом файле.

## Прогресс

- Карточки задач лежат в `docs/tasks/backlog/2026-04-28_cabinet-search-*.md`.
- Перемещение по канбану: `backlog → todo → in-progress → review → done` (см. [docs/tasks/README.md](tasks/README.md)).
- Чек-лист волны — внизу этого документа, обновляется по мере закрытия PR.

---

## Волна 1 — Фундамент (без зависимостей)

**Цель:** подготовить хелперы и общие UI-компоненты, на которых будут писаться все последующие волны. **Без миграций, без флагов** — чисто аддитивный код.

| Карточка | Что делается в волне |
|---|---|
| [cabinet-search-foundation](tasks/backlog/2026-04-28_cabinet-search-foundation.md) | Хелперы `DocumentNumber`, `QueryRouter`, общий `<SelectedFilters />`, хук `useSearchHistory` |

### PR-план

- **PR 1.1** — `App\Support\Search\DocumentNumber` + `App\Support\Search\QueryRouter` + unit-тесты.
- **PR 1.2** — Frontend: `resources/js/components/cabinet/SelectedFilters.jsx` (вынос из `Pages/User/Products/SelectedFilters.jsx` без удаления оригинала) + `resources/js/hooks/useSearchHistory.js`.

### Стратегия доставки

- Старые потребители не трогаются: `Pages/User/Products/SelectedFilters.jsx` остаётся на месте, новый компонент — копия с конфигом полей. Удалим оригинал, когда все потребители переедут (после волн 2–3).
- `useSearchHistory` — pure localStorage, без бэкенда. Нет рисков для прод-данных.

### Откат

`git revert` обоих PR. Никаких миграций и фича-флагов.

---

## Волна 2 — Документы (LIKE-only)

**Цель:** закрыть Этап 1 во всех «документах» кабинета — расширить точечный поиск через `whereHas` без Meilisearch.

**Зависит от:** Волна 1 (`DocumentNumber::normalize()`).

| Карточка | Что делается |
|---|---|
| [cabinet-search-orders](tasks/backlog/2026-04-28_cabinet-search-orders.md) | Этап 1: состав, контрагент, ИНН, комментарий, multi-бренд, позиции от/до, `?product_id=` (C-1.1 … C-1.13) |
| [cabinet-search-returns](tasks/backlog/2026-04-28_cabinet-search-returns.md) | Этап 1: состав, исходная реализация, текст комментария причины, multi-причина (C-2.1 … C-2.5) |
| [cabinet-search-shipments](tasks/backlog/2026-04-28_cabinet-search-shipments.md) | Этап 1: бренд/штрихкод/категория в составе, склад, `?order_uuid=` (C-4.1 … C-4.7) |
| [cabinet-search-shipments-picker](tasks/backlog/2026-04-28_cabinet-search-shipments-picker.md) | `searchShipments`: поиск по составу, `open_returns_count` в JSON (C-3.1 … C-3.4) |

### PR-план

- **PR 2.1** — Orders Этап 1 + UI + feature-тесты.
- **PR 2.2** — Returns Этап 1 + UI + feature-тесты.
- **PR 2.3** — Shipments Этап 1 + UI + feature-тесты.
- **PR 2.4** — shipments-picker (расширение `searchShipments`) + правка `ReturnItemsEditor.jsx`.
- **PR 2.5** — Подключение `<SelectedFilters />` и `useSearchHistory` на страницах Orders/Returns/Shipments + унификация debounce 400 мс на формах фильтров (A-7).

### Стратегия доставки

- **Без фича-флагов**: все изменения аддитивны (новые `whereHas` + новые query-параметры, старые продолжают работать).
- **Backend-first**: каждый PR раскатывается одним коммитом, новые поля поиска становятся доступны сразу. Старый UI без изменений работает корректно.
- На dev — проверка golden path: «найти заказ по бренду в составе», «найти возврат по номеру исходной реализации», и т.п.

### Откат

`git revert` конкретного PR. Тесты на старое поведение не должны ломаться.

---

## Волна 3 — Корзинно-избранное (LIKE)

**Цель:** довести `cart-products` и `favorites` до базового уровня поиска. Не зависит от волны 2 — может идти параллельно.

**Зависит от:** Волна 1 (`<SelectedFilters />` для favorites).

| Карточка | Что делается |
|---|---|
| [cabinet-search-cart-products](tasks/backlog/2026-04-28_cabinet-search-cart-products.md) | Этап 1: бренд, эвристика штрихкода, `purchased_count`, `in_favorites` (C-6.1 … C-6.5) |
| [cabinet-search-favorites](tasks/backlog/2026-04-28_cabinet-search-favorites.md) | Этап 1: поиск/фильтры/сортировка (C-7.1, C-7.3, C-7.4, C-7.6, C-7.7) |

### PR-план

- **PR 3.1** — cart-products Этап 1.
- **PR 3.2** — favorites Этап 1 + UI.

### Стратегия доставки и откат

Аналогично волне 2 — аддитивно, без флагов, revert по PR.

---

## Волна 4 — Fuzzy через Meilisearch (за флагом)

**Цель:** подключить fuzzy-поиск для названий товаров и брендов в составе документов и в favorites/cart-products. Контракт «fuzzy ТОЛЬКО для названий» из [сценариев §«Сквозные принципы»](cabinet-search-scenarios.md).

**Зависит от:** Волны 2 и 3 (потребители уже работают на LIKE).

| Карточка | Что делается |
|---|---|
| [cabinet-search-orders](tasks/backlog/2026-04-28_cabinet-search-orders.md) | Этап 2: fuzzy по составу |
| [cabinet-search-returns](tasks/backlog/2026-04-28_cabinet-search-returns.md) | Этап 2: fuzzy по составу |
| [cabinet-search-shipments](tasks/backlog/2026-04-28_cabinet-search-shipments.md) | Этап 2: fuzzy по составу |
| [cabinet-search-cart-products](tasks/backlog/2026-04-28_cabinet-search-cart-products.md) | Этап 2: fuzzy для name/brand |
| [cabinet-search-favorites](tasks/backlog/2026-04-28_cabinet-search-favorites.md) | Этап 2: fuzzy для name/brand |
| [cabinet-search-cross-cutting](tasks/backlog/2026-04-28_cabinet-search-cross-cutting.md) | A-5: контракт `match_source`/`match_snippet` + `<MatchBadge>` |

### PR-план

- **PR 4.1** — Индексация `OrderItem` / `ReturnItem` / `ShipmentItem` в Meilisearch (Searchable trait + миграция полей `product_name_snapshot`, `brand_name_snapshot`, если их нет) + бэкфилл-команда (`scout:import`).
  - **Деплой:** PR без подключения к контроллерам. Сначала индексы наполняются на dev, потом на prod (бэкфилл может быть долгим — проверить).
- **PR 4.2** — Подключение fuzzy в OrderController/ReturnController/ShipmentController за флагом `CABINET_SEARCH_FUZZY_DOCUMENTS`.
- **PR 4.3** — Подключение fuzzy в `searchProducts` / Favorites за флагом `CABINET_SEARCH_FUZZY_PRODUCTS`.
- **PR 4.4** — Контракт `match_source`/`match_snippet` в JSON-ответе + `<MatchBadge>` + рендеринг на страницах Orders/Returns/Shipments.

### Стратегия доставки

- **Фича-флаги обязательны.** Изменение поведения поиска (другая выдача, fuzzy) — риск ложных срабатываний. На prod включаем флаги поэтапно: сначала Orders → неделя наблюдения → Returns/Shipments → неделя → Favorites/cart-products.
- **Бэкфилл индекса — отдельный шаг.** Сначала PR 4.1 раскатывается и индекс заполняется в фоне (`php artisan scout:import`), и только потом PR 4.2 включает потребление.
- **Дедупликация результатов** обязательна (документ может найтись и по номеру, и по составу — выводим один раз) — учесть в реализации.
- **Scope user_id** не должен пробиваться через fuzzy — добавить ограничение в Scout-запросе.

### Откат

- Выключить флаг (мгновенно, без revert).
- Если индекс мешает — `scout:flush` для конкретной модели.
- Миграции PR 4.1 откатывать только при критических проблемах со схемой.

### Фича-флаги

| Env | Default prod | Описание |
|---|---|---|
| `CABINET_SEARCH_FUZZY_DOCUMENTS` | `false` | Fuzzy в OrderController/ReturnController/ShipmentController |
| `CABINET_SEARCH_FUZZY_PRODUCTS` | `false` | Fuzzy в `searchProducts`/Favorites |

---

## Волна 5 — UX-улучшения (за флагами)

**Цель:** закрыть UX-механизмы из cross-cutting, кроме фундамента и `match_source` (они в волнах 1 и 4).

**Зависит от:** Волны 2 и 3 (есть на чём демонстрировать).

| Карточка | Что делается |
|---|---|
| [cabinet-search-cross-cutting](tasks/backlog/2026-04-28_cabinet-search-cross-cutting.md) | A-2 пресеты, A-3 шаринг URL аудит, A-6 экспорт CSV/XLSX, A-8 EmptyState |

### PR-план

- **PR 5.1** — Пресеты (миграция `user_search_presets`, `SearchPresetController`, `<SavedSearches />`, smoke в Orders) + флаг `CABINET_SEARCH_PRESETS`.
- **PR 5.2** — Экспорт CSV/XLSX (общий trait или базовый контроллер) — Orders → Returns → Shipments. Флаг `CABINET_SEARCH_EXPORT`.
- **PR 5.3** — EmptyState с suggestion (серверное поле `suggestion` в ответах) + UI `<EmptyState>`. Флаг `CABINET_SEARCH_SUGGESTIONS`.
- **PR 5.4** — Аудит шаринга URL во всех разделах + точечные доделки. Без флага (точечные правки `withQueryString()`).

### Стратегия доставки

- Каждая фича — самостоятельный PR + флаг. Включаются независимо.
- **Пресеты** — единственная волна с миграцией БД. Бэкап БД перед деплоем (стандартная практика).

### Фича-флаги

| Env | Default prod | Описание |
|---|---|---|
| `CABINET_SEARCH_PRESETS` | `false` | Сохранённые поиски |
| `CABINET_SEARCH_EXPORT` | `false` | Кнопка «Экспорт» в шапке таблиц |
| `CABINET_SEARCH_SUGGESTIONS` | `false` | EmptyState с подсказками |

---

## Волна 6 — Низкоприоритетные разделы

**Цель:** довести оставшиеся разделы до уровня «капризного клиента». Не блокирует ничего.

**Зависит от:** Волны 1 (для шаринга URL и `<SelectedFilters />`).

| Карточка | Что делается |
|---|---|
| [cabinet-search-carts-list](tasks/backlog/2026-04-28_cabinet-search-carts-list.md) | Поиск/фильтры/сортировка списка корзин (C-5.1 … C-5.5) |
| [cabinet-search-media](tasks/backlog/2026-04-28_cabinet-search-media.md) | Дата/размер/разрешение, fuzzy. **Перед стартом:** обсуждение owner-scope (C-8.4 — security-issue) |
| [cabinet-search-product-exports](tasks/backlog/2026-04-28_cabinet-search-product-exports.md) | Фильтры по дате/статусу, поиск по содержимому правил (C-9.1 … C-9.5) |

### PR-план

- **PR 6.1** — carts-list. Без флага.
- **PR 6.2** — media. **Сначала issue-обсуждение owner-scope с владельцем продукта.** После решения — PR с фильтрами + (опционально) ограничение видимости медиа за флагом `CABINET_MEDIA_OWNER_SCOPE`.
- **PR 6.3** — product-exports. Без флага. Поиск по JSON-правилам — отдельный sub-PR при необходимости.

### Стратегия доставки и откат

- carts-list / product-exports: аддитивно, revert по PR.
- media: owner-scope меняет видимость — обязательно за флагом, с поэтапным включением и наблюдением жалоб пользователей.

---

## Сводный чек-лист волн

- [x] **Волна 1** — Фундамент
- [ ] **Волна 2** — Документы (LIKE)
- [ ] **Волна 3** — Корзинно-избранное (LIKE)
- [ ] **Волна 4** — Fuzzy через Meilisearch
- [ ] **Волна 5** — UX-улучшения
- [ ] **Волна 6** — Низкоприоритетные разделы

## Замечания

- Полная инициатива — порядка **15–20 PR**. Не пытаться объединять волны в один PR.
- Для каждого PR — карточка backlog движется по канбану. После закрытия этапа → перемещение в `done/` (или промежуточная пометка «Этап 1 закрыт» в карточке).
- Если в процессе обнаруживаются новые требования (опечатки в карточках, пропуск сценария) — обновлять [docs/cabinet-search-scenarios.md](cabinet-search-scenarios.md), а не код в обход документа.
