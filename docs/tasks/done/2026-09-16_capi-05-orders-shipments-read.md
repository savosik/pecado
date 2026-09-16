# capi-05: чтение заказов, реализаций, резервов и журнала изменений

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01

## Описание

Крупнейший вынос логики чтения из контроллеров: три места сегодня строят списки заказов и реализаций
независимо (`User\OrderController::buildIndexQuery` ~200 строк, `User\ShipmentController::buildIndexQuery`
~150, legacy `ClientApiController::shipments`). После карточки — по одному сервису на сущность, кабинет
и legacy на них.

| Операция | Параметры | Сервис |
|---|---|---|
| `orders.list` GET `orders` | `status[]`, `type`, `company_id`, `date_from/to`, `search`, `updated_since`, `cursor` | **новый** `App\Services\Order\ClientOrderQuery` (+ `statusCounts`); `OrderController::index/export/statusCounts` на него |
| `orders.get` GET `orders/{order}` | id / номер / uuid | **новый** `App\Services\Order\ClientOrderPresenter` (items, shipments, status_histories, change_logs, продавец, валюта); `OrderController::show` на него |
| `orders.changes` GET `orders/changes` | `type`, `date_from/to`, `cursor` | `OrderChangeAggregator::flatten` (как legacy `orderChanges`); `OrderChangeController::buildRows` на общий фильтр |
| `shipments.list` GET `shipments` | `status[]`, `payment_status[]` (при FINANCE), `date_from/to`, `updated_since`, `company_id`, `order`, `number`, `with_items`, `cursor` | **новые** `App\Services\Shipment\ClientShipmentQuery` + `ClientShipmentPresenter` (из legacy `shipmentPayload`); `ShipmentController::index/show` и legacy `shipments/shipment` на них |
| `shipments.get` GET `shipments/{shipment}` | id / uuid / номер (нормализация дефисов как в legacy) | те же; `payment_schedule` только при FINANCE |
| `reserves.list` GET `reserves` | — | как legacy `reserves()`; гейт RESERVE |

Место под блок `delivery` в `shipments.get` зарезервировано (эпик доставки, см. capi-16). Внутренние
организации скрываются (`withoutInternalOrganizations`). Сортировка списков — с тай-брейком `id`
для `cursorPaginate`.

## Критерии готовности

- [ ] Чужой заказ / реализация → 404 без подтверждения существования (id, номер, uuid).
- [ ] Блок оплаты и `payment_status` появляются только при `CabinetFinance::enabledFor`.
- [ ] `reserves.list` вне режима → 403 `reserve_unavailable`; в режиме — `items_version`, `reserved_until`.
- [ ] `cost_price` и внутренние поля не утекают (аналог `it_never_exposes_cost_price`).
- [ ] Кабинетные тесты заказов/реализаций и legacy `ClientApiShipmentsTest`, `ClientApiOrderChangesTest` зелёные без правок.
- [ ] `make lint` зелёный.
