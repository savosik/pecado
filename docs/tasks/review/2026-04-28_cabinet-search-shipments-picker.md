# ЛК / Поиск: Выбор реализации при создании возврата

**Приоритет:** средний
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §3](../../cabinet-search-scenarios.md) — сценарии C-3.1 … C-3.4

## Контекст

При создании возврата клиент в форме [ReturnItemsEditor](../../../resources/js/Admin/Components/ReturnItemsEditor.jsx) ищет реализацию по номеру. Сейчас это endpoint `GET /cabinet/returns/search-shipments` ([ReturnController.php:234-264](../../../app/Http/Controllers/User/ReturnController.php)) с LIKE по `number` и `erp_number`, лимит 20.

Капризный клиент:
- не помнит номер реализации, но помнит, что в ней были «Кроссовки Air Max»;
- хочет видеть в подсказке дату и сумму, чтобы быстрее опознать;
- хочет видеть, есть ли уже открытые возвраты по этой реализации (чтобы не дублировать).

## Текущая реализация

- Backend: [ReturnController::searchShipments](../../../app/Http/Controllers/User/ReturnController.php) — LIKE по `number`, `erp_number`. Возвращает `id`, `number`, `erp_number`, `date`, `total_amount`, `items_count`. Лимит 20, без пагинации.
- Frontend: [ReturnItemsEditor.jsx:272-296, 387-438](../../../resources/js/Admin/Components/ReturnItemsEditor.jsx) — debounce 250 мс, выпадайка с найденными реализациями.

## План реализации

### Этап 1. Расширение поиска по составу

**Backend ([ReturnController::searchShipments](../../../app/Http/Controllers/User/ReturnController.php)):**

1. **Поиск по товару в составе** (C-3.2):
   ```php
   $query->where(function ($sub) use ($q, $normalized) {
       $sub->where('number', 'like', "%{$q}%")
           ->orWhere('erp_number', 'like', "%{$q}%")
           ->orWhereHas('items.product', function ($p) use ($q) {
               $p->where('name', 'like', "%{$q}%")
                 ->orWhere('sku', 'like', "%{$q}%")
                 ->orWhere('code', 'like', "%{$q}%")
                 ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $q));
           });
   });
   ```
   Эвристика: если запрос — 8/12/13/14 цифр → точный поиск по barcode.

2. **Нормализация номера** — общий хелпер `App\Support\Search\DocumentNumber::normalize()`.

3. **Подсветка совпадения по составу** — в ответе добавить `match_source` (`number`/`erp_number`/`composition`) и для composition включить `match_product` (`{name, sku}` найденного товара) — UI отобразит «…содержит: Кроссовки Nike Air Max 90».

### Этап 2. Подсчёт открытых возвратов

4. **`open_returns_count`** (C-3.4) — добавить в ответ через subquery:
   ```php
   ->withCount(['returns as open_returns_count' => function ($q) {
       $q->whereNotIn('status', ['closed', 'cancelled']);
   }])
   ```
   (Связь `Shipment hasMany Return` через `ReturnItem.shipmentItem.shipment_id` — возможно потребуется промежуточный hasManyThrough или плоский подзапрос.)

### Этап 3. UI улучшения

**Frontend ([ReturnItemsEditor.jsx](../../../resources/js/Admin/Components/ReturnItemsEditor.jsx)):**

5. Плейсхолдер: `«Номер реализации, товар, артикул…»` (вместо «Например, 29УТ-003413»).

6. В выпадайке для каждой строки реализации (C-3.3):
   - Заголовок: `№ {number} · {date} · {total_amount} ₽` (часть полей уже есть, проверить актуальное отображение).
   - Подзаголовок (если `match_source = 'composition'`): `…содержит: <match_product.name>`.
   - Бейдж (если `open_returns_count > 0`): `⚠ {N} открытый возврат`.

7. Сортировка результатов: точные совпадения по номеру выше fuzzy по составу.

### Этап 4. Fuzzy для названий товаров и брендов (опционально)

8. Если в проекте уже подключён Meilisearch-индекс по реализациям (см. карточку `2026-04-28_cabinet-search-shipments.md`) — переиспользовать его и здесь, ограничив лимит 20.

## Критерии готовности (PR 2.4 — Этапы 1–3, без fuzzy)

- [x] Поиск находит реализацию по части номера (с дефисом и без)
- [x] Поиск находит реализацию по названию товара в составе
- [x] Поиск находит реализацию по `sku` товара (точное/префиксное)
- [x] Поиск по штрихкоду — точный матч (эвристика 8/12/13/14 цифр через `QueryRouter`)
- [x] В подсказке отображается номер, дата, сумма
- [x] При совпадении по составу видно, какой товар найден (`match_source` + `match_product`)
- [x] Бейдж «⚠ N открытых возвратов» показан, если есть (`open_returns_count` через `selectSub`)
- [x] Дедупликация (реализация в выдаче один раз — обеспечивается `whereHas` EXISTS)
- [x] Сортировка: точные по номеру → fuzzy по составу (на frontend по `match_source`)
- [x] Лимит 20 сохранён
- [x] Покрыто feature-тестом (`tests/Feature/User/ReturnShipmentPickerTest.php` — 12 кейсов)

Этап 4 (fuzzy через Meilisearch) перенесён в волну 4.

## Технические заметки

- Плотный `whereHas('items.product')` без индекса по `shipment_items(product_id)` может тормозить — проверить план запроса; добавить индекс при необходимости.
- `open_returns_count` через `withCount` с условием — нужен корректный путь связи. Если прямой `hasMany Returns` нет, использовать subquery `selectSub`.
- Эвристика «строка из ≥8 цифр → штрихкод» применяется и здесь.

## Тесты

- Feature: `tests/Feature/User/ReturnShipmentPickerTest.php`:
  - поиск по номеру
  - поиск по части номера без дефиса
  - поиск по названию товара
  - поиск по `sku`
  - поиск по штрихкоду
  - `open_returns_count` корректен (включает только pending/confirmed/ready_to_ship)
  - scope: видны только реализации текущего пользователя
  - лимит 20 соблюдается

## Зависимости

- Хелпер `App\Support\Search\DocumentNumber` — общий с задачами `2026-04-28_cabinet-search-orders.md`, `-returns.md`, `-shipments.md`.
- Желательно делать после или одновременно с `2026-04-28_cabinet-search-shipments.md`, чтобы переиспользовать тот же индекс/паттерн.
