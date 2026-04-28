# ЛК / Поиск: Заказы

**Приоритет:** высокий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §1](../../cabinet-search-scenarios.md) — сценарии C-1.1 … C-1.14

## Контекст

Сейчас поиск в `/cabinet/orders` ищет только по `uuid`, `number`, `erp_number`, `id` ([OrderController.php:41-47](../../../app/Http/Controllers/User/OrderController.php)). Капризный клиент-оптовик хочет искать заказы по составу (товар/бренд/артикул/штрихкод), по контрагенту по имени, по комментарию, и сохранять часто используемые наборы фильтров.

## Текущая реализация

- Backend: [OrderController.php:24-101](../../../app/Http/Controllers/User/OrderController.php) — Eloquent `where LIKE '%...%'` по 4 полям.
- Frontend: [Orders/Index.jsx](../../../resources/js/Pages/User/Cabinet/Orders/Index.jsx).
- Фильтры: `type`, `status`, `company_id`, `date_from/to`, `amount_from/to`. Без debounce, требует «Применить».

## План реализации

### Этап 1. Расширение точечного поиска (LIKE-only, без Meilisearch) — быстрая победа

**Backend ([OrderController.php](../../../app/Http/Controllers/User/OrderController.php)):**

1. **Нормализация номера документа** (C-1.1, C-2.1, C-4.1) — выделить хелпер `App\Support\Search\DocumentNumber::normalize(string)` (убирает пробелы и дефисы), искать одновременно по оригиналу и нормализованной форме. Хранить нормализованную форму в БД либо вычислять на лету (`REPLACE(REPLACE(number,'-',''),' ','')`).

2. **Поиск по составу заказа** (C-1.3, C-1.5, C-1.6, C-1.7) — расширить условие `where(...)`:
   ```php
   ->orWhereHas('items', function ($q) use ($search, $normalized) {
       $q->whereHas('product', function ($p) use ($search, $normalized) {
           $p->where('name', 'like', "%{$search}%")
             ->orWhere('sku', 'like', "%{$search}%")
             ->orWhere('code', 'like', "%{$search}%")
             ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search));
       });
   });
   ```
   Эвристика: если запрос — 8/12/13/14 цифр → искать как штрихкод (точное); если ≥10 цифр → ИНН (точное по `company.tax_id`).

3. **Поиск по контрагенту по имени и ИНН** (C-1.8, C-1.9):
   ```php
   ->orWhereHas('company', function ($q) use ($search) {
       $q->where('user_id', $userId)
         ->where(fn ($c) => $c->where('name', 'like', "%{$search}%")
                              ->orWhere('tax_id', 'like', "{$search}%"));
   });
   ```

4. **Поиск по комментарию** (C-1.10) — `orWhere('comment', 'like', "%{$search}%")`.

5. **Дедупликация:** `->distinct()` или `->select('orders.*')` если используются join'ы.

6. **Подсветка источника совпадения** (A-5) — добавить в ответе для каждого заказа поле `match_source` (`number`/`composition`/`comment`/`company`) и `match_snippet` (первое 80-символьное окно совпадения).

**Frontend ([Orders/Index.jsx](../../../resources/js/Pages/User/Cabinet/Orders/Index.jsx)):**

7. Обновить плейсхолдер: `«Поиск по номеру, товару, бренду, артикулу, ИНН…»`.
8. Debounce 400 мс на поле поиска (сейчас submit-only).
9. Бейдж `match_source` рядом с номером заказа: `«✓ найдено в составе»`, `«✓ совпадение в комментарии»`.
10. В развёрнутой строке показывать `match_snippet` с `<mark>`.

### Этап 2. Дополнительные фильтры

11. **Множественный фильтр по статусу** (сейчас single) — переключить на multi-select.
12. **Фильтр по диапазону количества позиций** (C-1.12) — добавить `items_count_from`, `items_count_to` (поле уже считается в `withCount('items')`).
13. **Фильтр «смешанные»** (C-1.11) — `type='mixed'` для заказов, у которых одни позиции `is_preorder=true`, другие — `false`.
14. **Параметр `product_id`** для сценария «Когда я это покупал?» (C-1.13) — `whereHas('items', fn ($q) => $q->where('product_id', $id))`. Кнопка на карточке товара ведёт на `/cabinet/orders?product_id=X`.

