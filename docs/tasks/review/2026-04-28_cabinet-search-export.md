---
title: Cabinet Search — экспорт списков CSV/XLSX (PR 5.2)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 5, PR 5.2)
  - docs/cabinet-search-scenarios.md (A-6)
  - docs/tasks/backlog/2026-04-28_cabinet-search-cross-cutting.md
---

# Cabinet Search — экспорт списков CSV/XLSX (PR 5.2)

## Цель

Дать возможность скачать текущий результат поиска/фильтрации в Orders /
Returns / Shipments как CSV или XLSX (тот же query, что и `index`, без
пагинации). За флагом `CABINET_SEARCH_EXPORT` (default `false`).

## Скоуп PR

**Backend:**
- `App\Services\SimpleCsvExporter` — стрим CSV (UTF-8 + BOM для Excel).
- В каждом контроллере (Order/Return/Shipment):
  - Извлечь `applyListFilters(Builder, Request, User): void` из `index`
    (поиск + все фильтры + сортировка). `index` после рефакторинга
    использует тот же метод. Транзакционно идентичное поведение.
  - Новый метод `export(Request, $format)` за флагом — `cursor()` без
    пагинации, маппинг строк → headers, стрим через нужный экспортёр.
- Routes:
  - `GET /cabinet/orders/export?format=csv|xlsx`
  - `GET /cabinet/returns/export?format=csv|xlsx`
  - `GET /cabinet/shipments/export?format=csv|xlsx`
- Конфиг `config/search-cabinet.php` → ключ `export` + env
  `CABINET_SEARCH_EXPORT`.

**Frontend:**
- Компонент `resources/js/components/cabinet/ExportMenu.jsx` — dropdown
  «Экспорт» с пунктами «CSV» / «XLSX». Передаёт текущие фильтры в
  `window.location` через query string.
- Подключение в Orders/Returns/Shipments Index — только при
  `exportEnabled === true`.

**Тесты:**
- `tests/Feature/Search/SearchExportTest.php`:
  - off: `?format=csv` и `?format=xlsx` → 404 для каждого раздела.
  - on csv: содержит шапку с известными заголовками и строки текущего пользователя.
  - on xlsx: правильный `Content-Type` и атачмент.
  - on: запрос без `format` → 422 (валидация).
  - on: фильтр `status` сужает выгрузку.
  - on: чужие документы в выгрузке отсутствуют.

## Вне скоупа

- Carts / Favorites / Media / ProductExports — у некоторых уже есть
  собственные выгрузки (`exportItems` для конкретного документа); общий
  «экспорт списка» можно добавить отдельно.
- Streaming больших объёмов через Spout — пока используем PhpSpreadsheet
  (тот же пакет, что в `SimpleXlsxExporter`).

## DoD

- [x] `composer test` — 1158 passed (+10 для SearchExportTest), 1 skipped.
- [x] `composer lint` (Pint) — clean.
- [x] `composer analyse` (PHPStan) — 1027, на 13 меньше baseline (refactor `buildIndexQuery` снял часть прежних property.notFound).
- [x] `npm run build` — 18.77s, без ошибок.
- [x] Флаг `CABINET_SEARCH_EXPORT` в `config/search-cabinet.php` + `.env.example`.
- [x] При выключенном флаге UI кнопки экспорта не виден (`exportEnabled=false`), API возвращает 404.
- [x] Все надписи на русском («Экспорт», «CSV», «Excel (XLSX)»).
- [x] Существующие `OrderSearchTest` / `ReturnSearchTest` / `ShipmentSearchTest` зелёные после refactor.
- [x] Карточка в `review/`.
