# capi-09: возвраты через API

**Приоритет:** средний
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-03, capi-05

## Описание

`ReturnService::createForUser` уже транспортно-независим; выносить нужно чтение и поиск оснований из
`User\ReturnController` (`buildIndexQuery` ~137 строк, `searchShipments` ~100, `getShipmentItems` ~45).

| Операция | Параметры | Сервис |
|---|---|---|
| `returns.list` GET `returns` | `status[]`, `date_from/to`, `search`, `cursor` | **новый** `App\Services\Returns\ClientReturnQuery`; `ReturnController::index/export` на него |
| `returns.get` GET `returns/{return}` | — | presenter из `ReturnController::show` |
| `returns.shipment-items` GET `returns/shipment-items?shipment=…` | реализация по id/uuid/номеру; ответ — строки с остатком к возврату | **новый** `App\Services\Returns\ReturnableShipmentItems`; `ReturnController::getShipmentItems/searchShipments` на него |
| `returns.create` POST `returns` (M, I) | `comment?`, `items[{shipment_item_id, quantity, reason, reason_comment?}]`; `reason` из `App\Enums\ReturnReason` | `ReturnService::createForUser` |

Согласование возврата остаётся в 1С (`return.updated`), API только создаёт и читает. Уведомления
`system.return_*` подчиняются матрице клиента (по умолчанию выключены).

## Критерии готовности

- [ ] Строка чужой реализации → 404/422 с тем же текстом, что в кабинете («Доступно к возврату: N»).
- [ ] Повтор `returns.create` с тем же ключом не создаёт второй возврат; событие `ReturnCreated` один раз.
- [ ] Список/карточка/строки к возврату совпадают с кабинетом на общем фикстурном наборе.
- [ ] Кабинетные тесты возвратов зелёные; `ReturnController` без запросов к БД в теле методов.
- [ ] `make lint` зелёный.
