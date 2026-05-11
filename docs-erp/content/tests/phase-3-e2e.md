# Фаза 3: Сквозные сценарии

---

## 3.1 — Полный жизненный цикл заказа

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Пользователь оформляет заказ | `order.created` в `erp_out.orders`, статус `pending_approval` |
| 2 | 🔵 1С | Согласовал → `order.updated` `ready_for_provision` | Статус → `ready_for_provision`, запись в `order_status_histories` |
| 3 | 🔵 1С | Готов к отгрузке → `order.updated` `ready_for_shipment` | Статус → `ready_for_shipment` |
| 4 | 🔵 1С | Изменил позиции → `order.updated` с `items[]` | Позиции пересозданы, diff в `order_change_logs` |
| 5 | 🔵 1С | Реализация → `shipment.created` | Реализация в ЛК |
| 6 | 🔵 1С | Запустил отгрузку → `order.updated` `shipping` | Статус → `shipping` |
| 7 | 🔵 1С | Готов к закрытию → `order.updated` `ready_for_closure` | Статус → `ready_for_closure` |
| 8 | 🔵 1С | Закрывает → `order.updated` `closed` | Статус → `closed` |
| 9 | 🔵 1С | Удаляет → `order.deleted` | soft-delete (`deleted_at` заполнен), статус = `closed` |
| 10 | 🔵 1С | Баланс → `balance.updated` | Баланс обновлён |

**Итоговая проверка:**

- [ ] `order_status_histories`: `pending_approval` → `ready_for_provision` → `ready_for_shipment` → `shipping` → `ready_for_closure` → `closed`
- [ ] После `order.deleted`: `orders.deleted_at` заполнен, статус = `closed`
- [ ] `order_change_logs`: изменения позиций зафиксированы
- [ ] Реализация привязана к заказу
- [ ] Баланс отражает задолженность

---

## 3.2 — Жизненный цикл партнёра

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Регистрация | `partner.created` → `erp_out.partners` |
| 2 | 🔵 1С | `partner.created` с `client_status: "silver"` | Статус Silver |
| 3 | 🔵 1С | Повышение → `client_status: "gold"` | Статус → Gold |
| 4 | 🔵 1С | Инд. → `client_status: "individual"` | Статус → Индивидуальный |
| 5 | 🔵 1С | Убирает → `client_status: null` | Статус сброшен |
| 6 | 🔵 1С | Деактивация → `partner.deleted` | `is_active = false` |

---

## 3.3 — Цепочка: Реализация → Баланс

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🔵 1С | `shipment.created` (с обязательным `number`, v12.12) | Реализация появилась, `erp_number` заполнен |
| 2 | 🔵 1С | `balance.updated` с `overdue_details` | Просрочка детализирована |
| 3 | 🔵 1С | `shipment.updated` (перепроведение) | Реализация обновлена |
| 4 | 🔵 1С | `balance.updated` с новой суммой | Баланс пересчитан |

---

## 3.4 — UUID-workflow контрагента (v13.2)

Полный сценарий синхронизации Company между сайтом и 1С через UUID + переживание смены ИНН менеджером.

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Пользователь в ЛК создаёт Company с `tax_id = "7710140679"` | В `erp_out.contractors` — `contractor.created` с `partner_uuid` и `tax_id` (v13.2) |
| 2 | 🔵 1С | Принимает `contractor.created`, создаёт контрагента, отправляет `contractor.updated` в `erp_in.partners` с назначенным UUID | На сайте `Company.erp_id` заполнен (ленивый backfill, см. 1.12а) |
| 3 | 🔵 1С | Менеджер правит ИНН на `"7710140600"` → `contractor.updated` с тем же `uuid` и новым `tax_id` | `Company.tax_id` обновлён по UUID, `erp_id` остался прежним |
| 4 | 🔵 1С | `shipment.created` с `contractor_uuid` + `partner_uuid` + новым `tax_id` (v12.12: `number` обязателен) | Shipment привязан к правильной Company по UUID, в ЛК отображается с `erp_number` |
| 5 | 🔵 1С | `balance.updated` с `contractors[].uuid` + новым `tax_id` | `ContractorBalance.contractor_uuid` заполнен, баланс обновлён |
| 6 | 🔵 1С | `contractor.deleted` с `uuid` | Company → soft-delete, исчезает из ЛК |

**Итоговая проверка:**

- [ ] Смена ИНН в 1С не ломает связь с Company на сайте
- [ ] Все последующие сообщения находят Company по UUID независимо от ИНН
- [ ] Регрессия security: `shipment.created` без `partner_uuid` и без `contractor_uuid` НЕ привязывается к чужой Company по совпадению `tax_id`

---

## 3.5 — Возврат через реализацию (v12.13 BREAKING)

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Пользователь оформляет заказ | `order.created` → `erp_out.orders` |
| 2 | 🔵 1С | `order.updated` `ready_for_provision` → `ready_for_shipment` → `closed` | Статусы прошли |
| 3 | 🔵 1С | `shipment.created` с `number` (v12.12) и `items[].price` | Реализация в ЛК с ERP-номером |
| 4 | 🟢 Сайт | Пользователь идёт в ЛК → Реализация → Возврат и оформляет возврат | `return.created` → `erp_out.returns` |
| 5 | 🔵 1С | Принимает `return.created` | В payload **нет** корневого `order_uuid`; есть `items[].shipment_uuid`, `items[].shipment_number`, `items[].price`, `items[].currency_code` |

**Итоговая проверка:**

- [ ] Возврат в 1С привязан к конкретной реализации, а не к заказу
- [ ] Сумма возврата = сумма по `price × quantity` из payload и совпадает с реализацией
