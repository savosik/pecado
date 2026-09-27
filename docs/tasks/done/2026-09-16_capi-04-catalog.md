# capi-04: каталог — цены, остатки, поиск, товар, штрихкод

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01, capi-02

## Описание

Секция `catalog` реестра и два новых сервиса, на которые переводятся legacy и кабинет.

| Операция | Параметры | Сервис |
|---|---|---|
| `catalog.prices` GET `catalog/prices` | `identifiers[]` ≤ 500 или `cursor`, `per_page` ≤ 500, `currency` | `PriceService::getPriceMapForProducts` (пакетно, не поштучно как в legacy), `CurrencyService::convertFromBase`, `UserCurrencyResolver` |
| `catalog.stocks` GET `catalog/stocks` | те же | `StockService::getStockMapsByIds` |
| `catalog.search` GET `catalog/search` | `q`, `page`, `per_page` ≤ 100 (offset — релевантность Meilisearch с курсором несовместима) | **новый** `App\Services\Search\ProductSearchQuery` — вынос из `SearchApiController::searchQuery/applyRelevanceOrder`; кабинетный контроллер переводится на него |
| `catalog.product` GET `catalog/products/{identifier}` | uuid / code / sku / barcode / id | **новый** `App\Services\Catalog\ProductIdentifierResolver` — объединяет legacy `ClientApiController::resolveProduct`, `OrderImportService::buildLookup`, `ExactProductMatcher`; в ответе цена, остаток, признак предзаказа и срок из `config/preorder.php` |
| `catalog.barcode` GET `catalog/barcode/{barcode}` | штрихкод (сценарий сканера) | тот же резолвер |

Legacy `prices()`/`stocks()` остаются с прежними ответами, но `resolveProduct` заменяется вызовом
резолвера. Ответы v1 — в конверте, наружу не утекают `cost_price` и внутренние склады.

## Критерии готовности

- [ ] `ClientApiCatalogTest`: цены/остатки по списку идентификаторов и постранично; потолок `per_page`; валюта по коду и по умолчанию клиента; товар по каждому виду идентификатора; неизвестный → 404.
- [ ] Паритет-тест: на одном фикстурном наборе цены и остатки из v1 совпадают с legacy `/prices` и `/stocks`.
- [ ] Legacy-тесты `tests/Feature/Api/ClientApi*Test.php` зелёные без правок.
- [ ] Кабинетный поиск (`SearchApiController`) работает через `ProductSearchQuery`; его тесты зелёные.
- [ ] `make lint` зелёный.
