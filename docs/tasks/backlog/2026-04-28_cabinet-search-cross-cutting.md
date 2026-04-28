# ЛК / Поиск: Кросс-разделы (UX-механизмы)

**Приоритет:** средний
**Создано:** 2026-04-28
**Roadmap:** [docs/cabinet-search-roadmap.md → Волны 4 и 5](../../cabinet-search-roadmap.md)
**Источник:** [docs/cabinet-search-scenarios.md Приложение А](../../cabinet-search-scenarios.md) — A-2, A-3, A-5, A-6, A-7, A-8

## Контекст

Несколько UX-возможностей повторяются во всех листингах кабинета и реализуются единообразно. Хелперы и базовые компоненты (A-1 история, A-4 чипы фильтров) **вынесены в [cabinet-search-foundation](2026-04-28_cabinet-search-foundation.md)** (Волна 1). В этой карточке остались более тяжёлые механизмы, которые требуют существующих потребителей — поэтому они идут в волнах 4 и 5.

## План реализации

### A-2. Сохранённые поиски (пресеты)

**Backend:**
1. Миграция `user_search_presets`: `id`, `user_id`, `section` (orders/returns/...), `name`, `filters` (JSON), `created_at`.
2. Контроллер `App\Http\Controllers\User\SearchPresetController` с методами `index/store/destroy`.
3. Routes: `/cabinet/search-presets/{section}`.

**Frontend:**
4. Компонент `<SavedSearches section="orders" current={filters} />`:
   - Список сохранённых пресетов в боковой колонке (или dropdown в шапке).
   - Кнопка «Сохранить как…» → диалог с именем.
   - Клик по пресету → `router.get` с применением фильтров.
   - Удаление крестиком.

### A-3. Шаринг URL

5. Аудит всех страниц кабинета: убедиться, что фильтры/поиск/сортировка/пагинация в query string и страница восстанавливается из URL. Большинство уже использует `withQueryString()` — задача проверить и достроить пробелы.

### A-4. Единый компонент `<SelectedFilters />`

6. Вынести [Pages/User/Products/SelectedFilters.jsx](../../../resources/js/Pages/User/Products/SelectedFilters.jsx) в общий путь `resources/js/components/cabinet/SelectedFilters.jsx`, параметризовать конфигом полей.

7. Подключить в Orders, Returns, Shipments, Carts, Favorites, Media, ProductExports.

### A-5. Подсветка совпадения и пометка источника

8. Договориться о контракте API: каждый элемент списка возвращает поля
   ```json
   {
     "match_source": "number|composition|comment|company|brand|...",
     "match_snippet": "Кроссовки Nike Air Max"
   }
   ```
9. Реализовать единый компонент `<MatchBadge source={...} snippet={...} />` — рендерит бейдж + подсветку через `<mark>`.

### A-6. Экспорт результата поиска

**Backend:**
10. Унифицированный trait или базовый контроллер с методом `export(Request, $format)` для каждого раздела:
    - `format`: `csv`, `xlsx`.
    - Тот же query, что и `index`, но без пагинации (chunk-stream).
    - Использовать существующие пакеты экспорта (Maatwebsite/Excel или OpenSpout — проверить, что уже есть).

**Frontend:**
11. Кнопка «Экспорт» в шапке таблицы каждого раздела с выбором формата.

### A-7. Debounce + автосабмит фильтров

12. Унифицировать через хук `useFilterForm(initial, { debounceMs: 400 })`:
    - При изменении любого поля — `router.get(url, filters, { preserveState: true })` через 400 мс.
    - Кнопка «Применить» становится не нужна (оставить «Сбросить»).
13. Применить во всех разделах (Orders, Returns, Shipments — сейчас form-submit; Media уже использует похожий механизм).

### A-8. «Ничего не найдено» с подсказками

14. Серверная подсказка в JSON-ответе: при `total = 0` возвращать поле `suggestion`:
   - Для текстовых запросов — топ-1 fuzzy-совпадение через Meilisearch.
   - Для номеров с дефисами — нормализованный вариант.
15. Фронтовый компонент `<EmptyState>` показывает: «Ничего не найдено. Возможно, вы имели в виду: <suggestion>?» с кликабельной ссылкой.

### Хелперы общего пользования

16. **`App\Support\Search\DocumentNumber`** (см. карточки `2026-04-28_cabinet-search-orders.md` и др.):
    ```php
    final class DocumentNumber {
        public static function normalize(string $value): string
        {
            return preg_replace('/[\s\-]+/u', '', mb_strtolower($value));
        }

        public static function isLikelyBarcode(string $value): bool {
            return preg_match('/^\d{8,14}$/', $value) === 1;
        }

        public static function isLikelyTaxId(string $value): bool {
            return preg_match('/^\d{10}$|^\d{12}$/', $value) === 1;
        }
    }
    ```

17. **`App\Support\Search\QueryRouter`** — хелпер, классифицирующий пользовательский запрос: `text`, `barcode`, `tax_id`, `uuid`, `document_number`, `sku_like`. Используется во всех контроллерах кабинета для выбора правильной стратегии поиска.

## Критерии готовности

- [ ] Хук `useSearchHistory` реализован и подключён во всех разделах
- [ ] Модель + миграция `user_search_presets`
- [ ] Контроллер `SearchPresetController` с тестами
- [ ] UI пресетов работает в Orders (smoke-тест)
- [ ] Все страницы кабинета поддерживают шаринг URL (аудит проведён)
- [ ] Общий `<SelectedFilters />` подключён минимум в 4 разделах
- [ ] Все списочные endpoints возвращают `match_source`/`match_snippet`
- [ ] Компонент `<MatchBadge>` подключён в Orders, Returns, Shipments
- [ ] Экспорт CSV/XLSX работает в Orders, Returns, Shipments
- [ ] Debounce + автосабмит унифицированы во всех формах фильтров
- [ ] «Ничего не найдено» показывает подсказку при пустом результате
- [ ] Хелперы `DocumentNumber` и `QueryRouter` написаны и покрыты unit-тестами

## Технические заметки

- Эта карточка — **зависимость** для всех остальных карточек ЛК-поиска. Выделить хелперы и компонент `<SelectedFilters />` в первую очередь, чтобы переиспользовать.
- Сохранённые поиски можно начать с минимума (только UI без серверной модели — хранить в localStorage), при росте интереса — мигрировать на БД.
- Контракт `match_source` нужно зафиксировать одним документом (например, в `docs/cabinet-search-scenarios.md` добавить раздел «API contract»).

## Тесты

- Unit: `App\Support\Search\DocumentNumber`, `App\Support\Search\QueryRouter`.
- Feature: `tests/Feature/User/SearchPresetTest.php` — CRUD пресетов с проверкой scope.
- Feature: smoke-тест экспорта в одном из разделов.
- Frontend: компонент `<SelectedFilters />` — Storybook/тесты props.

## Зависимости

- Не блокируется ничем; при этом блокирует / упрощает все остальные карточки ЛК-поиска.
- **Рекомендация по порядку выполнения:**
  1. Сначала хелперы (`DocumentNumber`, `QueryRouter`) и общий `<SelectedFilters />`.
  2. Потом основные разделы (Orders, Returns, Shipments) — они используют хелперы.
  3. Затем UX-улучшения (пресеты, экспорт, подсказки) — могут быть реализованы независимо.
