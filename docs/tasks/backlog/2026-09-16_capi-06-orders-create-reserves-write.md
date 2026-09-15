# capi-06: создание заказа, отмена, запись резервов; legacy становится обёрткой

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-03, capi-04, capi-05

## Описание

Логика создания заказа сегодня живёт в legacy `ClientApiController::orders()` (~240 строк: компания
по ИНН, резолв товара, раскладка instock/preorder/not_accepted/partial, «дружественное урезание»,
`ClientApiPromotions`, `OrderDraft` → `OrderAssembler`, `logApiShortfall`, `buildFulfillmentNote`).
Она выносится в сервис, и legacy-метод становится обёрткой: валидация + прежний JSON-ответ.
Идемпотентности в legacy не появляется (контракт неизменен), в v1 ключ обязателен.

| Операция | Параметры | Сервис |
|---|---|---|
| `orders.create` POST `orders` (I!, C) | `products[{identifier, quantity}]`, `company_id` \| `inn`, `delivery_method`, `address`, `comment`, `apply_promotions`, `reserve` | **новый** `App\Services\Order\ApiOrderPlacement::place(User, Company, PlacementRequest): PlacementResult`; гейт RESERVE при `reserve=true` |
| `orders.cancel` POST `orders/{order}/cancel` | — | `ClientOrderActions::cancel`; гейт ORDER_CANCEL; `not_cancellable` 422 |
| `reserves.confirm` POST `reserves/{order}/confirm` | — | `ClientOrderActions::confirmReserve`; гейт RESERVE |
| `reserves.items` POST `reserves/{order}/items` | `base_items_version`, `items[{item_id, quantity}]` | `ClientOrderActions::updateReserveItems`; 409 `stale_items_version`, коды legacy сохраняются |

Сопутствующее:
- `ClientOrderActions::cancel` читает `request()->merge(['status_comment'])` — заменить на явный
  `App\Support\Order\StatusCommentContext`, читаемый там же, где сейчас `request('status_comment')`
  (проверить `Order::booted`/наблюдатель). Сервис не должен зависеть от HTTP.
- Комментарий истории статусов из API/агента — через `ClientApiSource` («отменён клиентом через API»).
- Legacy `orders()`, `reserveConfirm/Items/Cancel` — обёртки над теми же сервисами, ответы и коды прежние.

## Критерии готовности

- [ ] `ClientApiOrdersTest` (v1): паритет с legacy на общем фикстурном наборе (not_accepted, partial, промо, резерв); контекст юрлица — умолчание, `company_required` с перечнем, чужая компания 404, `inn` работает.
- [ ] Повтор `orders.create` с тем же ключом: один заказ в БД, одна `PublishOrderToErpJob` (`Queue::fake`); без ключа → 422 `idempotency_key_required`.
- [ ] `orders.cancel`: гейт 403 при выключенном режиме; `not_cancellable`; чужой → 404.
- [ ] `ClientApiReservesTest` (v1): 403 `reserve_unavailable`, confirm, items c 409 `stale_items_version`, `increase_forbidden`.
- [ ] Legacy `ClientApiOrdersTest`, `ClientApiPromotionsTest`, `ClientApiOrderPublishTest`, `ClientApiReservesTest` зелёные **без правок**.
- [ ] Кабинетные `OrderController::cancel`, `ReserveOrderController` работают без `request()` внутри сервиса; их тесты зелёные.
- [ ] Аудит-строки в `client-agent` для всех четырёх операций.
