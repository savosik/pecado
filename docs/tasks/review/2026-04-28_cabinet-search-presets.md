---
title: Cabinet Search — сохранённые поиски (PR 5.1)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 5, PR 5.1)
  - docs/cabinet-search-scenarios.md (A-2)
  - docs/tasks/backlog/2026-04-28_cabinet-search-cross-cutting.md (исходник плана)
---

# Cabinet Search — сохранённые поиски (PR 5.1)

## Цель

Дать пользователю возможность сохранять часто используемые комбинации
поиска/фильтров/сортировки в любом разделе кабинета и возвращаться к ним
одним кликом. PR — за флагом `CABINET_SEARCH_PRESETS` (default `false`),
поэтапная раскатка через ENV.

## Скоуп PR

**Backend:**
- Миграция `user_search_presets`: `id`, `user_id` (FK on cascade delete),
  `section` (string, индекс), `name` (string), `filters` (json), timestamps.
- Модель `App\Models\UserSearchPreset` с `BelongsTo $user`.
- Контроллер `App\Http\Controllers\User\SearchPresetController` —
  `index($section)`, `store(Request)`, `destroy(UserSearchPreset)`.
  Все методы — за флагом (если выключен → 404).
- Маршруты в `routes/user.php`:
  - `GET  /cabinet/search-presets/{section}` → index
  - `POST /cabinet/search-presets`           → store
  - `DELETE /cabinet/search-presets/{preset}` → destroy
- Конфиг `config/search-cabinet.php` → ключ `presets` + env
  `CABINET_SEARCH_PRESETS`. Default `false`.
- В `OrderController::index` и `HandleInertiaRequests` (или прямо в
  Inertia-prop) передавать `presetsEnabled` для UI.

**Frontend:**
- Компонент `resources/js/components/cabinet/SavedSearches.jsx`:
  - Dropdown «Мои поиски» в шапке таблицы рядом с кнопкой сохранения.
  - Кнопка «Сохранить» — открывает diaolog с input для имени.
  - Клик по пресету → `router.get(window.location.pathname, savedFilters)`.
  - Кнопка «×» рядом с пресетом — удаление (DELETE).
  - Появляется только когда `presetsEnabled === true`.
- Smoke-подключение только в `Pages/User/Cabinet/Orders/Index.jsx`.
  Returns/Shipments/Favorites — отдельным PR (или быстрым follow-up).

**Тесты:**
- `tests/Feature/User/SearchPresetTest.php`:
  - off: store/index/destroy → 404.
  - on: store создаёт запись с правильным user_id и filters.
  - on: index возвращает только пресеты текущего user в указанной секции.
  - on: destroy не удаляет чужой пресет (404).

## Вне скоупа

- Подключение `<SavedSearches />` в Returns/Shipments/Favorites/Media/
  Carts — следующий PR (или часть этого как «smoke-расширение», если
  быстро).
- Шаринг URL (A-3), экспорт (A-6), EmptyState (A-8) — отдельные PR
  Волны 5.

## DoD

- [x] `composer test` — 1148 passed (+9), 1 skipped (fuzzy fallback из PR 4.4).
- [x] `composer lint` (Pint) — clean.
- [x] `composer analyse` (PHPStan) — 1040, равно baseline.
- [x] `npm run build` — 18.76s, без ошибок.
- [x] Флаг `CABINET_SEARCH_PRESETS` в `config/search-cabinet.php` + `.env.example`, default `false`.
- [x] Все надписи UI на русском.
- [x] При выключенном флаге UI пресетов не виден (`presetsEnabled=false`), API возвращает 404.
- [x] Карточка в `review/`.
