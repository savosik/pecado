# ЛК / Поиск: Отгрузки

**Приоритет:** средний
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §4](../../cabinet-search-scenarios.md) — сценарии C-4.1 … C-4.7

## Контекст

Из всех документов в кабинете `/cabinet/shipments` имеет самый продвинутый поиск ([ShipmentController.php:45-56](../../../app/Http/Controllers/User/ShipmentController.php)) — он ищет даже по составу (`items.product.name`, `items.product.sku`). Не хватает: бренд, штрихкод, категория, склад, fuzzy для опечаток, поиск отгрузок по UUID связанного заказа.

## Текущая реализация

- Backend: [ShipmentController.php:31-90](../../../app/Http/Controllers/User/ShipmentController.php) — LIKE по `uuid`, `number`, `erp_number`, `tax_id`, `items.product.name`, `items.product.sku`. Фильтры: `status`, `date_from/to`, `amount_from/to`. Сортировка по 4 полям.
- Frontend: [Shipments/Index.jsx](../../../resources/js/Pages/User/Cabinet/Shipments/Index.jsx). Без debounce.

## План реализации

### Этап 1. Расширение поиска по составу

**Backend ([ShipmentController.php](../../../app/Http/Controllers/User/ShipmentController.php)):**

1. **Нормализация номера** — общий хелпер `App\Support\Search\DocumentNumber::normalize()`.

2. **Поиск по бренду в составе** (C-4.2):
   ```php
   ->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"))
   ```

3. **Поиск по штрихкоду в составе** (C-4.3):
   ```php
   ->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search))
   ```
   Эвристика: 8/12/13/14 цифр → точное совпадение.

4. **Поиск по коду товара** — `code` в существующее условие `whereHas('items.product')`.

5. **Поиск отгрузок по UUID заказа** (C-4.6) — новый параметр `order_uuid`:
   ```php
   if ($orderUuid = $request->input('order_uuid')) {
       $query->whereHas('items', fn ($q) => $q->where('order_uuid', $orderUuid));
   }
   ```
   Frontend: на странице заказа кнопка «Все отгрузки» → `/cabinet/shipments?order_uuid=...`.

6. **`match_source` в ответе** (`number`/`tax_id`/`composition`/`brand`).

### Этап 2. Дополнительные фильтры

7. **Фильтр по бренду** (C-4.2 как фасет) — multi-select со списком брендов из истории отгрузок пользователя:
   ```php
   $query->whereHas('items.product', fn ($q) => $q->whereIn('brand_id', $brandIds));
   ```

8. **Фильтр по категории товара в составе** (C-4.4) — multi-select; через `whereHas('items.product.categories')` с поддержкой потомков (nestedset).

9. **Фильтр по складу/региону** (C-4.7) — если у `Shipment` есть поле `warehouse_id`/`region_id`. Если нет — отдельная задача на расширение модели.

10. **Multi-select по статусу**.

### Этап 3. UI

**Frontend ([Shipments/Index.jsx](../../../resources/js/Pages/User/Cabinet/Shipments/Index.jsx)):**

11. Плейсхолдер: `«Поиск по номеру, ИНН, товару, бренду, артикулу, штрихкоду…»`.
12. Debounce 400 мс на поле поиска (сейчас form-submit).
13. Бейдж `match_source` (например, `«✓ найдено в составе: Adidas Superstar»`).
14. Подсветка `match_snippet` через `<mark>`.

### Этап 4. Fuzzy для названий товаров и брендов

15. Косвенная индексация `Shipment` в Meilisearch: `id`, `number`, `erp_number`, `tax_id`, `items_text` (concat product.name + brand.name).

16. Маршрутизация: цифры/UUID/sku → LIKE, текст → Meilisearch + LIKE объединённо. Дедуплицировать по `id`.

17. Реиндексация при изменении состава — переоформить родительский `Shipment` через event-listener `ShipmentItem::saved`/`deleted`.

## Критерии готовности

### PR 2.3 (Этап 1, LIKE) — закрыт
- [x] Поиск по бренду в составе (LIKE).
- [x] Поиск по штрихкоду — точный матч (через `QueryRouter::TYPE_BARCODE`).
- [x] Поиск по `code` товара (LIKE по `product.code`).
- [x] Поиск отгрузок по UUID связанного заказа (`?order_uuid=...`).
- [x] Фильтр по бренду (`brand_ids[]` — query-only; UI multi-select откладывается до фасета «топ брендов из истории»).
- [x] Multi-select по статусу: `?status[]=...`, фронт — `Select.Root multiple`.
- [x] Debounce 400 мс (`useEffect` + `setTimeout`).
- [x] Дедупликация — EXISTS-подзапросы.
- [x] Покрыто feature-тестами (`tests/Feature/User/ShipmentSearchTest.php` — 9 тестов / 26 ассертов).
- [x] Pint + PHPStan чистые на PR-файлах (PHPStan baseline 29 не изменился).
- [x] Нормализация номера отгрузки (`REPLACE(REPLACE(number, '-', ''), ' ', '')`) — попутно с C-4.1.

### Не вошло в PR 2.3 — зависит от других PR / исследований
- [ ] Подсветка `match_source`/`match_snippet` — отнесено к **PR 4.4**.
- [ ] Fuzzy для названия товара / бренда (русские опечатки) — **PR 4.2**.
- [ ] Подключение `<SelectedFilters />` и `useSearchHistory` — **PR 2.5**.
- [ ] Кнопка «Все отгрузки» на странице заказа — отдельная UI-задача (нужен переход с страницы Order/Show).
- [ ] Фильтр по категории (с поддержкой подкатегорий) — **Этап 2**, требует рассмотрения performance JOIN'а на больших выборках.
- [ ] Фильтр по складу/региону — поле в `Shipment` отсутствует, нужна отдельная задача на расширение модели.
- [ ] UI multi-select бренда — нужен фасет «топ-N брендов из истории отгрузок пользователя».

## Технические заметки

- Существующий `whereHas('items.product')` корректно работает с правами пользователя (`where('user_id', ...)` на `Shipment`).
- Если `warehouse_id`/`region_id` нет в схеме `Shipment` — фильтр C-4.7 вынести в отдельную задачу с миграцией.
- Связь `ShipmentItem.order_uuid` существует — новый параметр `order_uuid` потребует только индекса по этой колонке для производительности.

## Тесты

- Feature: `tests/Feature/User/ShipmentSearchTest.php`:
  - поиск по бренду (LIKE + fuzzy)
  - поиск по штрихкоду
  - поиск по `code`
  - параметр `order_uuid` фильтрует только отгрузки с этим заказом
  - фильтр по бренду (multi-select)
  - фильтр по категории с потомками
  - дедупликация
  - scope: только свои отгрузки

## Зависимости

- Хелпер `App\Support\Search\DocumentNumber` — общий.
- Meilisearch-индекс `shipments` — связан с `2026-04-28_cabinet-search-shipments-picker.md` (тот же индекс).
- Для фильтра по складу: проверить наличие поля в `Shipment`; при отсутствии — отдельная задача.
