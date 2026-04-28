---
title: Cabinet Search — аудит шаринга URL (PR 5.4)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 5, PR 5.4)
  - docs/cabinet-search-scenarios.md (A-3)
---

# Cabinet Search — аудит URL-шаринга (PR 5.4)

## Цель

Убедиться, что во всех листингах кабинета фильтры/поиск/сортировка/
пагинация сохраняются в query string, страница восстанавливается из URL
и ссылку можно скинуть коллеге.

## Найденные пробелы

- [Pages/User/Cabinet/Companies](../../../app/Http/Controllers/User/CompanyController.php) —
  `paginate(15)` без `withQueryString()`. Если в будущем у компаний появятся
  фильтры, ссылки пагинатора не сохранят их. Фиксим сейчас.

## Что уже работает

- **Orders / Returns / Shipments** — `paginate->withQueryString()` + Inertia
  фильтры (PR 2.1/2.2/2.3). URL-restore покрыт существующими feature-тестами.
- **Carts / ProductExports** — paginate с `withQueryString()` (PR 2.4 / 5bdaff3).
- **Favorites** — paginate с `withQueryString()` (PR 3.2).
- **Media** — JSON-API + ручной `history.replaceState` для URL-синхронизации
  (Cabinet/Media/Index.jsx). Все фильтры readable из URL через `readInitialFilters`.
- **DeliveryAddresses / ExportPresets / ApiTokens** — без пагинации/фильтров,
  URL-шаринг неактуален.

## Скоуп PR

- Добавить `withQueryString()` в `CompanyController::index`.
- Написать smoke-тест URL-restore для Orders: запрос вида
  `/cabinet/orders?search=X&status[]=Y&sort_by=total_amount` восстанавливает
  состояние через `filters` props.

## DoD

- [x] `composer test` — 1165 passed (+1), 1 skipped.
- [x] `composer lint` (Pint) — clean.
- [x] `composer analyse` (PHPStan) — 1027, без новых ошибок.
- [x] Карточка в `review/`.
