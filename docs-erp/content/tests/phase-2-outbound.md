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
- [ ] `contractor` может быть объектом или `null`; если объект передан, в нём есть `tax_id`, `legal_name`, `bank_accounts` и опциональный `uuid` (UUID контрагента в 1С, v13.2)
- [ ] `items[]` содержит `quantity`, `base_price`, `discount_percent`, `final_price`; `product_uuid` может быть `null`
- [ ] `discount_percent > 0` трактуется как скидка, `discount_percent < 0` — как наценка; при расхождении источником истины считается `final_price`
- [ ] `currency_code`, `exchange_rate`, `rate_coefficient`, `delivery_address`, `comment` могут быть заполнены либо `null`
- [ ] 1С сопоставляет контрагента: приоритет `contractor.uuid`, fallback `tax_id` (v13.2)
- [ ] Если склад не найден — `ОсновнойСклад`
- [ ] **Эхо-фикс (2026-04-23):** при оформлении нового заказа в очереди публикуется только `order.created`; `order.updated` НЕ предшествует `order.created`

---

## 2.2а — Заказ-замена по недобору (`order.created` + `replaces_order_uuid`, v16.2.0)

**Зависимости:** 2.2 (заказ), обмен `order.updated` с `cancelled: true` (недобор)

🟢 **Разработчик сайта** воспроизводит цепочку: 1С отменяет строки заказа при сборке →
на сайте клиент согласовывает подборку замен → сайт создаёт заказ-замену.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.to_erp.json)

**Проверка (1С-ник):**

- [ ] В `erp_out.orders` появился обычный `order.created` — как в 2.2, все проверки применимы
- [ ] `manager_comment` содержит текст «Замена недоборов по заказу N»
- [ ] При включённом на сайте флаге отправки поля: `replaces_order_uuid` = UUID исходного заказа
- [ ] Незнакомое поле `replaces_order_uuid` **не роняет** приёмник 1С (главная проверка перед включением)
- [ ] У обычных заказов (2.2, 2.3) поле отсутствует — payload не изменился байт-в-байт
- [ ] Смешанный выбор клиента (обычный товар + уценка) даёт два заказа-замены: `type: "order"` и `type: "defect"`, оба с одним `replaces_order_uuid`

---

## 2.3 — Предзаказ (`order.created`, `type: "preorder"`)

**Зависимости:** аналогично 2.2

🟢 Товар только на складе предзаказа → оформить заказ.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.to_erp.json)

- [ ] `type: "preorder"`
- [ ] `warehouse_uuids` содержит UUID склада предзаказа

---

## 2.6 — Промо-позиции (`order.created`, `type: "promo"`, v15.6)

**Зависимости:** 2.2, настроенная акция в режиме выдачи

🟢 Набрать корзину так, чтобы сработала акция с подотчётной наградой → оформить заказ.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.to_erp.json)

- [ ] В `erp_out.orders` пришло **два** сообщения: `type: "order"` и `type: "promo"`
- [ ] У промо-заказа `warehouse_uuids` содержит склады наличия региона клиента
- [ ] Позиции промо-заказа несут `is_promo: true` и `promo_kind: "accountable"`
- [ ] `final_price` позиции равен цене из награды; `base_price` — обычная цена клиента
- [ ] Подарок: `final_price = 0`, `discount_percent = 100`, заказ с нулевой суммой принят
- [ ] Промо-позиция не подняла сумму корзины: акция со следующим порогом не сработала

---

## 2.7 — Рекламные образцы (`order.created`, `type: "promo_sample"`, v15.6)

**Зависимости:** 2.6, склад «Москва подарки» с заполненным `external_id`

🟢 Акция с наградой вида «рекламный образец» → оформить заказ.

- [ ] `type: "promo_sample"`, `warehouse_uuids` содержит склад «Москва подарки»
- [ ] Позиции несут `promo_kind: "sample"`
- [ ] Позиции **не выписываются** в накладную клиенту
- [ ] Если у склада «Москва подарки» **не заполнен** `external_id` — заказ **не публикуется**,
      в лог сайта пишется warning с номером заказа (пустой `warehouse_uuids` для 1С хуже,
      чем отсутствие сообщения)

---

## 2.4 — Создание возврата (`return.created`, v12.13 BREAKING)

**Зависимости:** 1.16 (реализация), у пользователя есть закрытый заказ с проведённой реализацией

🟢 Создать возврат через ЛК → Реализация → Возврат (привязка возврата теперь к **реализации**, а не к заказу).

> Структура payload → [JSON Schema](/docs/erp/schemas/return.created.to_erp.json)

- [ ] В `erp_out.returns` — сообщение `return.created`
- [ ] Обязательные поля по схеме: `event`, `uuid`, `items`
- [ ] **Поле `order_uuid` в корне отсутствует** (v12.13: удалено)
- [ ] `partner_uuid` может быть строкой или `null`
- [ ] Каждый элемент `items[]` содержит:
    - `shipment_uuid` — UUID реализации (новое обязательное поле, v12.13)
    - `shipment_number` — человекочитаемый номер реализации (новое обязательное поле, v12.13)
    - `price` — snapshot цены из `ShipmentItem.price`
    - `currency_code` — валюта реализации
    - `quantity`
    - опциональный `subtotal = price × quantity`
    - `product_uuid` и `reason` могут быть `null`
- [ ] 1С привязывает возврат к конкретной реализации по `shipment_uuid` (а не к заказу)
- [ ] Бухгалтерия возвратов сходится с реализацией по сумме `price × quantity`

---

## 2.5 — Публикация контрагента (`contractor.created`, v13.2)

**Зависимости:** 1.9 (партнёр с `User.erp_id`)

🟢 **Разработчик сайта** в ЛК → «Компании» создаёт новый контрагент с непустым `tax_id` (или впервые заполняет `tax_id` у существующей Company).

> Структура payload → [JSON Schema](/docs/erp/schemas/contractor.created.to_erp.json)

**Проверка (1С-ник):**

- [ ] В очереди `erp_out.contractors` появилось сообщение `contractor.created`
- [ ] Обязательные поля: `event`, `uuid` (локальный UUIDv4 сайта для корреляции), `partner_uuid`, `tax_id`, `name`
- [ ] Опциональные: `legal_name`, `country`, `tax_code`, `registration_number`, `okpo_code`, `legal_address`, `actual_address`, `phone`, `email`, `bank_accounts[]`
- [ ] Сайт **НЕ** публикует, если у партнёра нет `User.erp_id` (откладывается до получения UUID; затем `PublishUserToErp` догоняет)
- [ ] env `PUBLISH_CONTRACTORS_TO_ERP=false` — kill-switch, отключает publisher без деплоя

🟢 **1С обрабатывает** `contractor.created` от сайта:

- [ ] Матчинг контрагента в 1С по `tax_id` **в рамках партнёра** (`partner_uuid`)
- [ ] Если не найден — создать нового, 1С генерирует **собственный** UUID
- [ ] **`uuid` из payload сайта использовать только для корреляции**, не как ссылку на сущность 1С
- [ ] После обработки 1С отправляет `contractor.updated` в `erp_in.contractors` с назначенным UUID — сайт привязывает `Company.erp_id` (см. 1.12а)
