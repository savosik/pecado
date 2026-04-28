---
title: Cabinet Search — EmptyState с suggestion (PR 5.3)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 5, PR 5.3)
  - docs/cabinet-search-scenarios.md (A-8)
---

# Cabinet Search — EmptyState с suggestion (PR 5.3)

## Цель

Когда поиск/фильтры не дают результатов, не оставлять пользователя со
сухой надписью «ничего не найдено» — подсказывать, что можно сделать
(сбросить часть фильтров, проверить написание поискового запроса).
Включается флагом `CABINET_SEARCH_SUGGESTIONS` (default `false`).

## Скоуп PR

**Backend:**
- `App\Support\Search\EmptyResultSuggestion` — статический хелпер
  `build(string $search, array $activeFilters): ?string`. Возвращает
  готовую человеческую подсказку либо `null`, если флаг выключен/выдача
  не пуста (вызывается контроллером уже после проверки `total===0`).
  Контракт текстов:
  - Если `search !== ''` → «Проверьте написание запроса «X» или попробуйте
    более короткое ключевое слово.»
  - Если фильтры `>0` → «Попробуйте сбросить фильтры: <список>.»
  - Если оба условия — оба совета через `\n`.
- В каждом контроллере (Order/Return/Shipment) после `paginate()` если
  `total === 0` и `config('search-cabinet.suggestions')` — вызвать
  хелпер и передать `'suggestion' => $text` в Inertia render.

**Frontend:**
- В существующих empty-state блоках Orders/Returns/Shipments — рендерить
  `suggestion` (string|null) под заголовком «Ничего не найдено».
- Не создаём отдельный компонент — рендеринг 3-4 строк в каждом разделе
  достаточен. Если в будущем понадобится единый компонент — можно
  выделить.

**Тесты:**
- `tests/Feature/Search/EmptyResultSuggestionTest.php`:
  - off флаг → suggestion null даже при пустой выдаче.
  - on флаг + search + фильтры → suggestion содержит оба совета.
  - on флаг + search → только совет про написание.
  - on флаг + фильтры → только совет про сброс.
  - on флаг + есть результаты → suggestion null (не показывается).

## Вне скоупа

- Fuzzy-предложения «возможно, вы имели в виду» через Meilisearch — это
  отдельная история (требует Meilisearch suggestions endpoint).
- Carts / Favorites / Media / ProductExports — отдельный быстрый PR.

## DoD

- [x] `composer test` — 1164 passed (+6), 1 skipped.
- [x] `composer lint` (Pint) — clean (1 косметическое автоисправление).
- [x] `composer analyse` (PHPStan) — 1027, на 13 меньше baseline.
- [x] `npm run build` — 18.20s, без ошибок.
- [x] Флаг `CABINET_SEARCH_SUGGESTIONS` в `config/search-cabinet.php` + `.env.example`.
- [x] Все надписи на русском.
- [x] При выключенном флаге `suggestion` всегда `null`, UI без изменений.
- [x] Карточка в `review/`.
