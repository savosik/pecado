# ЛК / Поиск: Возвраты

**Приоритет:** высокий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §2](../../cabinet-search-scenarios.md) — сценарии C-2.1 … C-2.6

## Контекст

Самый узкий поиск в кабинете — `/cabinet/returns` ищет только по `uuid`, `erp_number`, `id` ([ReturnController.php:34-40](../../../app/Http/Controllers/User/ReturnController.php)). Капризный клиент хочет искать возвраты по составу, по номеру исходной реализации, по тексту комментария к причине, и фильтровать по нескольким причинам одновременно.

## Текущая реализация

- Backend: [ReturnController.php:24-72](../../../app/Http/Controllers/User/ReturnController.php) — Eloquent LIKE по 3 полям; фильтр `reason` через `whereHas('items')` (single-select).
- Frontend: [Returns/Index.jsx](../../../resources/js/Pages/User/Cabinet/Returns/Index.jsx). Без debounce. Без бейджа активных фильтров.

## План реализации

### Этап 1. Расширение поиска (LIKE)

**Backend ([ReturnController.php](../../../app/Http/Controllers/User/ReturnController.php)):**

1. **Нормализация номера** (C-2.1) — использовать общий хелпер `App\Support\Search\DocumentNumber::normalize()` (см. карточку `2026-04-28_cabinet-search-orders.md`).

2. **Поиск по номеру исходной реализации** (C-2.2) — расширить условие:
   ```php
   ->orWhereHas('items.shipmentItem.shipment', function ($q) use ($search, $normalized) {
       $q->where('number', 'like', "%{$search}%")
         ->orWhere('erp_number', 'like', "%{$search}%")
         ->orWhere('uuid', 'like', "%{$search}%");
   });
   ```
   В выдаче `match_source = 'shipment'` + `match_snippet` с номером реализации.

3. **Поиск по составу** (C-2.3):
   ```php
   ->orWhereHas('items.shipmentItem.product', function ($q) use ($search) {
       $q->where('name', 'like', "%{$search}%")
         ->orWhere('sku', 'like', "%{$search}%")
         ->orWhere('code', 'like', "%{$search}%")
         ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search));
   });
   ```
   Эвристика для штрихкода (8/12/13/14 цифр) — точное совпадение.

4. **Поиск по тексту комментария к причине** (C-2.4):
   ```php
   ->orWhereHas('items', fn ($q) => $q->where('reason_comment', 'like', "%{$search}%"));
   ```

5. **Дедупликация** — `->distinct()` или `->select('returns.*')`.

6. **`match_source` в ответе:** `number`/`shipment`/`composition`/`reason_comment`.

**Frontend ([Returns/Index.jsx](../../../resources/js/Pages/User/Cabinet/Returns/Index.jsx)):**

7. Плейсхолдер: `«Поиск по номеру возврата, реализации, товару…»`.
8. Debounce 400 мс.
9. Бейдж `activeFiltersCount` на кнопке «Фильтры» (сейчас отсутствует — есть только в Orders/Shipments).

### Этап 2. Множественные фильтры

10. **Multi-select по причине** (C-2.5) — заменить `where('reason', $reason)` на `whereIn('reason', $reasons)` в `whereHas('items')`. Frontend: [Returns/Index.jsx:162-179](../../../resources/js/Pages/User/Cabinet/Returns/Index.jsx) — single-select Select заменить на CheckboxGroup в Popover, с чипами выбранного.

11. **Multi-select по статусу** — аналогично.

### Этап 3. Fuzzy для названий товаров и брендов

12. Косвенная индексация `Return` в Meilisearch (как для `Order` в `2026-04-28_cabinet-search-orders.md`): индекс хранит `id`, `erp_number`, `comment_text` (concat `reason_comment` всех позиций), `items_text` (concat product.name + brand.name).

13. Маршрутизация: цифры/UUID/артикул → LIKE; русский/латинский текст → Meilisearch.

14. Объединять и дедуплицировать результаты обоих источников.

### Этап 4. Сохранённые поиски (опционально)

15. «Все возвраты по браку за квартал» (C-2.6) — см. кросс-задачу.

## Критерии готовности

### PR 2.2 (Этап 1 + 2, LIKE) — закрыт
- [x] Поиск находит возврат по части номера (с дефисом и без) — нормализация `REPLACE(REPLACE(erp_number, '-', ''), ' ', '')`.
- [x] Поиск находит возврат по номеру исходной реализации (`whereHas('items.shipmentItem.shipment')`).
- [x] Поиск находит возврат по названию товара в составе (LIKE по `product.name/sku/code`).
- [x] Поиск находит возврат по бренду в составе.
- [x] Поиск по 13-значному штрихкоду — точный матч (`QueryRouter::TYPE_BARCODE` → `whereHas('items.shipmentItem.product.barcodes')`).
- [x] Поиск по `sku` — LIKE-подстрока.
- [x] Поиск по тексту комментария причины (`whereHas('items', reason_comment LIKE)`).
- [x] Multi-select по причине: `?reason[]=` (бэк + multiple Select на фронте).
- [x] Multi-select по статусу: `?status[]=`.
- [x] Бейдж количества активных фильтров на кнопке «Фильтры».
- [x] Debounce 400 мс на поле поиска (`useEffect` + `setTimeout`).
- [x] Дедупликация — EXISTS-подзапросы, дубли исключены.
- [x] Покрыто feature-тестами (`tests/Feature/User/ReturnSearchTest.php` — 10 тестов / 30 ассертов).
- [x] Pint + PHPStan чистые на PR-файлах (PHPStan baseline 23 не изменился).
- [x] Попутно: исправлена устаревшая `ReturnItemFactory` (поле `order_id` удалено миграцией 2026-04-21, без правки фабрики никакие feature-тесты с `ReturnItem::factory()` не проходили).

### Не вошло в PR 2.2 — зависит от других PR
- [ ] Подсветка `match_source`/`match_snippet` — отнесено к **PR 4.4**.
- [ ] Fuzzy для названий товаров и брендов — **PR 4.2**.
- [ ] Подключение `<SelectedFilters />` и `useSearchHistory` — **PR 2.5**.

## Технические заметки

- Связи: `Return → ReturnItem → ShipmentItem → Shipment` и `ShipmentItem → Product → Barcode/Brand`. Глубокий `whereHas` стоит профилировать на больших выборках.
- При большом числе позиций возвратов индекс Meilisearch может расти быстро — реиндексация при `ReturnItem::saved` может быть тяжёлой; рассмотреть batch-mode (раз в N минут).
- Подсветка `match_snippet` — клиентская через `<mark>`.

## Тесты

- Feature: `tests/Feature/User/ReturnSearchTest.php`:
  - поиск по части номера возврата
  - поиск по номеру исходной реализации (находит возвраты, чьи позиции ссылаются на эту реализацию)
  - поиск по названию товара (LIKE + fuzzy)
  - поиск по штрихкоду (точное)
  - поиск по комментарию причины
  - multi-select reasons: возвраты с любой из выбранных причин
  - multi-select statuses
  - дедупликация
  - scope: пользователь не видит чужие возвраты при поиске

## Зависимости

- Хелпер `App\Support\Search\DocumentNumber` — общий с задачей по заказам.
- Meilisearch-индекс `returns` — отдельный, но архитектурно похож на `orders`.
- Связана с карточкой `2026-04-28_cabinet-search-shipments-picker.md` — поиск реализаций при создании возврата (тот же домен, но другой UI).
