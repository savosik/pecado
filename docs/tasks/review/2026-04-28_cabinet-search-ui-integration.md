# ЛК / Поиск: подключение SelectedFilters и useSearchHistory к страницам документов

**Приоритет:** средний
**Создано:** 2026-04-28
**PR:** 2.5 (последний в волне 2)
**Источник:** [docs/cabinet-search-roadmap.md](../../cabinet-search-roadmap.md) — раздел «Волна 2 / PR-план», и [docs/cabinet-search-scenarios.md](../../cabinet-search-scenarios.md) — A-7 (унификация debounce 400 мс).

## Контекст

Хелпер-компонент [SelectedFilters](../../../resources/js/components/cabinet/SelectedFilters.jsx) и хук [useSearchHistory](../../../resources/js/hooks/useSearchHistory.js) уже реализованы в PR 1.2 (cabinet-search-foundation), но к страницам документов кабинета (Orders/Returns/Shipments) пока не подключены. Этот PR закрывает гэп — даёт пользователю единый UX выбранных фильтров и истории поисковых запросов в трёх ключевых разделах.

## Скоуп

### Backend

Никаких изменений. Существующие endpoints уже отдают всё необходимое.

### Frontend

1. **Orders/Index.jsx**, **Returns/Index.jsx**, **Shipments/Index.jsx**:
   - Подключить `<SelectedFilters />` под формой фильтров — пользователь видит активные фильтры чипами, может убрать любой одним кликом.
   - Подключить `useSearchHistory(section)` к полю поиска — последние ≥2-символьные запросы предлагаются как datalist/Autocomplete.
   - При сабмите/применении фильтров вызвать `history.push(search)`.

2. **Унификация debounce 400 мс (A-7)**:
   - Все формы фильтров — поиск с тем же таймингом, что я уже применил при PR 2.1/2.2/2.3 (через `useEffect` + `setTimeout` 400 мс). Подтвердить, что значение одинаковое во всех трёх страницах.

### Тесты

Не требуются: подключение готовых компонентов без изменения серверного контракта. Существующие feature-тесты Orders/Returns/Shipments-Search должны продолжать проходить — проверить, что я не сломал передачу query-параметров.

## Критерии готовности

- [x] `<SelectedFilters />` подключён в Orders/Returns/Shipments Index.
- [x] `useSearchHistory` подключён в Orders/Returns/Shipments Index (datalist + push при сабмите/debounced search).
- [x] Debounce 400 мс одинаков в трёх страницах (был уже 400 мс в PR 2.1/2.2/2.3 — подтверждено).
- [x] Все надписи на русском.
- [x] Чипы фильтров корректно убирают параметр (multi-select убирает по одному значению, скаляр — целиком; `localFilters` синхронизируется).
- [x] История ≥2 символов сохраняется/предлагается между перезагрузками (через `useSearchHistory` → `localStorage`).
- [x] Существующие feature-тесты OrderSearch/ReturnSearch/ShipmentSearch — зелёные (47 проверок).
- [x] `npm run build` зелёный.

## Зависимости

- PR 1.2 (`<SelectedFilters />`, `useSearchHistory`) — готово, в `review/`.
- PR 2.1, 2.2, 2.3 — готово, в `review/`.
