# ЛК / Поиск: Товары для добавления в корзину

**Приоритет:** высокий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §6](../../cabinet-search-scenarios.md) — сценарии C-6.1 … C-6.6

## Контекст

Когда клиент добавляет товары в корзину, он использует автокомплит `GET /cabinet/carts/search-products` ([CabinetCartController::searchProducts](../../../app/Http/Controllers/User/CabinetCartController.php)). Сейчас ищет по `name`, `sku`, `code`, `barcodes.barcode`. Не хватает: бренд, fuzzy для опечаток, подсказка «вы уже покупали», специальная обработка штрихкода.

## Текущая реализация

- Backend: [CabinetCartController::searchProducts](../../../app/Http/Controllers/User/CabinetCartController.php) — LIKE по `name`/`sku`/`code` + `whereHas('barcodes')`. Лимит 15. Загружает `brand`, `media`.
- Frontend: компонент-автокомплит в редакторе корзины (debounce ~250 мс).

## План реализации

### Этап 1. Расширение поиска и эвристики

**Backend ([CabinetCartController::searchProducts](../../../app/Http/Controllers/User/CabinetCartController.php)):**

1. **Поиск по бренду** (C-6.2):
   ```php
   ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
   ```

2. **Эвристика штрихкода** (C-6.5) — если запрос — строка ровно из 8/12/13/14 цифр, ставить в начало выдачи **точное** совпадение по `barcodes.barcode = $query`. Если найдено — возвращать первым.

3. **Эвристика артикула** — если запрос содержит дефис и/или верхний регистр (`AM90-001`, `ART-12345`), приоритет точного и префиксного совпадения по `sku`/`code`.

4. **Сортировка**: точные совпадения по `sku`/`barcode`/`code` выше всех. Затем по популярности/`is_bestseller`.

### Этап 2. Подсказки в выдаче

5. **`purchased_count`** (C-6.4) — для каждого товара посчитать, сколько раз пользователь его покупал (через `OrderItem` со статусом `confirmed`+):
   ```php
   ->withCount(['orderItems as purchased_count' => function ($q) use ($userId) {
       $q->whereHas('order', fn ($o) => $o->where('user_id', $userId)
                                          ->whereIn('status', ['confirmed', 'completed']));
   }])
   ```
   Может быть тяжело — кешировать на пользователя на 5 минут (Redis tag).

6. **`in_favorites: bool`** — `Favorite::where('user_id', $userId)->whereIn('product_id', $ids)->pluck('product_id')`.

7. **В JSON-ответе** добавить `purchased_count`, `in_favorites`, и при найденном штрихкоде — `match_source = 'barcode_exact'`.

### Этап 3. Frontend

8. В строке автокомплита показывать бейджи:
   - `✓ покупали 3×` (если `purchased_count > 0`)
   - `★ в избранном` (если `in_favorites`)
9. При вводе ровно 13 цифр — если есть точный матч по barcode, **не показывать выпадайку**, сразу подставлять товар (опция «авто-вставка по штрихкоду» включена по умолчанию).

### Этап 4. Fuzzy для названий товаров и брендов

10. Подключить Meilisearch-индекс `products` (он уже есть — `Product` использует Scout). Использовать `Product::search($query)->take(15)` для текстовых запросов.

11. Маршрутизация:
    - Запрос — цифры/UUID/sku → текущая LIKE-логика.
    - Запрос — текст (русский/латиница) → `Product::search($query)` с fuzzy.
    - Объединение и дедупликация по `id`.

12. Опечатки в бренде — Meilisearch автоматом обрабатывает Levenshtein ≤ 2.

### Этап 5. Аналог по EAN (опционально, отдельный задел)

13. C-6.6 «Найти аналог» — если штрихкод не найден, показывать топ-5 товаров той же категории. Реализуется через стороннее API EAN-баз — выходит за рамки этой карточки, упомянуто как идея.

## Критерии готовности

### Этап 1 (PR 3.1) — закрыто

- [x] Поиск находит товар по бренду
- [x] Точный матч по 13-значному штрихкоду — товар в выдаче первым
- [x] При ровно 8/12/13/14 цифрах + точном штрихкоде — авто-вставка без выпадайки
- [x] Поиск по `sku` с дефисом — точное и префиксное (точные ранжируются выше)
- [x] В строке автокомплита показывается бейдж «✓ покупали N×»
- [x] Бейдж «★ в избранном» работает
- [x] Точные совпадения по идентификаторам (sku/code/barcode) выше LIKE-выдачи
- [x] Лимит 15 сохранён
- [x] Покрыто feature-тестом (`tests/Feature/User/CabinetCartProductSearchTest.php`, 15 кейсов)

### Этап 4 (PR 4.x, Meilisearch fuzzy за флагом) — отдельный PR

- [ ] Fuzzy для русских опечаток в названии (`кросовки` → находит «Кроссовки»)
- [ ] Fuzzy для опечаток в бренде (`addidas` → «Adidas»)

## Технические заметки

- `Product` уже имеет `Searchable` trait через Scout — можно использовать сразу.
- `purchased_count` для каждого товара — потенциально N+1 при больших списках; альтернатива — материализованная статистика в `user_product_purchase_stats` (отдельная таблица), обновляется по событию `OrderConfirmed`.
- Кеш `purchased_count` — `cache()->tags(['user-purchases', "user-{$userId}"])->remember(...)`. Инвалидация при создании заказа.

## Тесты

- Feature: `tests/Feature/User/CabinetCartProductSearchTest.php`:
  - поиск по `name`/`sku`/`code`/`barcode` (как сейчас)
  - поиск по бренду
  - точный матч штрихкода → первый в выдаче
  - `purchased_count` корректен для текущего пользователя
  - `in_favorites` корректен
  - fuzzy для названия товара
  - fuzzy для бренда
  - при ровно 13 цифрах без матча — falls back к LIKE по barcode (несколько результатов)

## Зависимости

- Существующий Meilisearch-индекс по `Product` (`docs/MEILISEARCH.md`).
- Возможна миграция `user_product_purchase_stats` (если кеш недостаточен).
