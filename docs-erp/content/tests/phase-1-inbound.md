# Фаза 1: 1С → Сайт

> Последовательность тестов соответствует порядку первоначальной выгрузки (зависимости сущностей).

---

## 1.1 — Категории (`category.created`)

**Зависимости:** нет

🔵 **1С отправляет** два сообщения в `erp.events` с routing key `category.created`: сначала родительскую, затем дочернюю.

> Структура payload → [JSON Schema](/docs/erp/schemas/category.created.json)

- [ ] В `categories` появились 2 записи
- [ ] У дочерней `parent_id` указывает на родительскую
- [ ] `external_id` совпадает с `uuid` из payload
- [ ] В `erp_processed_messages` записаны оба `message_id`

---

## 1.2 — Обновление категории (`category.updated`)

**Зависимости:** Тест 1.1

🔵 **1С отправляет** `category.updated` с новым `name`.

> Структура payload → [JSON Schema](/docs/erp/schemas/category.created.json) (формат идентичен `category.created`)

- [ ] `name` обновлён
- [ ] `parent_id` НЕ изменился (частичное обновление)

---

## 1.3 — Товары (`product.created`)

**Зависимости:** 1.1 (категории)

🔵 **1С отправляет** `product.created` с `category_uuid`, `brand`, `attributes`.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.created.json)

- [ ] Товар создан с `external_id`
- [ ] `category_id` указывает на правильную категорию
- [ ] Бренд создан/привязан
- [ ] Атрибуты созданы
- [ ] `hidden = false` — товар видим

---

## 1.4 — Товар со скрытием (`hidden = true`)

**Зависимости:** 1.1

🔵 **1С отправляет** `product.created` с `hidden = true`.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.created.json)

- [ ] Товар создан с `hidden = true`
- [ ] Товар **НЕ** отображается в каталоге и поиске

---

## 1.5 — Обновление товара (`product.updated`)

**Зависимости:** 1.3

🔵 **1С отправляет** `product.updated` с новым `name` и новым атрибутом.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.updated.json)

- [ ] `name` обновлён
- [ ] Старые атрибуты **сохранены** (мерж по `property_uuid`)
- [ ] Новый атрибут добавлен
- [ ] `sku`, `code`, `category_uuid` НЕ изменились

---

## 1.6 — Базовые цены (`price.updated`)

**Зависимости:** 1.3 (товар)

🔵 **1С отправляет** `price.updated` с ценой.

> Структура payload → [JSON Schema](/docs/erp/schemas/price.updated.json)

- [ ] Цена товара обновлена
- [ ] Цена отображается в каталоге

---

## 1.7 — Остатки (`stock.updated`)

**Зависимости:** 1.3 (товар), 0.4.2 (склады)

🔵 **1С отправляет** `stock.updated` с `warehouse_uuid` и `quantity`.

> Структура payload → [JSON Schema](/docs/erp/schemas/stock.updated.json)

- [ ] В `product_warehouse` появилась запись
- [ ] Товар «В наличии» для пользователя из региона

---

## 1.8 — Курсы валют (`exchange_rate.updated`)

**Зависимости:** нет

🔵 **1С отправляет** `exchange_rate.updated` для KZT.

> Структура payload → [JSON Schema](/docs/erp/schemas/exchange_rate.updated.json)

- [ ] Запись создана/обновлена в `exchange_rates`
- [ ] Все три значения сохранены: `official_rate`, `rate_coefficient`, `rate`

---

## 1.9 — Партнёры (`partner.created`)

**Зависимости:** 0.4.4 (статусы клиентов)

🔵 **1С отправляет** `partner.created` с `client_status: "gold"`.

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.created.json)

- [ ] Пользователь создан с `external_id`
- [ ] `client_status_id` → ClientStatus с `external_id = gold`
- [ ] Может войти с паролем из payload
- [ ] Обязательная смена пароля при первом входе
- [ ] Плашка «Gold» в ЛК

---

## 1.10 — Обновление атрибутов партнёра (`partner.updated`)

**Зависимости:** 1.9

🔵 **1С отправляет** `partner.updated` с обновлёнными атрибутами.

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.updated.json)

- [ ] `name` обновлён
- [ ] `phone` обновлён
- [ ] `city` обновлён
- [ ] `client_status_id` обновился → `diamond`
- [ ] Пароль **НЕ** изменился
- [ ] Плашка «Diamond» в ЛК

---

## 1.10а — Обновление статуса партнёра (`partner.updated`)

**Зависимости:** 1.9

🔵 **1С отправляет** `partner.updated` с `is_active: false`.

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.updated.json)

- [ ] Статус → `BLOCKED`
- [ ] Пользователь не может авторизоваться

🔵 **1С отправляет** `partner.updated` с `is_active: true`.

- [ ] Статус → `ACTIVE`
- [ ] Пользователь может авторизоваться

