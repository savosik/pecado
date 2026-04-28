# ЛК / Поиск: Избранное

**Приоритет:** средний
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §7](../../cabinet-search-scenarios.md) — сценарии C-7.1 … C-7.7

## Контекст

В `/favorites` сейчас просто список избранных товаров без поиска, фильтров и сортировки ([FavoriteController.php:18-50](../../../app/Http/Controllers/User/FavoriteController.php)). Капризный клиент имеет 200+ товаров в избранном и хочет искать по названию/бренду/штрихкоду, фильтровать по наличию, сортировать по цене.

## Текущая реализация

- Backend: [FavoriteController::index](../../../app/Http/Controllers/User/FavoriteController.php) — `Product::query()->join('favorites')` + сортировка по `favorites.created_at DESC`. Использует `ProductQueryService::productEagerLoads()` (brand, media, tags) + конвертацию цен/скидок/остатков. Пагинация 20.
- Frontend: [Favorites/Index.jsx](../../../resources/js/Pages/User/Favorites/Index.jsx) — простой grid + пагинация.

## План реализации

### Этап 1. Поиск + сортировка

**Backend ([FavoriteController::index](../../../app/Http/Controllers/User/FavoriteController.php)):**

1. **Поиск по `name`/`sku`/`code`/`barcode`/`brand`** (C-7.1, C-7.3):
   ```php
   if ($search = $request->input('search')) {
       $query->where(function ($q) use ($search) {
           $q->where('products.name', 'like', "%{$search}%")
             ->orWhere('products.sku', 'like', "%{$search}%")
             ->orWhere('products.code', 'like', "%{$search}%")
             ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search))
             ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));
       });
   }
   ```
   Эвристика штрихкода: 8/12/13/14 цифр.

2. **Сортировка** (C-7.6) — параметр `sort`:
   - `added_desc` (default) — `favorites.created_at DESC`
   - `added_asc`
   - `price_asc` / `price_desc` — `products.base_price`
   - `name_asc` / `name_desc` — `products.name`
   - `stock_desc` — по `total_stock` (через subselect, как в `ProductQueryService::withRegionStockSums`)

3. **Фильтр «в наличии / нет в наличии / предзаказ»** (C-7.4):
   - Использовать `ProductQueryService::withRegionStockSums($query)` (уже подключён) и условие `having('region_total_stock', '>', 0)` для in_stock.
   - Для предзаказа — `having('region_preorder_stock', '>', 0)`.

4. **Фильтр по бренду / категории** (C-7.5) — `?brand_ids[]=`, `?category_ids[]=`. Через `whereIn('brand_id', ...)` и `whereHas('categories', ...)`.

### Этап 2. Frontend

**[Favorites/Index.jsx](../../../resources/js/Pages/User/Favorites/Index.jsx):**

5. Поле поиска в шапке: «Поиск по товару, бренду, артикулу, штрихкоду…» с debounce 400 мс.

6. Кнопка «Фильтры» с popover:
   - Чекбоксы наличия: «В наличии» / «Предзаказ» / «Нет в наличии».
   - Фасет «Бренд» (multi-select) — собирается из текущего избранного.
   - Фасет «Категория» (multi-select).

7. Селект сортировки в шапке (7 опций).

8. Toggle «Группировать по категории» (C-7.7) — клиентская группировка, без изменений сервера.

9. Чипы активных фильтров + кнопка «Сбросить».

### Этап 3. Fuzzy для названий и брендов

10. Если запрос текстовый — использовать существующий Meilisearch-индекс по `Product`. Скрестить с `Favorite` через `whereIn('products.id', $idsFromMeilisearch)`.

11. Точные идентификаторы (sku/barcode) — LIKE как в этапе 1.

## Критерии готовности

- [ ] Поиск находит товар в избранном по названию (LIKE)
- [ ] Поиск по бренду
- [ ] Точный матч по штрихкоду
- [ ] Поиск по `sku` (точное/префиксное)
- [ ] Сортировка по дате добавления (default), цене ↑/↓, имени, наличию
- [ ] Фильтр «в наличии» использует региональные склады пользователя
- [ ] Фильтр «предзаказ»
- [ ] Multi-select по бренду
- [ ] Multi-select по категории
- [ ] Группировка по категориям (клиентская)
- [ ] Debounce 400 мс
- [ ] Чипы активных фильтров + сброс
- [ ] Fuzzy для русских/латинских опечаток в названии и бренде
- [ ] Покрыто feature-тестом (`tests/Feature/User/FavoriteSearchTest.php`)

## Технические заметки

- Конвертация цен — в `FavoriteController::index` происходит **после** запроса в БД. Для сортировки по цене пользователя сортировать всё равно в БД по `base_price` — пересчёт после фактически не меняет порядок (валюта одна и та же для всех записей пользователя).
- Существующий метод `ProductQueryService::withRegionStockSums` добавляет `region_total_stock`, `region_preorder_stock` через subselect — переиспользовать для фильтра наличия.
- При группировке по категории на клиенте использовать `category_path` или main category — у `Product` уже есть `categories()` relation.

## Тесты

- Feature: `tests/Feature/User/FavoriteSearchTest.php`:
  - поиск по названию / бренду / штрихкоду / `sku`
  - сортировка по 7 вариантам
  - фильтр «в наличии» учитывает регион пользователя
  - фильтр «предзаказ»
  - multi-select по бренду / категории
  - fuzzy (через Meilisearch) по названию
  - scope: чужое избранное не находится

## Зависимости

- Существующий Meilisearch-индекс по `Product`.
- `ProductQueryService::withRegionStockSums` уже есть.
