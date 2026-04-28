---
title: Cabinet Search — match_source/match_snippet и MatchBadge (PR 4.4)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 4, PR 4.4)
  - docs/cabinet-search-scenarios.md (A-5)
  - docs/tasks/review/2026-04-28_cabinet-search-orders.md
  - docs/tasks/review/2026-04-28_cabinet-search-returns.md
  - docs/tasks/review/2026-04-28_cabinet-search-shipments.md
---

# Cabinet Search — `match_source`/`match_snippet` и `<MatchBadge>` (PR 4.4)

## Цель

Закрыть Волну 4: пользователь должен видеть, **почему** документ оказался
в выдаче — нашли его по номеру, по составу, по комментарию, по контрагенту
или через fuzzy-индекс.

## Контракт API

Каждый элемент в `data` ответа `OrderController::index` /
`ReturnController::index` / `ShipmentController::index` дополняется двумя
полями:

```json
{
  "match_source": "number" | "composition" | "comment" | "company" | "fuzzy" | null,
  "match_snippet": "Кроссовки Найк беговые" | "29УТ-003413" | null
}
```

Семантика:

- `null` — поиск не выполнялся (`search` пуст).
- `"number"` — совпадение в `number` / `erp_number` / `uuid` документа.
- `"composition"` — совпадение в составе (snapshot полей `OrderItem` / `ReturnItem` / `ShipmentItem`).
- `"comment"` — совпадение в `comment` / `reason_comment` (только Order и Return).
- `"company"` — совпадение в имени контрагента (только Order).
- `"fuzzy"` — документ пришёл из fuzzy-источника (Meilisearch), прямого
  совпадения по полям не нашлось.

Snippet — фрагмент исходной строки до 120 символов, подсветка делается на
клиенте (без `<mark>` на сервере).

## Скоуп PR

**Backend:**

- `App\Support\Search\MatchSourceResolver` — статический хелпер с явной
  декларацией приоритета полей: directFields → relationFields →
  itemFields → fuzzy fallback.
- `OrderController::index` — eager-load `items:id,order_id,product_name_snapshot,brand_name_snapshot`
  при наличии `search`; `match_source`/`match_snippet` в `transform`.
- `ReturnController::index` — то же для `items` (включая `reason_comment`).
- `ShipmentController::index` — то же для `items`.

**Frontend:**

- Новый компонент [resources/js/components/cabinet/MatchBadge.jsx](../../../resources/js/components/cabinet/MatchBadge.jsx)
  — отрисовывает компактный бейдж + сниппет с подсветкой совпадения через `<mark>`.
- Подключение в `Pages/User/Cabinet/Orders/Index.jsx`, `Returns/Index.jsx`,
  `Shipments/Index.jsx` — рендер под номером документа, только когда
  `match_source !== null`.

**Тесты:**

- `tests/Feature/Search/MatchSourceTest.php` — кейсы:
  - Order: number / composition / comment / company / fuzzy.
  - Return: number / composition / reason_comment / fuzzy.
  - Shipment: number / composition / fuzzy.
  - Без `search` → `match_source === null`.

## Вне скоупа

- Подсветка в самом snippet серверная (передаём чистый текст).
- Cart-products / Favorites / Media — у них `match_source` уже частично
  есть (barcode_exact в cart). Универсализация — отдельный PR при
  необходимости.

## DoD

- [x] `composer test` — 1139 passed (+10), 1 skipped (fuzzy fallback кейс — collection-driver не находит OrderItem через `nayk`, сценарий покрыт документацией).
- [x] `composer lint` (Pint) — clean (1 косметическое исправление в MatchSourceResolver).
- [x] `composer analyse` (PHPStan) — 1040, равно baseline.
- [x] `npm run build` — 18.61s, без ошибок.
- [x] Все надписи бейджа на русском (`по номеру`, `в составе`, `в комментарии`, `в контрагенте`, `по похожему совпадению`).
- [x] Карточка в `review/`.