### Этап 3. Fuzzy для названий товаров и брендов

15. Подключить `Order` к Meilisearch через **косвенную индексацию**: индекс `orders` хранит `id`, `number`, `erp_number`, `comment`, `company_name`, `company_tax_id`, **+ агрегат `items_text` (concat product.name + brand.name + sku + code)**.
16. Реиндексация: при `OrderItem::saved/deleted` пересохранять родительский `Order` через `searchable()`.
17. Маршрутизация запроса: если запрос содержит русский/латинский текст без цифр → искать через Meilisearch (fuzzy для name/brand). Если запрос — цифры/UUID/артикул → LIKE как в этапе 1.
18. Объединять результаты двух источников (точный + fuzzy), дедуплицировать по `id`.

**Замечания по fuzzy** ([cabinet-search-scenarios.md §сквозные принципы](../../cabinet-search-scenarios.md)):
- Fuzzy применяется ТОЛЬКО к `product.name` и `brand.name`.
- Артикул (`sku`/`code`), штрихкод, UUID, ИНН, номер документа — точное/префиксное.
- Levenshtein ≤ 2 (Meilisearch default).

### Этап 4. UX-улучшения (опционально, можно вынести в кросс-задачу)

19. История последних 10 запросов (localStorage).
20. Сохранённые поиски — см. [`2026-04-28_cabinet-search-cross-cutting.md`](2026-04-28_cabinet-search-cross-cutting.md).

## Критерии готовности

- [ ] Поиск по части номера 1С работает с дефисом и без (`003413`, `29УТ-003413`, `29УТ003413`)
- [ ] Поиск находит заказ по названию товара в составе (с пометкой `match_source = 'composition'`)
- [ ] Поиск находит заказ по бренду в составе
- [ ] Поиск по 13-значному штрихкоду — точный матч, без LIKE
- [ ] Поиск по `sku` — точное и префиксное
- [ ] Поиск по названию контрагента и ИНН (только в рамках своих компаний)
- [ ] Поиск по комментарию работает
- [ ] Multi-select по статусу
- [ ] Параметр `product_id` фильтрует заказы с этим товаром
- [ ] Дедупликация: заказ, нашедшийся по двум критериям, в выдаче один раз
- [ ] Дебаунс 400 мс на поле поиска
- [ ] Fuzzy для русских опечаток в названии товара (`кросовки` → находит «Кроссовки»)
- [ ] Fuzzy для опечаток в бренде (`addidas` → «Adidas»)
- [ ] Покрыто feature-тестами (`tests/Feature/User/OrderSearchTest.php`)

## Технические заметки

- Эвристика «строка из ≥8 цифр → штрихкод» позволяет избежать LIKE по barcode для коротких запросов.
- Существующий fuzzy-поиск по продуктам уже сделан в каталоге через Scout — переиспользовать [docs/MEILISEARCH.md](../../MEILISEARCH.md).
- Подсветка `match_snippet` — клиентская, через библиотеку `mark.js` или собственный split.
- При большом числе товаров в заказе индексация в Meilisearch может стать тяжёлой — рассмотреть batch-реиндексацию (раз в 5 минут вместо мгновенной).

## Тесты

- Unit: `App\Support\Search\DocumentNumber` — нормализация номера.
- Feature: `tests/Feature/User/OrderSearchTest.php`:
  - поиск по части номера с дефисом и без
  - поиск по названию товара (LIKE и fuzzy)
  - поиск по бренду (fuzzy)
  - поиск по штрихкоду (точное)
  - поиск по `sku` (точное/префиксное)
  - поиск по контрагенту/ИНН с проверкой scope (чужие компании не находятся)
  - поиск по комментарию
  - дедупликация при множественных совпадениях
  - поиск возвращает `match_source` для каждой записи

## Зависимости

- Хелпер `App\Support\Search\DocumentNumber` нужен также для §2, §3, §4 — реализовать в общей задаче или сначала здесь.
- Fuzzy по товарам/брендам через Meilisearch — единый индекс может переиспользоваться возвратами и отгрузками.
