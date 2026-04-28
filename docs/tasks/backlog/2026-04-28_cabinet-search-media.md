# ЛК / Поиск: Медиатека

**Приоритет:** низкий
**Создано:** 2026-04-28
**Источник:** [docs/cabinet-search-scenarios.md §8](../../cabinet-search-scenarios.md) — сценарии C-8.1 … C-8.5

## Контекст

Медиатека `/cabinet/media` уже имеет мощный морфный поиск ([MediaController::api](../../../app/Http/Controllers/User/MediaController.php)) — работает по name товара, sku, code, barcode, brand.name, заголовкам статей/новостей/историй. Не хватает: фильтр по дате/размеру/разрешению, fuzzy для опечаток.

**Решение по owner-scope (2026-04-29):** медиатека остаётся глобальной — пользователи имеют общий доступ ко всем картинкам, своих загрузок в кабинете не делают. Owner-scope, миграция `uploaded_by_user_id` и чекбокс «Только мои» из плана исключены (C-8.4 снят).

## Текущая реализация

- Backend: [MediaController.php:55-115, 189-252](../../../app/Http/Controllers/User/MediaController.php) — `whereHasMorph` по 7 морфным целям, фильтры `type`/`collection`/`modelType`, сортировка 6 вариантов, пагинация 24.
- Frontend: [Cabinet/Media/Index.jsx](../../../resources/js/Pages/User/Cabinet/Media/Index.jsx) — debounce 300 мс (единственный в кабинете), чипы, batch-операции.

## План реализации

### Этап 1. Дата / размер / разрешение

**Backend ([MediaController::api](../../../app/Http/Controllers/User/MediaController.php)):**

1. **Фильтр по дате загрузки** (C-8.2):
   ```php
   if ($from = $request->input('date_from')) $query->whereDate('created_at', '>=', $from);
   if ($to = $request->input('date_to'))     $query->whereDate('created_at', '<=', $to);
   ```

2. **Фильтр по размеру** (C-8.3):
   ```php
   if ($sf = $request->input('size_from')) $query->where('size', '>=', $sf);
   if ($st = $request->input('size_to'))   $query->where('size', '<=', $st);
   ```
   На клиенте поля в МБ → конвертация в байты.

3. **Фильтр по разрешению (только изображения)** (C-8.3) — опционально, через `custom_properties->width`/`->height`. Spatie Media Library хранит размеры в `custom_properties`; добавить условие `whereJsonContains` или через колонку `manipulations`. Если не реализовано — отдельная задача на расширение модели.

### Этап 2. Fuzzy для названий товаров и брендов

7. В `MediaController::morphSearchMap()` ([MediaController.php:189-208](../../../app/Http/Controllers/User/MediaController.php)) для морфов `Product` и `Brand` заменить LIKE на маршрутизацию через Meilisearch:
   - Для морфа `Product` — если запрос текстовый, искать `Product::search($query)->keys()`, дальше `whereIn('model_id', $ids)`.
   - Для морфа `Brand` — аналогично, через индекс брендов или `whereHas('brand', ...)` с fuzzy на стороне Meilisearch.
   - Для статей/новостей/историй — оставить LIKE (опечатки в заголовках статей маловероятны).

8. Для exact-полей (sku/code/barcode/external_id) — без изменений.

### Этап 3. UI

**[Cabinet/Media/Index.jsx](../../../resources/js/Pages/User/Cabinet/Media/Index.jsx):**

9. Добавить в форму фильтров:
   - Поля «Загружено с / по» (date inputs).
   - Поля «Размер от / до МБ».
   - (Опционально) Поля разрешения «Ширина / высота».

10. Чипы для новых фильтров (уже есть инфраструктура чипов — расширить).

## Критерии готовности

- [ ] Фильтр по диапазону даты загрузки работает
- [ ] Фильтр по размеру (МБ) работает
- [ ] (Опционально) Фильтр по разрешению изображения
- [ ] Fuzzy для названий товаров и брендов через Meilisearch
- [ ] Exact-поиск по `sku`/`code`/`barcode`/`external_id` сохранён
- [ ] LIKE для статей/новостей/историй сохранён
- [ ] Чипы фильтров обновлены
- [ ] Покрыто feature-тестом (`tests/Feature/User/MediaSearchTest.php`)

## Технические заметки

- Spatie Media Library хранит размеры изображений в `custom_properties` или через manipulations. Точно проверить, доступны ли `width`/`height` без открытия файла.
- При больших объёмах медиатеки текущий `whereHasMorph` может быть тяжёлым. Если fuzzy через Meilisearch — индексировать пары `(media_id, product_id)` отдельно либо использовать существующий продукт-индекс с дополнительной операцией post-fetch.

## Тесты

- Feature: `tests/Feature/User/MediaSearchTest.php`:
  - фильтр по дате
  - фильтр по размеру
  - fuzzy по продукту
  - exact по штрихкоду
  - owner-scope (если внедрён)

## Зависимости

- Существующий Meilisearch-индекс `Product`.

## Открытые вопросы

- [ ] Доступны ли `width`/`height` в `media.custom_properties` без переоткрытия файла?
