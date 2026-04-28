# ЛК / Поиск: Список корзин

**Приоритет:** низкий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §5](../../cabinet-search-scenarios.md) — сценарии C-5.1 … C-5.5

## Контекст

В `/cabinet/carts` сейчас просто список корзин пользователя без поиска и фильтров ([CabinetCartController.php:23-54](../../../app/Http/Controllers/User/CabinetCartController.php)). Капризный клиент ведёт 30+ корзин и хочет искать их по имени или по содержимому, фильтровать по сумме и количеству позиций.

## Текущая реализация

- Backend: [CabinetCartController::index](../../../app/Http/Controllers/User/CabinetCartController.php) — `$user->carts()` без фильтрации.
- Frontend: [Carts/Index.jsx](../../../resources/js/Pages/User/Cabinet/Carts/Index.jsx) — таблица + мобильные карточки. Нет поля поиска.

## План реализации

### Этап 1. Минимальный поиск + сортировка

**Backend ([CabinetCartController::index](../../../app/Http/Controllers/User/CabinetCartController.php)):**

1. **Поиск по имени корзины** (C-5.1):
   ```php
   if ($search = $request->input('search')) {
       $query->where('name', 'like', "%{$search}%");
   }
   ```

2. **Поиск по составу** (C-5.2) — расширение того же поля:
   ```php
   $query->where(function ($q) use ($search) {
       $q->where('name', 'like', "%{$search}%")
         ->orWhereHas('items.product', function ($p) use ($search) {
             $p->where('name', 'like', "%{$search}%")
               ->orWhere('sku', 'like', "%{$search}%")
               ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search))
               ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));
         });
   });
   ```
   Эвристика для штрихкода: 8/12/13/14 цифр.

3. **Сортировка** (C-5.4) — параметр `sort_by` (`updated_at`/`created_at`/`name`/`total_amount`/`items_count`) + `sort_order`.

4. **`match_source`** — `name`/`composition` для UI-бейджа.

5. Пагинация: добавить, если её нет (по 20 на страницу).

### Этап 2. Фильтры

6. **Сумма от/до** (C-5.3) — поля `amount_from`, `amount_to`. Корзина не имеет персистентного `total_amount`? — пересчитать через `withSum`/`withCount` (см. как в `OrderController::index`):
   ```php
   ->withSum(['items as total_amount' => fn ($q) => $q->select(DB::raw('SUM(price * quantity))'))], '...')
   ```
   или хранить в `Cart.total_amount` (актуализировать при `addProduct/removeProduct`).

7. **Позиций от/до** (C-5.3) — `items_count_from`, `items_count_to`; `withCount('items')`.

8. **«Только пустые»** / **«Только активная»** (C-5.5) — `?only=empty|active`.

### Этап 3. Frontend

**[Carts/Index.jsx](../../../resources/js/Pages/User/Cabinet/Carts/Index.jsx):**

9. Поле поиска в шапке: «Поиск по имени корзины или товару…» с debounce 400 мс.

10. Кнопка «Фильтры» с popover (как в Orders/Returns/Shipments):
    - Сумма от/до
    - Позиций от/до
    - Чекбокс «Только пустые»
    - Чекбокс «Только активная»

11. Сортировка через menu-кнопку (как в Orders) с опциями: Обновлено ↓/↑, Создано ↓/↑, Имя А-Я, Сумма ↓/↑, Позиций ↓/↑.

12. Бейдж активных фильтров. Кнопка «Сбросить».

### Этап 4. Fuzzy (опционально)

13. Если объёмы корзин велики — добавить fuzzy через Meilisearch на `Cart` с агрегатом `items_text` (как в заказах). Иначе ограничиться LIKE.

## Критерии готовности

- [ ] Поиск находит корзину по имени
- [ ] Поиск находит корзину по товару в составе (включая бренд и штрихкод)
- [ ] Сортировка работает по 5 полям (обновление, создание, имя, сумма, позиции)
- [ ] Фильтр по сумме от/до
- [ ] Фильтр по количеству позиций от/до
- [ ] Чекбокс «Только пустые» (`items_count = 0`)
- [ ] Чекбокс «Только активная» (текущая активная корзина пользователя)
- [ ] Debounce 400 мс
- [ ] Бейдж активных фильтров
- [ ] Дедупликация
- [ ] Scope: только свои корзины (через `$user->carts()`)
- [ ] Покрыто feature-тестами (`tests/Feature/User/CabinetCartListSearchTest.php`)

## Технические заметки

- В модели `Cart` стоит проверить наличие поля `total_amount` — если его нет, либо добавить и обновлять при изменениях, либо считать через `withSum` (медленнее, но проще).
- Связь `Cart → CartItem → Product → Brand/Barcode` — глубина 3, может потребовать индексов на `cart_items.cart_id`, `cart_items.product_id`.
- Для «активной» корзины: проверить, как помечается активность (поле `is_active` в `Cart`? или через `User.active_cart_id`?).

## Тесты

- Feature: `tests/Feature/User/CabinetCartListSearchTest.php`:
  - поиск по имени корзины
  - поиск по товару в составе
  - поиск по бренду в составе
  - поиск по штрихкоду
  - сортировка по сумме / по обновлению
  - фильтр по сумме (от/до)
  - фильтр «только пустые»
  - фильтр «только активная»
  - дедупликация
  - scope: чужие корзины не находятся

## Зависимости

- Поле `Cart.total_amount` или агрегат — обсудить с владельцем продукта.
- Может потребовать миграцию (если поля для сортировки/счётчиков отсутствуют).
