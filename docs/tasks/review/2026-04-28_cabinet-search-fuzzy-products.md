---
title: Cabinet Search — fuzzy в товарных разделах за флагом (PR 4.3)
created: 2026-04-28
status: in-progress
related:
  - docs/cabinet-search-roadmap.md (Волна 4, PR 4.3)
  - docs/cabinet-search-scenarios.md (C-3 cart-products, C-4 favorites)
  - docs/tasks/review/2026-04-28_cabinet-search-cart-products.md (Этап 1)
  - docs/tasks/review/2026-04-28_cabinet-search-favorites.md (Этап 1)
---

# Cabinet Search — fuzzy в товарных разделах за флагом (PR 4.3)

## Цель

Подключить fuzzy-поиск Meilisearch (через Scout) в товарные эндпойнты кабинета:

1. `GET /cabinet/carts/search-products` ([CabinetCartController::searchProducts](../../../app/Http/Controllers/User/CabinetCartController.php))
2. Список «Избранное» — `GET /favorites` ([FavoriteController::index](../../../app/Http/Controllers/User/FavoriteController.php))

Источник fuzzy-выдачи — `Product::search($query)` (модель уже Searchable). Поведение
скрыто за `CABINET_SEARCH_FUZZY_PRODUCTS` (default `false`). При выключенном флаге
поведение — только LIKE из Этапа 1.

## Скоуп PR

**В скоупе:**

- Хелпер `App\Support\Search\FuzzyProductMatcher` (по аналогии с `FuzzyDocumentMatcher`):
  `isApplicable($search, $queryType)` + `findProductIds($search): array<int>`.
- Подключение в `CabinetCartController::searchProducts` — `orWhereIn('id', $fuzzyIds)` к существующему `where(...)` LIKE-блока.
- Подключение в `FavoriteController::applySearch` — `orWhereIn('products.id', $fuzzyIds)` к существующему `where(...)` LIKE-блока. **Скоуп пользователя сохраняется**: уже есть `join('favorites')->where('favorites.user_id', $user->id)` в основном запросе.
- Лимит fuzzy-выдачи `fuzzy_products_limit` в `config/search-cabinet.php`.
- Feature-тесты на оба контроллера в двух состояниях флага (off / on), плюс кейс `TYPE_BARCODE` (fuzzy не должен срабатывать), плюс scope-тест для favorites.

**Вне скоупа:**

- `match_source`/`match_snippet` — это PR 4.4.
- Изменения в карточках Этапа 1 (`cart-products`, `favorites`).
- Любая работа с UI.

## DoD

- [ ] `docker exec pecado-app composer test` — без падений.
- [ ] `docker exec pecado-app composer lint` — без правок.
- [ ] `docker exec pecado-app composer analyse` — без новых ошибок.
- [ ] Флаг `CABINET_SEARCH_FUZZY_PRODUCTS` в `.env.example` (уже есть после PR 4.2) — default `false`.
- [ ] Никаких изменений за пределами скоупа.
- [ ] Карточка в `review/`.
