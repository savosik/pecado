---
title: PR 4.2 — Подключение fuzzy в контроллерах документов кабинета
date: 2026-04-28
wave: 4
flag: CABINET_SEARCH_FUZZY_DOCUMENTS
depends_on:
  - PR 4.1 (cabinet-search-meilisearch-indexing) — индекс Searchable на Item-моделях
---

# PR 4.2 — Fuzzy в OrderController / ReturnController / ShipmentController

## Цель

Подключить fuzzy-поиск по составу документов через Scout-индексы из PR 4.1. Поведение по умолчанию не меняется: только при включённом флаге `CABINET_SEARCH_FUZZY_DOCUMENTS=true` и текстовом запросе (без цифр/UUID/штрихкода) добавляется дополнительный OR-источник через Meilisearch по `product_name_snapshot`/`brand_name_snapshot`.

## Скоуп (по [roadmap](../../cabinet-search-roadmap.md#волна-4--meilisearch-fuzzy-за-флагами))

- ✅ Конфиг `config/search-cabinet.php`, ключ `fuzzy_documents` из `env('CABINET_SEARCH_FUZZY_DOCUMENTS', false)`.
- ✅ `.env.example` — `CABINET_SEARCH_FUZZY_DOCUMENTS=false`.
- ✅ В `OrderController::index`, `ReturnController::index`, `ShipmentController::index`:
  - При выключенном флаге — поведение не меняется.
  - При включённом флаге и `QueryRouter::TYPE_TEXT` — дополнительный `orWhereIn('id', $fuzzyDocumentIds)`.
  - Жёсткий scope `user_id` через DB-фильтр (поверх Scout-результата).
- ✅ Дедупликация автоматическая (один документ выводится один раз через `OR`).
- ❌ Не меняем фронт (это PR 4.4).
- ❌ Не подключаем fuzzy к товарным разделам (PR 4.3).

## Критерии готовности

- [ ] Флаг существует и default=false.
- [ ] Фича-тесты с двумя состояниями флага (off/on) для каждого из трёх контроллеров.
- [ ] При выключенном флаге — старые тесты Orders/Returns/Shipments проходят без изменений.
- [ ] При включённом флаге и `scout.driver=database` — fuzzy находит заказ по snapshot-полю товара, который не виден через текущий LIKE-сценарий (товар удалён → имя осталось только в snapshot).
- [ ] Scope `user_id` работает: чужие заказы не находятся даже при совпадении по составу.
- [ ] `composer test` зелёный (1104+ новые).
- [ ] `composer lint`, `composer analyse` без новых ошибок.

## Деплой

1. Раскатить миграцию и backfill из PR 4.1, `scout:import` для Item-моделей.
2. Раскатить этот PR с флагом `CABINET_SEARCH_FUZZY_DOCUMENTS=false`.
3. На dev переключить флаг в `true`, проверить выдачу.
4. На prod включать поэтапно (Orders → неделя → Returns → неделя → Shipments).

## Откат

- Выключить флаг (мгновенно).

## Ссылки

- [docs/cabinet-search-roadmap.md → Волна 4](../../cabinet-search-roadmap.md)
- [docs/cabinet-search-scenarios.md C-1.5/C-2.3/C-4.4](../../cabinet-search-scenarios.md)
