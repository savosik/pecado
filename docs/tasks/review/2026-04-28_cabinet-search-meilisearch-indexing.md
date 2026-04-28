---
title: PR 4.1 — Индексация документов кабинета в Meilisearch
date: 2026-04-28
wave: 4
depends_on:
  - cabinet-search-orders (review)
  - cabinet-search-returns (review)
  - cabinet-search-shipments (review)
---

# PR 4.1 — Индексация OrderItem / ReturnItem / ShipmentItem в Meilisearch

## Цель

Подготовить инфраструктуру для fuzzy-поиска по составу документов кабинета: добавить snapshot-поля имени товара и бренда на `OrderItem` / `ReturnItem` / `ShipmentItem`, подключить Scout `Searchable` trait, наполнить snapshot-поля для существующих записей, обеспечить наполнение Meilisearch без подключения к контроллерам.

## Скоуп (по [roadmap](../../cabinet-search-roadmap.md#волна-4--meilisearch-fuzzy-за-флагами))

- ✅ Миграция `product_name_snapshot`/`brand_name_snapshot` на `return_items`, `shipment_items` (у `order_items` уже есть `name`, добавляется только `brand_name_snapshot`).
- ✅ Авто-заполнение snapshot-полей при создании записи через `boot()`.
- ✅ `Laravel\Scout\Searchable` в трёх моделях с `toSearchableArray()` и scope-полями (`user_id`).
- ✅ Backfill-команда `cabinet-search:backfill-item-snapshots` для существующих записей.
- ✅ Изоляция тестов от Meilisearch — `SCOUT_DRIVER=null` в `phpunit.xml`.
- ❌ Не подключаем fuzzy в контроллеры (это PR 4.2 за флагом `CABINET_SEARCH_FUZZY_DOCUMENTS`).
- ❌ Не трогаем фронт (это PR 4.4).

## Критерии готовности

- [ ] Миграция применяется, поля добавлены, существующие миграции не тронуты.
- [ ] При создании `OrderItem`/`ReturnItem`/`ShipmentItem` с указанным `product_id` snapshot-поля автоматически заполняются.
- [ ] `toSearchableArray()` возвращает `id`, `user_id` (scope), `product_id`, `product_name_snapshot`, `brand_name_snapshot`, ключевые ссылочные ID (для Order — `order_id`, `number`; для Return — `return_id`, `number`; для Shipment — `shipment_id`, `number`).
- [ ] Backfill-команда заполняет пустые snapshot-поля без падения на удалённых товарах.
- [ ] Тесты:
  - `tests/Feature/Search/OrderItemSnapshotTest.php` — авто-заполнение и `toSearchableArray()`
  - `tests/Feature/Search/ReturnItemSnapshotTest.php`
  - `tests/Feature/Search/ShipmentItemSnapshotTest.php`
  - `tests/Feature/Console/BackfillItemSnapshotsCommandTest.php`
- [ ] Существующие тесты зелёные (1089+).
- [ ] `composer lint`, `composer analyse` без новых ошибок.

## Деплой

1. Раскатить миграцию (быстрая — три ALTER без переноса данных).
2. Запустить `php artisan cabinet-search:backfill-item-snapshots` для заполнения snapshot-полей.
3. Запустить `php artisan scout:import "App\\Models\\OrderItem"` (и для двух других моделей) для заливки в Meilisearch.
4. PR 4.2 уже сможет использовать fuzzy за флагом.

## Ссылки

- [docs/cabinet-search-roadmap.md → Волна 4](../../cabinet-search-roadmap.md)
- [docs/cabinet-search-scenarios.md C-1.5, C-1.6, C-1.7](../../cabinet-search-scenarios.md)
