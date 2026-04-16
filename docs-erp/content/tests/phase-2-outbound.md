# Фаза 2: Сайт → 1С

---

## 2.1 — Регистрация пользователя (`partner.created`)

**Зависимости:** нет

🟢 **Разработчик сайта** регистрирует нового пользователя (имя, email, телефон).

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.created.to_erp.json)

**Проверка (1С-ник):**

- [ ] В `erp_out.partners` появилось сообщение
- [ ] Обязательные поля по схеме: `event`, `uuid`, `login`, `name`, `email`
- [ ] `phone`, `message_id`, `timestamp` передаются опционально
- [ ] `uuid` заполнен
- [ ] `login = email`
- [ ] 1С ищет по email → если не найден — создаёт нового
- [ ] 1С **НЕ** использует `uuid` из payload — генерирует свой

---

## 2.2 — Оформление заказа (`order.created`)

**Зависимости:** 1.3 (товар), 1.7 (остатки), 1.9 (партнёр), 1.12 (контрагент)

🟢 **Разработчик сайта** авторизуется и оформляет заказ:

1. Добавить товар в корзину
2. Выбрать контрагента
3. Указать адрес доставки
4. Оформить заказ

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.to_erp.json)

**Проверка (1С-ник):**

- [ ] В `erp_out.orders` появилось сообщение
- [ ] Обязательные поля по схеме: `event`, `uuid`, `items`
- [ ] Если передан `status` — он из enum: `pending`, `confirmed`, `ready_to_ship`, `closed`, `deleted`
- [ ] `type` передаётся строкой; для обычного заказа ожидается `order`
- [ ] `partner_uuid` может быть строкой или `null`
- [ ] `contractor` может быть объектом или `null`; если объект передан, в нём есть `tax_id`, `legal_name`, `bank_accounts`
- [ ] `items[]` содержит `quantity`, `base_price`, `discount_percent`, `final_price`; `product_uuid` может быть `null`
- [ ] `discount_percent > 0` трактуется как скидка, `discount_percent < 0` — как наценка; при расхождении источником истины считается `final_price`
- [ ] `currency_code`, `exchange_rate`, `rate_coefficient`, `delivery_address`, `comment` могут быть заполнены либо `null`
- [ ] 1С сопоставляет контрагента по ИНН
- [ ] Если склад не найден — `ОсновнойСклад`

---

## 2.3 — Предзаказ (`order.created`, `type: "preorder"`)

**Зависимости:** аналогично 2.2

🟢 Товар только на складе предзаказа → оформить заказ.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.to_erp.json)

- [ ] `type: "preorder"`
- [ ] `warehouse_uuids` содержит UUID склада предзаказа

---

## 2.4 — Создание возврата (`return.created`)

!!! warning "Отложено"
    US-09 отложен на следующий скоп.

**Зависимости:** 1.13 или 2.2 (заказ в статусе `closed`)

🟢 Создать возврат через ЛК → Заказ → Возврат.

> Структура payload → [JSON Schema](/docs/erp/schemas/return.created.to_erp.json)

- [ ] В `erp_out.returns` — сообщение `return.created`
- [ ] Обязательные поля по схеме: `event`, `uuid`, `items`
- [ ] `order_uuid` и `partner_uuid` могут быть строкой или `null`
- [ ] `items[]` содержит `quantity`; `product_uuid` и `reason` могут быть `null`