---

## 1.10б — Привязка erp_id по email (`partner.updated`)

**Зависимости:** пользователь зарегистрирован на сайте (без `erp_id`)

🔵 **1С отправляет** `partner.updated` с `uuid` и `login` = email пользователя.

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.updated.json)

- [ ] `erp_id` привязан к пользователю
- [ ] Атрибуты обновлены
- [ ] Повторный `partner.updated` с тем же `uuid` — идемпотентный (обновление по `erp_id`)

---

## 1.11 — Деактивация партнёра (`partner.deleted`)

**Зависимости:** 1.9

🔵 **1С отправляет** `partner.deleted`.

> Структура payload → [JSON Schema](/docs/erp/schemas/partner.deleted.json)

- [ ] `is_active = false`
- [ ] Не может авторизоваться
- [ ] Не может оформлять заказы

!!! warning "После этого теста"
    Активируйте пользователя обратно (`partner.updated` с `is_active: true`).

---

## 1.12 — Контрагенты (`contractor.created`)

**Зависимости:** 1.9 (партнёр)

🔵 **1С отправляет** `contractor.created` с `bank_accounts`.

> Структура payload → [JSON Schema](/docs/erp/schemas/contractor.created.json)

- [ ] Контрагент создан, привязан к пользователю
- [ ] `tax_id`, `legal_name` заполнены
- [ ] 2 банковских счёта, первый `is_primary = true`
- [ ] Контрагент в ЛК → «Компании»

---

## 1.13 — Заказ от менеджера (`order.created`)

**Зависимости:** 1.3 (товар), 1.9 (партнёр)

🔵 **1С отправляет** `order.created` со статусом `confirmed`.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.json)

- [ ] Заказ создан с `external_id`
- [ ] Статус = `confirmed`, тип = `order`
- [ ] Позиции с `base_price`, `discount_percent`, `final_price`
- [ ] `delivery_address` сохранён в текстовое поле `orders.delivery_address` (v12.1)
- [ ] Заказ в ЛК

---

## 1.14 — Обновление заказа (`order.updated`)

**Зависимости:** 1.13

### A) Только статус

> Структура payload → [JSON Schema](/docs/erp/schemas/order.updated.json)

🔵 `order.updated` с `status: "processing"` (без `items`).

- [ ] Статус → `processing`
- [ ] Запись в `order_status_histories`
- [ ] Позиции НЕ изменились

### B) Статус + позиции

🔵 `order.updated` с `items[]` (изменённая цена/кол-во).

- [ ] Позиции полностью пересозданы
- [ ] Количество и цены обновлены
- [ ] Diff зафиксирован в `order_change_logs`

---

## 1.15 — Удаление заказа (`order.deleted`)

**Зависимости:** создать тестовый заказ

🔵 **1С отправляет** `order.deleted`.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.deleted.json)

- [ ] Заказ → `cancelled` (soft delete)
- [ ] Не отображается в активных заказах

---

## 1.16 — Реализации (`shipment.created`)

**Зависимости:** 1.12 (контрагент), 1.13 (заказ)

🔵 **1С отправляет** `shipment.created` с `contractor_inn` и `items`.

> Структура payload → [JSON Schema](/docs/erp/schemas/shipment.created.json)

- [ ] Реализация создана, привязана к контрагенту по ИНН
- [ ] Позиция связана с заказом через `order_uuid`
- [ ] Реализация в ЛК

---

## 1.17 — Баланс (`balance.updated`)

**Зависимости:** 1.9 (партнёр), 1.12 (контрагент), 1.16 (реализация)

🔵 **1С отправляет** `balance.updated` с `overdue_details`.

> Структура payload → [JSON Schema](/docs/erp/schemas/balance.updated.json)

- [ ] Баланс обновлён
- [ ] Просрочка детализирована по реализации
- [ ] Баланс в ЛК в разрезе контрагентов

---

## 1.18 — Индивидуальные цены (`individual_prices.ready`)

**Зависимости:** 1.3 (товар), 1.9 (партнёр), 0.3 (MinIO)

### Шаг A: CSV в MinIO

🔵 1С загружает CSV: `product_uuid,warehouse_uuid,price`

### Шаг B: Уведомление

🔵 `individual_prices.ready` с `upload_type: "full"`.

> Структура payload → [JSON Schema](/docs/erp/schemas/individual_prices.ready.json)

- [ ] Цена в `individual_prices`
- [ ] CSV удалён из MinIO
- [ ] Авторизованный пользователь видит индивидуальную цену

---

## 1.19 — Дельта индивидуальных цен

**Зависимости:** 1.18

🔵 `individual_prices.ready` с `upload_type: "delta"` и новой ценой.

- [ ] Цена **обновлена** (UPSERT)
- [ ] Другие цены партнёра **НЕ удалены**
