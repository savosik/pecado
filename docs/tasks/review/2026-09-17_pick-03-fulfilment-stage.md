# pick-03 · Стадия исполнения заказа и резолвер

**Приоритет:** высокий
**Создано:** 2026-09-17
**Эпик:** [pick-00](../in-progress/2026-09-17_pick-00-epic.md)
**Зависимости:** —
**Волна:** 1

## Описание

Клиенту нужен один понятный ответ «что с моим заказом», а не десять статусов 1С и семь статусов
расходного ордера. Приём уже есть: «В резерве» поверх `ready_for_shipment`
(`Cabinet/Orders/Show.jsx:478-493`). Обобщаем его.

- `app/Enums/OrderFulfilmentStage.php`: `reserved`, `sent_to_warehouse`, `picking`, `ready`,
  `handed_over`, `shipped`, `none` (закрыт, отменён, предзаказ без движения). `label()`, `color()`,
  `hint()` — на русском. Зеркало на фронте — `resources/js/constants/fulfilmentStage.js`.
- `app/Services/Pickup/OrderFulfilmentResolver.php`:
  - `forOrders(Collection $orders): array<order_id, FulfilmentView>` — **пакетно, два запроса**:
    РО по `goods_issue_items.order_uuid` (только по uuid, `order_id` пуст у четверти строк),
    выдачи по `goods_issue_id`. Никаких запросов в цикле.
  - `FulfilmentView`: `stage`, `label`, `is_pickup`, `goods_issues[]` (номер, статус, мест,
    выдан ли, кем и когда), `packages_total`, `packages_handed`, `promised_ready_at`,
    `ready_since`, `is_mixed`.
  - Правила (по живым данным, см. эпик): `picking` = любой РО в `prepared…to_ship`;
    `ready` = самовывоз и все активные РО `shipped` без выдачи; `handed_over` = по всем РО есть
    неотменённая выдача; для доставки после `shipped` — `shipped`. Стадия заказа — минимальная
    по его РО. Удалённые (soft-deleted) РО не считаются.
  - **Ничего не хранит**: `shipped` обратим, стадия всегда считается на лету.
- Связь `Order::goodsIssues()` через `goods_issue_items.order_uuid` (сейчас связи на модели нет).
- `readyForUser(User)`: РО клиента, готовые к выдаче, — общий источник для кабинета, пропуска и API.
  Клиент определяется **от заказа** (`orders.user_id`), не от шапки РО.

## Ход работ

- **17.09.2026** — `App\Enums\OrderFulfilmentStage`, `App\Services\Pickup\OrderFulfilmentResolver`: `forOrders` (три запроса
  на любой список), `forOrder`, `issueStage`, `readyForUser`, `issuesForUser`, `timeline` (складская история для карточки),
  `handoverSince`. Связи `GoodsIssue::activeHandover()` и `handovers()`. Правила — по живым данным: «собран» = `shipped`
  без выдачи, связь только по `order_uuid`, клиент от заказа, дата отсечения истории.
  Тесты `tests/Feature/Pickup/OrderFulfilmentResolverTest.php` (12). На копии боевой базы в MySQL: 50 заказов — 3 запроса, 13 мс.

## Критерии готовности

- [x] Тесты: заказ без РО, один РО по стадиям, два РО в разных стадиях, РО на несколько заказов,
      смешанный РО, откат `shipped → to_pick`, удалённый РО, отменённая выдача, заказ доставки.
- [x] Список из 50 заказов — не больше трёх запросов резолвера.
- [x] Запросы с агрегатами проверены на MySQL (SQLite прячет `only_full_group_by`).
