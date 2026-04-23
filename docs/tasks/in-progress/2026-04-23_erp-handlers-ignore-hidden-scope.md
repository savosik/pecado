# ERP-handlers: обходить HiddenScope при поиске товара

## Контекст

На dev обнаружен баг: после того как 1С скрывает товар сообщением `product.updated` с `hidden: true`, любые последующие сообщения по этому товару (включение обратно, обновление атрибутов, цен, остатков, отгрузок, заказов) **не применяются**, но в шине выглядят как `success`.

Воспроизведение — товар `c5782211-5d68-11e6-80c3-00155d00e605`:

| ErpBusMessage | event | payload | результат |
|---|---|---|---|
| 1256 | product.created | hidden:false + 18 атрибутов | OK, товар создан |
| 9580 | product.updated | `{hidden: true}` | OK, hidden=true |
| 9581 | product.updated | `{hidden: false, attributes:[Высота=15]}` | **не применилось** |

В laravel.log в момент обработки 9581:
```
[2026-04-23 10:56:12] dev.WARNING: product.updated: товар не найден
  {"uuid":"c5782211-5d68-11e6-80c3-00155d00e605"}
```

## Причина

`app/Models/Product.php:31` регистрирует глобальный `HiddenScope`, который добавляет `where hidden = false` во все запросы `Product::query()`. В `app/Services/Erp/Handlers/HandleProductUpdated.php:68` товар ищется без `withoutGlobalScope` — поэтому после `hidden=true` handler делает `$product === null` → early-return с warning. Exception не выбрасывается, поэтому:

- `ErpProcessedMessage` пишется (идемпотентность «съедает» повтор),
- `ErpBusMessage` получает `status=success`,
- в БД никаких изменений.

Тот же баг во всех ERP-handler'ах, читающих `Product` по external_id:
- `HandleProductUpdated:68`
- `HandlePriceUpdated:27`
- `HandleStockUpdated:30`
- `HandleOrderCreated:149`
- `HandleOrderUpdated:148`
- `HandleShipmentCreated:87`
- `HandleShipmentUpdated:79`

## Что делаем

1. Во всех ERP-handler'ах (1С → Сайт) заменить `Product::where(...)` → `Product::withoutGlobalScope(HiddenScope::class)->where(...)`.
   1С — мастер-каталог, бэкофис должен «видеть» скрытые товары.
2. Интеграционный тест: `HandleProductUpdatedTest::скрытый_товар_можно_снова_включить` — скрываем, затем шлём update с `hidden:false` и одним атрибутом, проверяем, что hidden=false и остался ровно один атрибут.
3. `docs-erp/content/changelog.md` — запись о фиксе.
4. После мержа в dev — автодеплой.

## Критерии готовности

- [x] Все ERP-handler'ы используют `withoutGlobalScope(HiddenScope::class)` при поиске товара.
- [x] Интеграционный тест зелёный.
- [x] На dev сообщение с `hidden: false` возвращает скрытый товар в каталог.
