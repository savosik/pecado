# Заказы

> **JSON Schema:** [`order.created.json`](/docs/erp/schemas/order.created.json) | [`order.created.to_erp.json`](/docs/erp/schemas/order.created.to_erp.json) | [`order.updated.json`](/docs/erp/schemas/order.updated.json) | [`order.deleted.json`](/docs/erp/schemas/order.deleted.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `order.created` | Сайт → 1С | `erp_out.orders` |
| `order.created` | 1С → Сайт | `erp_in.orders` |
| `order.updated` | 1С → Сайт | `erp_in.orders` |
| `order.deleted` | 1С → Сайт | `erp_in.orders` |

---

## order.created (Сайт → 1С)

### Бизнес-правила

- Корзина разделяется на заказ (`type: "order"`) + предзаказ (`type: "preorder"`)
- Заказ фиксируется в валюте пользователя с курсом и коэффициентом
- После отправки пользователь изменять заказ **не может**
- Если ни один склад из `warehouse_uuids` не найден — используется `ОсновнойСклад`
- `comment` включается в комментарий к заказу в 1С

### Сторона 1С

- 1С сохраняет UUID заказа с сайта как реквизит (не как ссылку)
- Комментарий формируется из: номер заказа, тип, партнёр, контрагент (ИНН+наименование), валюта, адрес доставки, комментарий покупателя
- Контрагент сопоставляется по ИНН (`tax_id`)

---

## order.created (1С → Сайт)

### Бизнес-правила

- Формат идентичен `order.created` от сайта
- Используется когда менеджер создал заказ вручную в 1С
- **(v12.1)** Если передан `delivery_address` (строка) — сайт сохраняет значение напрямую в текстовое поле `orders.delivery_address`

---

## order.updated (1С → Сайт)

### Бизнес-правила

- Если передан `items` — он **полностью заменяет** текущие позиции
- Не передавайте `items`, если обновляете только статус
- **(v11)** При изменении позиций сайт фиксирует diff в `order_change_logs`
- **(v12.1)** Если передан `delivery_address` — сайт сохраняет значение напрямую в текстовое поле `orders.delivery_address`

### Маппинг статусов

| Статус 1С | Значение на сайте |
|---|---|
| Не согласован | `pending` |
| К выполнению | `confirmed` |
| В работе | `processing` |
| Выполнен | `completed` |
| Закрыт | `closed` |

---

## order.deleted (1С → Сайт)

### Бизнес-правила

- Заказ помечается как `cancelled` (soft delete), не удаляется физически

---

## Критерии приёмки

- [ ] Сайт публикует `order.created` при оформлении заказа
- [ ] Каждая позиция содержит `base_price`, `discount_percent`, `final_price` (v7)
- [ ] Сайт принимает `order.created` (от менеджера), `order.updated`, `order.deleted`
- [ ] `order.updated` с `items` полностью заменяет позиции
- [ ] `order.deleted` ставит статус `cancelled` (soft delete)
- [ ] История изменений позиций фиксируется в `order_change_logs` (v11)
- [ ] `delivery_address` сохраняется напрямую в текстовое поле `orders.delivery_address` (v12.1)
