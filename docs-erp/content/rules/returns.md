# Возвраты

> **JSON Schema:** [`return.created.to_erp.json`](/docs/erp/schemas/return.created.to_erp.json) | [`return.updated.json`](/docs/erp/schemas/return.updated.json) | [`return.deleted.json`](/docs/erp/schemas/return.deleted.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `return.created` | Сайт → 1С | `erp_out.returns` |
| `return.updated` | 1С → Сайт | `erp_in.returns` |
| `return.deleted` | 1С → Сайт | `erp_in.returns` |

---

## return.created (Сайт → 1С)

### Бизнес-правила

- **(v12.13)** Возврат формируется на основании **реализаций (shipments)**, а не заказов. Возвращать можно только то, что физически отгружено.
- Одна заявка на возврат может содержать позиции из разных реализаций — привязка хранится на уровне позиции возврата (`return_items.shipment_item_id`).
- Цена каждой позиции фиксируется как **snapshot** из `ShipmentItem.price` в момент создания возврата. Последующие переоценки реализации со стороны 1С цену возврата не меняют.
- Валюта возврата совпадает с валютой реализации — передаётся полем `currency_code`.
- Поле `order_uuid` в корне сообщения **удалено** (v12.13, BREAKING). Если 1С нужно связать возврат с заказом, она делает это через цепочку `shipment_uuid → shipment.order_uuid`.

### Обязательные поля позиции

| Поле | Тип | Описание |
|---|---|---|
| `product_uuid` | uuid | UUID товара (ERP) |
| `shipment_uuid` | uuid | UUID реализации, из которой возвращается позиция |
| `shipment_number` | string | Человекочитаемый номер реализации (shipments.number), с v12.12 обязателен |
| `quantity` | number ≥ 1 | Количество к возврату (не больше отгруженного и не возвращённого ранее) |
| `price` | number ≥ 0 | Цена одной единицы — snapshot из ShipmentItem |
| `currency_code` | string | Код валюты реализации (например, `RUB`) |

Опциональные: `subtotal` (price × quantity), `reason`.

### Валидация сайта при создании возврата

- `ShipmentItem.shipment.user_id` должен совпадать с пользователем, от имени которого создаётся возврат (иначе 403).
- `sum(return_items.quantity)` по одной `shipment_item_id` не может превышать `ShipmentItem.quantity` (иначе 422 с сообщением «Доступно к возврату: N»).

---

## return.updated (1С → Сайт)

### Бизнес-правила

- Обновляет статус возврата по UUID.
- **(v12.3)** Если передан `number` — сайт сохраняет его как `erp_number`. Это номер возврата из 1С, который отображается пользователю для коммуникации с менеджером.
