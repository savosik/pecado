# ЛК / Поиск: Выгрузки товаров

**Приоритет:** низкий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §9](../../cabinet-search-scenarios.md) — сценарии C-9.1 … C-9.5

## Контекст

В `/cabinet/product-exports` сейчас поиск только по названию выгрузки ([ProductExportController::index](../../../app/Http/Controllers/User/ProductExportController.php)). У клиента может быть 50+ выгрузок, и он хочет найти ту, в которой есть фильтр по бренду Adidas.

## Текущая реализация

- Backend: [ProductExportController.php:27-45](../../../app/Http/Controllers/User/ProductExportController.php) — LIKE по `name`. Без фильтров. Сортировка отсутствует. Пагинация 15.
- Frontend: [Cabinet/ProductExports/Index.jsx](../../../resources/js/Pages/User/Cabinet/ProductExports/Index.jsx) — простое поле поиска без debounce.

## План реализации

### Этап 1. Базовые фильтры и сортировка

**Backend ([ProductExportController::index](../../../app/Http/Controllers/User/ProductExportController.php)):**

1. **Фильтр по дате** (C-9.2):
   ```php
   if ($from = $request->input('created_from')) $query->whereDate('created_at', '>=', $from);
   if ($to   = $request->input('created_to'))   $query->whereDate('created_at', '<=', $to);
   if ($lf   = $request->input('last_run_from'))$query->whereDate('last_run_at', '>=', $lf);
   if ($lt   = $request->input('last_run_to'))  $query->whereDate('last_run_at', '<=', $lt);
   ```

2. **Фильтр по статусу** (C-9.3) — если поле `status` есть в `ProductExport`. Если нет — задача расширения модели (например, добавить `is_active`).

3. **Сортировка** (C-9.5) — параметр `sort`:
   - `created_desc` (default) / `created_asc`
   - `name_asc` / `name_desc`
   - `last_run_desc` / `last_run_asc`
   - `result_size_desc` — по `last_run_count` (если такое поле есть; иначе через relation/agg).

### Этап 2. Поиск по содержимому правил

**Backend:**

4. **Поиск по упомянутым в фильтрах брендам/категориям** (C-9.4) — самый интересный сценарий. Подходы:

   **Вариант А (быстро, ограниченно):** добавить колонку `filters_text` (TEXT), при сохранении выгрузки рендерить туда строку с именами всех упомянутых брендов/категорий (`'Adidas Nike Reebok ' + 'Обувь Одежда'`). Искать `LIKE`.

   **Вариант B (правильно):** новые таблицы `product_export_filter_brands(export_id, brand_id)`, `product_export_filter_categories(...)` — обновлять при сохранении, индексировать. Искать `whereHas`.

   **Рекомендация:** начать с варианта А (одна миграция + observer), при росте сложности — мигрировать на B.

5. Расширить условие поиска:
   ```php
   $query->where(function ($q) use ($search) {
       $q->where('name', 'like', "%{$search}%")
         ->orWhere('filters_text', 'like', "%{$search}%");
   });
   ```

### Этап 3. Frontend

**[Cabinet/ProductExports/Index.jsx](../../../resources/js/Pages/User/ProductExports/Index.jsx):**

6. Поле поиска: «Поиск по названию или фильтрам выгрузки…», debounce 400 мс.

7. Кнопка «Фильтры» с popover:
   - Дата создания с / по.
   - Дата последнего запуска с / по.
   - Чекбокс «Активные» / «Архивные» (если поле есть).

8. Селект сортировки.

9. Чипы активных фильтров.

## Критерии готовности

- [ ] Фильтр по дате создания работает
- [ ] Фильтр по дате последнего запуска работает
- [ ] Сортировка по 4 полям
- [ ] Поиск по упомянутым брендам/категориям в фильтрах выгрузки (вариант А)
- [ ] Observer обновляет `filters_text` при сохранении выгрузки
- [ ] Debounce 400 мс на поле поиска
- [ ] Чипы активных фильтров
- [ ] Покрыто feature-тестами (`tests/Feature/User/ProductExportSearchTest.php`)

## Технические заметки

- Структура `ProductExport.filters` — JSON с иерархией (см. [ProductExportService](../../../app/Services/ProductExport)). Денормализация в `filters_text` через observer/event-listener гарантирует актуальность.
- Если `last_run_at`/`last_run_count` нет в схеме — добавить миграцией.
- При варианте B — обсудить с владельцем продукта необходимость, возможно overengineering для текущих объёмов.

## Тесты

- Feature: `tests/Feature/User/ProductExportSearchTest.php`:
  - поиск по названию
  - поиск по бренду в фильтрах (через `filters_text`)
  - фильтр по дате
  - сортировка
  - scope: чужие выгрузки не находятся

## Зависимости

- Возможна миграция `product_exports.filters_text` + observer.
- Решение по полю `status`/`is_active`.
