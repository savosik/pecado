# Интеграция 1С ↔ Сайт Pecado через RabbitMQ

> Документация для программиста 1С. Описывает все exchanges, очереди, routing keys и форматы сообщений.

---

## Оглавление

1. [Общая архитектура](#1-общая-архитектура)
2. [Exchanges (точки обмена)](#2-exchanges)
3. [Входящие очереди (1С → Сайт)](#3-входящие-очереди-1с--сайт)
4. [Исходящие очереди (Сайт → 1С)](#4-исходящие-очереди-сайт--1с)
5. [Общий формат сообщений](#5-общий-формат-сообщений)
6. [Детальное описание событий (1С → Сайт)](#6-детальное-описание-событий-1с--сайт)
7. [Детальное описание событий (Сайт → 1С)](#7-детальное-описание-событий-сайт--1с)
8. [Dead Letter Queues (DLQ)](#8-dead-letter-queues-dlq)
9. [Идемпотентность](#9-идемпотентность)
10. [Тестирование и отладка](#10-тестирование-и-отладка)

---

## 1. Общая архитектура

```
┌─────────┐                    ┌──────────────┐                    ┌─────────┐
│         │  erp.events        │              │  site.events       │         │
│   1С    │ ──────────────▶    │  RabbitMQ    │ ◀──────────────    │  Сайт   │
│         │  (1С публикует)    │              │  (Сайт публикует)  │         │
└─────────┘                    └──────────────┘                    └─────────┘
```

**Два направления обмена:**
- **1С → Сайт**: 1С публикует сообщения в exchange `erp.events`, сайт читает из привязанных очередей
- **Сайт → 1С**: Сайт публикует сообщения в exchange `site.events`, 1С читает из привязанных очередей

> **Протокол**: AMQP 0.9.1  
> **Формат сообщений**: JSON (UTF-8)  
> **Тип exchanges**: `topic` (маршрутизация по routing key с поддержкой wildcards `*` и `#`)

---

## 2. Exchanges

| Exchange | Тип | Durable | Направление | Описание |
|---|---|---|---|---|
| `erp.events` | `topic` | ✅ да | **1С → Сайт** | 1С публикует сюда все события для сайта |
| `site.events` | `topic` | ✅ да | **Сайт → 1С** | Сайт публикует сюда все события для 1С |
| `erp.dlx` | `topic` | ✅ да | Внутренний | Dead Letter Exchange для не обработанных сообщений |

### ⭐ Для публикации сообщений из 1С на сайт используется exchange `erp.events`

---

## 3. Входящие очереди (1С → Сайт)

Все эти очереди привязаны к exchange `erp.events`. **1С публикует сообщения в exchange `erp.events` с соответствующим routing key**, и RabbitMQ автоматически маршрутизирует сообщение в нужную очередь.

| Очередь | Routing Keys | Описание |
|---|---|---|
| `erp_in.partners` | `partner.*` | Управление партнёрами (деактивация) |
| `erp_in.prices` | `price.*`, `discount.*`, `exchange_rate.*` | Цены, скидки, курсы валют |
| `erp_in.stock` | `stock.*` | Остатки товаров по складам |
| `erp_in.orders` | `order.*` | Обновление/удаление заказов |
| `erp_in.returns` | `return.*` | Обновление/удаление возвратов |
| `erp_in.documents` | `shipment.*` | Реализации (создание, обновление, удаление) |
| `erp_in.balance` | `balance.*` | Балансы контрагентов |
| `erp_in.segments` | `product_segment.*`, `partner_segment.*` | Сегменты номенклатуры и партнёров |
| `erp_in.catalog` | `category.*`, `product.*` | Категории и товары (справочник) |

---

## 4. Исходящие очереди (Сайт → 1С)

Все эти очереди привязаны к exchange `site.events`. **1С подписывается на эти очереди для получения данных от сайта.**

| Очередь | Routing Key | Описание |
|---|---|---|
| `erp_out.orders` | `order.created` | Новые заказы и предзаказы |
| `erp_out.returns` | `return.created` | Новые запросы на возврат |
| `erp_out.partners` | `partner.created` | Новые активированные пользователи |

---

## 5. Общий формат сообщений

Каждое сообщение — это JSON-объект в кодировке UTF-8. **Обязательные поля для всех сообщений от 1С:**

```json
{
  "event": "тип.события",
  "message_id": "уникальный-идентификатор-сообщения",
  "payload-поля": "..."
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `event` | `string` | ✅ | Тип события (например, `stock.updated`, `order.updated`). Должен совпадать с routing key |
| `message_id` | `string` | ✅ рекомендуется | Уникальный ID сообщения для идемпотентности. Формат: произвольная строка (рекомендуется UUID) |

> ⚠️ **Важно**: Если `message_id` не указан, сообщение всё равно будет обработано, но без защиты от повторной обработки. **Рекомендуется всегда указывать `message_id`.**

---

## 6. Детальное описание событий (1С → Сайт)

### 6.1 Партнёры

#### `partner.deleted` — Деактивация партнёра

**Routing key**: `partner.deleted`  
**Очередь**: `erp_in.partners`  
**Действие**: Блокирует пользователя на сайте (статус → `blocked`)

```json
{
  "event": "partner.deleted",
  "message_id": "msg-partner-del-001",
  "uuid": "erp-id-партнёра-uuid"
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | ERP ID партнёра (`erp_id` пользователя на сайте) |

---

### 6.2 Цены

#### `price.updated` — Обновление базовой цены товара

**Routing key**: `price.updated`  
**Очередь**: `erp_in.prices`  
**Действие**: Обновляет поле `base_price` товара

```json
{
  "event": "price.updated",
  "message_id": "msg-price-001",
  "product_uuid": "uuid-товара-из-1с",
  "price": 12500.50
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `product_uuid` | `string` | ✅ | UUID товара из 1С (поле `external_id` на сайте) |
| `price` | `number` | ✅ | Новая базовая цена |

---

### 6.3 Скидки

#### `discount.created` — Создание/обновление скидки

**Routing key**: `discount.created`  
**Очередь**: `erp_in.prices`  
**Действие**: Создаёт или обновляет скидку, привязывает товары, партнёров и сегменты. Идемпотентно — повторное создание с тем же `uuid` обновляет существующую.

```json
{
  "event": "discount.created",
  "message_id": "msg-discount-001",
  "uuid": "uuid-скидки-из-1с",
  "type": "percent",
  "value": 10,
  "starts_at": "2026-01-01T00:00:00+03:00",
  "ends_at": "2026-12-31T23:59:59+03:00",
  "product_uuids": ["uuid-товара-1", "uuid-товара-2"],
  "partner_uuids": ["erp-id-партнёра-1", "erp-id-партнёра-2"],
  "product_segment_uuids": ["uuid-сегмента-номенклатуры-1"],
  "partner_segment_uuids": ["uuid-сегмента-партнёра-1"]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID скидки в 1С |
| `type` | `string` | нет | Тип скидки (напр. `percent`) |
| `value` | `number` | ✅ | Значение скидки (%) |
| `starts_at` | `string (ISO 8601)` | нет | Дата начала действия |
| `ends_at` | `string (ISO 8601)` | нет | Дата окончания действия |
| `product_uuids` | `string[]` | нет | UUID товаров, к которым применяется скидка |
| `partner_uuids` | `string[]` | нет | ERP ID партнёров, которым доступна скидка |
| `product_segment_uuids` | `string[]` | нет | UUID сегментов номенклатуры |
| `partner_segment_uuids` | `string[]` | нет | UUID сегментов партнёров |

#### `discount.updated` — Обновление скидки

**Routing key**: `discount.updated`  
Формат полностью аналогичен `discount.created`.

#### `discount.deleted` — Удаление скидки

**Routing key**: `discount.deleted`  
**Действие**: Soft-delete скидки по UUID.

```json
{
  "event": "discount.deleted",
  "message_id": "msg-discount-del-001",
  "uuid": "uuid-скидки-из-1с"
}
```

---

### 6.4 Курсы валют

#### `exchange_rate.updated` — Обновление курса валюты

**Routing key**: `exchange_rate.updated`  
**Очередь**: `erp_in.prices`  
**Действие**: Обновляет курс валюты. Базовая валюта — RUB.

```json
{
  "event": "exchange_rate.updated",
  "message_id": "msg-rate-001",
  "currency_code": "USD",
  "rate": 92.50,
  "official_rate": 90.00,
  "rate_coefficient": 1.0278,
  "date": "2026-03-15"
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `currency_code` | `string` | ✅ | Код валюты (напр. `USD`, `EUR`, `CNY`) |
| `rate` | `number` | ✅ | Итоговый курс (= official_rate × rate_coefficient) |
| `official_rate` | `number` | нет | Курс нацбанка |
| `rate_coefficient` | `number` | нет | Поправочный коэффициент |
| `date` | `string` | нет | Дата курса (формат `YYYY-MM-DD`) |

---

### 6.5 Остатки (Stock)

#### `stock.updated` — Обновление остатков товара на складе

**Routing key**: `stock.updated`  
**Очередь**: `erp_in.stock`  
**Действие**: Обновляет количество товара на конкретном складе

```json
{
  "event": "stock.updated",
  "message_id": "msg-stock-001",
  "product_uuid": "uuid-товара-из-1с",
  "warehouse_uuid": "uuid-склада-из-1с",
  "quantity": 42
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `product_uuid` | `string` | ✅ | UUID товара в 1С (`external_id` на сайте) |
| `warehouse_uuid` | `string` | ✅ | UUID склада в 1С (`external_id` на сайте) |
| `quantity` | `integer` | ✅ | Текущий остаток на складе (абсолютное значение, не дельта) |

> ℹ️ Если товар или склад не найден на сайте — событие игнорируется без ошибки.

---

### 6.6 Заказы

#### `order.updated` — Обновление статуса заказа

**Routing key**: `order.updated`  
**Очередь**: `erp_in.orders`  
**Действие**: Обновляет статус заказа и (опционально) пересоздаёт позиции

```json
{
  "event": "order.updated",
  "message_id": "msg-order-upd-001",
  "uuid": "uuid-заказа",
  "status": "confirmed",
  "items": [
    {
      "product_uuid": "uuid-товара",
      "quantity": 5,
      "price": 1200.00
    }
  ]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID заказа |
| `status` | `string` | нет | Новый статус. Допустимые: `pending`, `confirmed`, `processing`, `shipped`, `delivered`, `cancelled` |
| `items` | `array` | нет | Если передан — **полностью заменяет** все позиции заказа (старые удаляются) |
| `items[].product_uuid` | `string` | ✅* | UUID товара |
| `items[].quantity` | `integer` | ✅* | Количество |
| `items[].price` | `number` | ✅* | Цена за единицу |

> ⚠️ Если передан массив `items` — он **полностью заменяет** текущие позиции заказа. Не передавайте `items`, если хотите обновить только статус.

#### `order.deleted` — Удаление заказа

**Routing key**: `order.deleted`  
**Действие**: Устанавливает статус `cancelled` и soft-delete.

```json
{
  "event": "order.deleted",
  "message_id": "msg-order-del-001",
  "uuid": "uuid-заказа"
}
```

---

### 6.7 Возвраты

#### `return.updated` — Обновление статуса возврата

**Routing key**: `return.updated`  
**Очередь**: `erp_in.returns`  
**Действие**: Обновляет статус возврата

```json
{
  "event": "return.updated",
  "message_id": "msg-return-upd-001",
  "uuid": "uuid-возврата",
  "status": "approved"
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID возврата |
| `status` | `string` | нет | Новый статус. Допустимые: `pending`, `approved`, `rejected`, `completed` |

#### `return.deleted` — Удаление возврата

**Routing key**: `return.deleted`

```json
{
  "event": "return.deleted",
  "message_id": "msg-return-del-001",
  "uuid": "uuid-возврата"
}
```

---

### 6.8 Реализации (Shipments)

#### `shipment.created` — Создание реализации

**Routing key**: `shipment.created`  
**Очередь**: `erp_in.documents`  
**Действие**: Создаёт или обновляет реализацию. Привязывает к контрагенту по ИНН.

```json
{
  "event": "shipment.created",
  "message_id": "msg-shipment-001",
  "uuid": "uuid-реализации",
  "contractor_inn": "7710140679",
  "date": "2026-03-15",
  "status": "new",
  "currency_code": "RUB",
  "items": [
    {
      "product_uuid": "uuid-товара",
      "quantity": 10,
      "price": 500.00,
      "total": 4500.00,
      "auto_discount_percent": 5,
      "manual_discount_percent": 5,
      "vat_rate": 20,
      "order_uuid": "uuid-связанного-заказа"
    }
  ]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID реализации в 1С |
| `contractor_inn` | `string` | нет | ИНН контрагента для привязки к компании на сайте |
| `date` | `string` | нет | Дата реализации |
| `status` | `string` | нет | Статус (по умолчанию `new`) |
| `currency_code` | `string` | нет | Код валюты |
| `items` | `array` | нет | Позиции реализации |
| `items[].product_uuid` | `string` | нет | UUID товара |
| `items[].quantity` | `integer` | нет | Количество |
| `items[].price` | `number` | нет | Цена за единицу (без скидок) |
| `items[].total` | `number` | нет | Итоговая сумма позиции (с учётом скидок). Если не передан — вычисляется как `quantity × price` |
| `items[].auto_discount_percent` | `number` | нет | Автоматическая скидка (%) |
| `items[].manual_discount_percent` | `number` | нет | Ручная скидка (%) |
| `items[].vat_rate` | `number` | нет | Ставка НДС (%) |
| `items[].order_uuid` | `string` | нет | UUID связанного заказа |

#### `shipment.updated` — Обновление реализации

**Routing key**: `shipment.updated`  
Формат аналогичен `shipment.created`.

#### `shipment.deleted` — Удаление реализации

**Routing key**: `shipment.deleted`

```json
{
  "event": "shipment.deleted",
  "message_id": "msg-shipment-del-001",
  "uuid": "uuid-реализации"
}
```

---

### 6.9 Балансы контрагентов

#### `balance.updated` — Обновление баланса

**Routing key**: `balance.updated`  
**Очередь**: `erp_in.balance`  
**Действие**: Обновляет баланс по каждому контрагенту партнёра (по ИНН). Включает детализацию просрочки.

```json
{
  "event": "balance.updated",
  "message_id": "msg-balance-001",
  "partner_uuid": "erp-id-партнёра",
  "updated_at": "2026-03-15T15:00:00+03:00",
  "contractors": [
    {
      "contractor_inn": "7710140679",
      "contractor_uuid": "uuid-контрагента-в-1с",
      "current_balance": -125000.00,
      "overdue_debt": 50000.00,
      "overdue_details": [
        {
          "shipment_uuid": "uuid-реализации",
          "amount": 30000.00,
          "due_date": "2026-02-15"
        },
        {
          "shipment_uuid": "uuid-реализации-2",
          "amount": 20000.00,
          "due_date": "2026-03-01"
        }
      ]
    }
  ]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `partner_uuid` | `string` | ✅ | ERP ID партнёра (`erp_id` пользователя на сайте) |
| `updated_at` | `string (ISO 8601)` | нет | Дата/время обновления баланса |
| `contractors` | `array` | ✅ | Массив контрагентов партнёра |
| `contractors[].contractor_inn` | `string` | ✅ | ИНН контрагента (ключ сопоставления) |
| `contractors[].contractor_uuid` | `string` | нет | UUID контрагента в 1С |
| `contractors[].current_balance` | `number` | нет | Текущий баланс (отрицательное = задолженность) |
| `contractors[].overdue_debt` | `number` | нет | Сумма просроченной задолженности |
| `contractors[].overdue_details` | `array` | нет | Детализация просрочки по реализациям |
| `contractors[].overdue_details[].shipment_uuid` | `string` | ✅* | UUID реализации |
| `contractors[].overdue_details[].amount` | `number` | нет | Сумма просрочки |
| `contractors[].overdue_details[].due_date` | `string` | нет | Дата оплаты |

---

### 6.10 Сегменты номенклатуры (US-11)

#### `product_segment.created` — Создание/обновление сегмента товаров

**Routing key**: `product_segment.created`  
**Очередь**: `erp_in.segments`  
**Действие**: Создаёт или обновляет сегмент и привязывает к нему товары. Идемпотентно.

```json
{
  "event": "product_segment.created",
  "message_id": "msg-pseg-001",
  "uuid": "uuid-сегмента",
  "name": "Премиум товары",
  "product_uuids": ["uuid-товара-1", "uuid-товара-2", "uuid-товара-3"]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID сегмента |
| `name` | `string` | ✅ | Название сегмента |
| `product_uuids` | `string[]` | нет | Массив UUID товаров, входящих в сегмент (полная замена) |

#### `product_segment.updated` — Обновление сегмента  
Формат аналогичен `product_segment.created`.

#### `product_segment.deleted` — Удаление сегмента

```json
{
  "event": "product_segment.deleted",
  "message_id": "msg-pseg-del-001",
  "uuid": "uuid-сегмента"
}
```

---

### 6.11 Сегменты партнёров (US-12)

#### `partner_segment.created` — Создание/обновление сегмента партнёров

**Routing key**: `partner_segment.created`  
**Очередь**: `erp_in.segments`  
**Действие**: Создаёт или обновляет сегмент и привязывает к нему партнёров. Идемпотентно.

```json
{
  "event": "partner_segment.created",
  "message_id": "msg-partseg-001",
  "uuid": "uuid-сегмента",
  "name": "VIP партнёры",
  "partner_uuids": ["erp-id-партнёра-1", "erp-id-партнёра-2"]
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID сегмента |
| `name` | `string` | ✅ | Название сегмента |
| `partner_uuids` | `string[]` | нет | Массив ERP ID партнёров (полная замена) |

#### `partner_segment.updated` — Обновление сегмента  
Формат аналогичен `partner_segment.created`.

#### `partner_segment.deleted` — Удаление сегмента

```json
{
  "event": "partner_segment.deleted",
  "message_id": "msg-partseg-del-001",
  "uuid": "uuid-сегмента"
}
```

---

### 6.12 Каталог — Категории (US-13)

#### `category.created` — Создание/обновление категории

**Routing key**: `category.created`  
**Очередь**: `erp_in.catalog`  
**Действие**: Создаёт или обновляет категорию (вид номенклатуры). Поддерживает иерархию через `parent_uuid`. Идемпотентно.

```json
{
  "event": "category.created",
  "message_id": "msg-cat-001",
  "uuid": "uuid-категории-из-1с",
  "name": "Электроника",
  "parent_uuid": "uuid-родительской-категории",
  "is_group": true
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID категории в 1С |
| `name` | `string` | ✅ | Название категории |
| `parent_uuid` | `string` | нет | UUID родительской категории (для построения дерева). `null` = корневая |
| `is_group` | `boolean` | нет | Является ли категория группой (по умолчанию `false`) |

> ⚠️ **Важно**: Родительская категория должна быть создана **до** дочерней. Если `parent_uuid` указан, но родительская категория ещё не создана на сайте — связь не будет установлена.

#### `category.updated` — Обновление категории  
Формат аналогичен `category.created`.

---

### 6.13 Каталог — Товары (US-13)

#### `product.created` — Создание/обновление товара

**Routing key**: `product.created`  
**Очередь**: `erp_in.catalog`  
**Действие**: Создаёт или обновляет товар с привязкой к категории, бренду, модели. Синхронизирует штрих-коды и атрибуты. Идемпотентно.

> ⚠️ **Важно**: Цена товара (`base_price`) **не перезаписывается** через `product.created`. Для обновления цены используйте событие `price.updated`.

```json
{
  "event": "product.created",
  "message_id": "msg-prod-001",
  "uuid": "uuid-товара-в-1с",
  "name": "Смартфон XYZ Pro",
  "code": "СМ-001",
  "sku": "ARTXYZ001",
  "description": "Описание товара",
  "category_uuid": "uuid-категории",
  "brand": "Samsung",
  "barcodes": ["4607001234567", "4607009876543"],
  "model": {
    "uuid": "uuid-модели-в-1с",
    "name": "XYZ Pro"
  },
  "attributes": {
    "Цвет": "Чёрный",
    "Размер": "128GB",
    "Материал": "Алюминий"
  }
}
```

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `uuid` | `string` | ✅ | UUID товара в 1С (будет записан как `external_id` на сайте) |
| `name` | `string` | ✅ | Наименование товара |
| `code` | `string` | нет | Код товара в 1С |
| `sku` | `string` | нет | Артикул |
| `description` | `string` | нет | Описание товара |
| `category_uuid` | `string` | нет | UUID категории (создаётся через `category.created`) |
| `brand` | `string` | нет | Название бренда (будет найден или создан автоматически) |
| `barcodes` | `string[]` | нет | Массив штрих-кодов (полная замена при обновлении) |
| `model.uuid` | `string` | нет | UUID модели товара в 1С |
| `model.name` | `string` | нет | Название модели |
| `attributes` | `object` | нет | Пары «Название атрибута: Значение» в свободном формате |

#### `product.updated` — Обновление товара  
Формат аналогичен `product.created`.

---

## 7. Детальное описание событий (Сайт → 1С)

Эти сообщения публикуются **сайтом** в exchange `site.events`. **1С должна подписаться на соответствующие очереди.**

### 7.1 Новый заказ — `order.created`

**Очередь для чтения**: `erp_out.orders`

```json
{
  "event": "order.created",
  "uuid": "uuid-заказа",
  "number": "ORD-2026-0042",
  "date": "2026-03-15T14:30:00+03:00",
  "status": "pending",
  "type": "order",
  "partner_uuid": "erp-id-партнёра",
  "warehouse_uuids": ["uuid-склада-1", "uuid-склада-2"],
  "timestamp": "2026-03-15T14:30:01+03:00",
  "contractor": {
    "country": "RU",
    "name": "ООО Ромашка",
    "legal_name": "Общество с ограниченной ответственностью Ромашка",
    "tax_id": "7710140679",
    "registration_number": "1027700132195",
    "tax_code": "771001001",
    "okpo_code": "01234567",
    "legal_address": "г. Москва, ул. Примерная, д. 1",
    "actual_address": "г. Москва, ул. Реальная, д. 5",
    "phone": "+74951234567",
    "email": "romashka@example.com",
    "latitude": 55.7558,
    "longitude": 37.6173,
    "bank_accounts": [
      {
        "bank_name": "ПАО Сбербанк",
        "bank_bik": "044525225",
        "correspondent_account": "30101810400000000225",
        "account_number": "40702810123456789012",
        "is_primary": true
      }
    ]
  },
  "delivery_address": "г. Москва, ул. Доставки, д. 10",
  "currency_code": "RUB",
  "exchange_rate": 1.0,
  "rate_coefficient": 1.0,
  "items": [
    {
      "product_uuid": "uuid-товара-1",
      "quantity": 5,
      "price": 1200.00
    },
    {
      "product_uuid": "uuid-товара-2",
      "quantity": 2,
      "price": 3500.00
    }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `event` | `string` | Всегда `order.created` |
| `uuid` | `string` | UUID заказа |
| `number` | `string` | Номер заказа (формат `ORD-YYYY-XXXX`) |
| `date` | `string (ISO 8601)` | Дата создания |
| `status` | `string` | Текущий статус (при создании: `pending`) |
| `type` | `string` | Тип: `order` (заказ) или `preorder` (предзаказ) |
| `partner_uuid` | `string` | ERP ID партнёра |
| `warehouse_uuids` | `string[]` | UUID складов, привязанных к региону пользователя. Для `order` — основные склады, для `preorder` — склады предзаказа |
| `contractor` | `object` | Данные контрагента (юридического лица). **Сопоставление в 1С по `tax_id` (ИНН)** |
| `delivery_address` | `string` | Адрес доставки |
| `currency_code` | `string` | Код валюты заказа |
| `exchange_rate` | `number` | Курс валюты |
| `rate_coefficient` | `number` | Поправочный коэффициент курса |
| `items` | `array` | Позиции заказа |
| `items[].product_uuid` | `string` | UUID товара (`external_id`) |
| `items[].quantity` | `integer` | Количество |
| `items[].price` | `number` | Цена за единицу |

---

### 7.2 Новый возврат — `return.created`

**Очередь для чтения**: `erp_out.returns`

```json
{
  "event": "return.created",
  "uuid": "uuid-возврата",
  "order_uuid": "uuid-исходного-заказа",
  "partner_uuid": "erp-id-партнёра",
  "timestamp": "2026-03-15T16:00:00+03:00",
  "items": [
    {
      "product_uuid": "uuid-товара",
      "quantity": 2,
      "reason": "defective"
    }
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `event` | `string` | Всегда `return.created` |
| `uuid` | `string` | UUID возврата |
| `order_uuid` | `string` | UUID заказа, к которому относится возврат |
| `partner_uuid` | `string` | ERP ID партнёра |
| `timestamp` | `string (ISO 8601)` | Время создания |
| `items` | `array` | Позиции возврата |
| `items[].product_uuid` | `string` | UUID товара |
| `items[].quantity` | `integer` | Количество к возврату |
| `items[].reason` | `string` | Причина: `defective`, `wrong_item`, `changed_mind`, `damaged_in_transit`, `wrong_size`, `other` |

---

### 7.3 Новый партнёр — `partner.created`

**Очередь для чтения**: `erp_out.partners`

Публикуется **только при активации** пользователя администратором (статус → `active`).

```json
{
  "event": "partner.created",
  "timestamp": "2026-03-15T12:00:00+03:00",
  "message_id": "msg-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "uuid": "erp-id-или-id-пользователя",
  "login": "user@example.com",
  "name": "Иванов Иван Иванович",
  "phone": "+79001234567",
  "email": "user@example.com"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `event` | `string` | Всегда `partner.created` |
| `message_id` | `string` | Уникальный ID сообщения |
| `uuid` | `string` | ERP ID партнёра (или ID пользователя, если erp_id ещё не назначен) |
| `login` | `string` | Email / логин |
| `name` | `string` | ФИО |
| `phone` | `string` | Телефон |
| `email` | `string` | Email |

---

## 8. Dead Letter Queues (DLQ)

Для каждой входящей очереди существует DLQ. Если сообщение не удалось обработать после всех попыток — оно перемещается в DLQ для ручного анализа.

| Основная очередь | DLQ | Макс. попыток | Пауза между попытками |
|---|---|---|---|
| `erp_in.partners` | `erp_dlq.partners` | 5 | 30 сек |
| `erp_in.prices` | `erp_dlq.prices` | 3 | 10 сек |
| `erp_in.stock` | `erp_dlq.stock` | 3 | 5 сек |
| `erp_in.orders` | `erp_dlq.orders` | 5 | 15 сек |
| `erp_in.returns` | `erp_dlq.returns` | 3 | 15 сек |
| `erp_in.documents` | `erp_dlq.documents` | 3 | 15 сек |
| `erp_in.balance` | `erp_dlq.balance` | 3 | 30 сек |
| `erp_in.segments` | `erp_dlq.segments` | 3 | 15 сек |
| `erp_in.catalog` | `erp_dlq.catalog` | 3 | 15 сек |

---

## 9. Идемпотентность

Сайт реализует защиту от повторной обработки сообщений. Механизм основан на поле `message_id`:

1. При получении сообщения сайт проверяет, был ли `message_id` уже обработан (таблица `erp_processed_messages`)
2. Если `message_id` уже есть — сообщение игнорируется
3. После успешной обработки `message_id` записывается в таблицу

**Рекомендации для 1С:**
- Всегда передавайте уникальный `message_id` в каждом сообщении
- Используйте формат UUID для `message_id`
- При повторной отправке того же события используйте **тот же** `message_id` — сообщение не будет обработано повторно
- При отправке нового события (даже для того же объекта) используйте **новый** `message_id`

---

## 10. Тестирование и отладка

### 10.1 Примеры curl для публикации сообщений из 1С (HTTP API)

RabbitMQ предоставляет HTTP Management API для публикации и чтения сообщений. Это самый простой способ отправить сообщение из 1С без AMQP-клиента.

**Параметры подключения:**

| Параметр | Значение (dev-сервер) |
|---|---|
| Хост | `10.2.2.100` |
| Порт HTTP API | `15672` |
| Пользователь (Management API) | `pecado_admin` |
| Пароль (Management API) | `SecurePass2024!` |
| Пользователь (AMQP, порт 5672) | `pecado_app` |
| Пароль (AMQP) | `PecadoApp2024!` |
| Virtual Host | `/` (в URL кодируется как `%2F`) |

> ℹ️ Для HTTP Management API (curl-примеры ниже) используйте учётную запись `pecado_admin`. Для AMQP-подключения (если 1С использует нативный AMQP-клиент) — `pecado_app`.

---

#### Публикация сообщения — обновление остатков (stock.updated)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "stock.updated",
    "payload": "{\"event\":\"stock.updated\",\"message_id\":\"msg-test-stock-001\",\"product_uuid\":\"a1b2c3d4-e5f6-7890-abcd-ef1234567890\",\"warehouse_uuid\":\"w1a2b3c4-d5e6-7890-abcd-ef1234567890\",\"quantity\":42}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — обновление цены (price.updated)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "price.updated",
    "payload": "{\"event\":\"price.updated\",\"message_id\":\"msg-test-price-001\",\"product_uuid\":\"a1b2c3d4-e5f6-7890-abcd-ef1234567890\",\"price\":12500.50}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — обновление статуса заказа (order.updated)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "order.updated",
    "payload": "{\"event\":\"order.updated\",\"message_id\":\"msg-test-order-001\",\"uuid\":\"uuid-заказа-на-сайте\",\"status\":\"confirmed\"}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — обновление баланса (balance.updated)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "balance.updated",
    "payload": "{\"event\":\"balance.updated\",\"message_id\":\"msg-test-balance-001\",\"partner_uuid\":\"erp-id-партнёра\",\"updated_at\":\"2026-03-15T15:00:00+03:00\",\"contractors\":[{\"contractor_inn\":\"7710140679\",\"contractor_uuid\":\"uuid-контрагента\",\"current_balance\":-125000.00,\"overdue_debt\":50000.00,\"overdue_details\":[{\"shipment_uuid\":\"uuid-реализации\",\"amount\":30000,\"due_date\":\"2026-02-15\"}]}]}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — создание реализации (shipment.created)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "shipment.created",
    "payload": "{\"event\":\"shipment.created\",\"message_id\":\"msg-test-ship-001\",\"uuid\":\"uuid-реализации\",\"contractor_inn\":\"7710140679\",\"date\":\"2026-03-15\",\"status\":\"new\",\"currency_code\":\"RUB\",\"items\":[{\"product_uuid\":\"uuid-товара\",\"quantity\":10,\"price\":500.00,\"total\":4500.00,\"auto_discount_percent\":5,\"manual_discount_percent\":5,\"vat_rate\":20}]}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — создание категории (category.created)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "category.created",
    "payload": "{\"event\":\"category.created\",\"message_id\":\"msg-test-cat-001\",\"uuid\":\"uuid-категории\",\"name\":\"Электроника\",\"parent_uuid\":null,\"is_group\":true}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

#### Публикация сообщения — создание товара (product.created)

```bash
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/exchanges/%2F/erp.events/publish" \
  -d '{
    "routing_key": "product.created",
    "payload": "{\"event\":\"product.created\",\"message_id\":\"msg-test-prod-001\",\"uuid\":\"uuid-товара\",\"name\":\"Тестовый товар\",\"code\":\"ТСТ-001\",\"sku\":\"ART001\",\"category_uuid\":\"uuid-категории\",\"brand\":\"TestBrand\",\"barcodes\":[\"4607001234567\"],\"attributes\":{\"Цвет\":\"Красный\"}}",
    "payload_encoding": "string",
    "properties": {
      "content_type": "application/json",
      "delivery_mode": 2
    }
  }'
```

---

### 10.2 Чтение сообщений из очередей (Сайт → 1С) через curl

1С может забирать сообщения из исходящих очередей через HTTP API:

```bash
# Прочитать сообщение из очереди заказов (с подтверждением — удаляет из очереди)
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/queues/%2F/erp_out.orders/get" \
  -d '{
    "count": 1,
    "ackmode": "ack_requeue_false",
    "encoding": "auto"
  }'

# Прочитать сообщение БЕЗ удаления (peek — просмотр)
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/queues/%2F/erp_out.orders/get" \
  -d '{
    "count": 1,
    "ackmode": "ack_requeue_true",
    "encoding": "auto"
  }'

# Чтение из очереди возвратов
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/queues/%2F/erp_out.returns/get" \
  -d '{
    "count": 1,
    "ackmode": "ack_requeue_false",
    "encoding": "auto"
  }'

# Чтение из очереди партнёров
curl -u pecado_admin:SecurePass2024! \
  -H "Content-Type: application/json" \
  -X POST \
  "http://10.2.2.100:15672/api/queues/%2F/erp_out.partners/get" \
  -d '{
    "count": 1,
    "ackmode": "ack_requeue_false",
    "encoding": "auto"
  }'
```

> **`ackmode` варианты:**
> - `ack_requeue_false` — прочитать и **удалить** из очереди (стандартный режим работы)
> - `ack_requeue_true` — прочитать и **вернуть** обратно в очередь (для отладки)

---

### 10.3 Мониторинг очередей через curl

```bash
# Получить список всех очередей с количеством сообщений
curl -u pecado_admin:SecurePass2024! \
  "http://localhost:15672/api/queues/%2F" | python3 -m json.tool

# Получить информацию о конкретной очереди
curl -u pecado_admin:SecurePass2024! \
  "http://localhost:15672/api/queues/%2F/erp_in.stock"

# Проверить, что exchange существует
curl -u pecado_admin:SecurePass2024! \
  "http://localhost:15672/api/exchanges/%2F/erp.events"
```

---

### 10.4 Пример ответа при успешной публикации

При успешной публикации через HTTP API, ответ выглядит так:

```json
{
  "routed": true
}
```

- `"routed": true` — сообщение доставлено в хотя бы одну очередь
- `"routed": false` — ни одна очередь не совпала с routing key (проверьте routing key)

---

### 10.5 Публикация через rabbitmqadmin (для Docker)

```bash
# Пример: обновление остатка
rabbitmqadmin publish exchange=erp.events routing_key="stock.updated" \
  payload='{"event":"stock.updated","product_uuid":"test-uuid","warehouse_uuid":"test-wh-uuid","quantity":100,"message_id":"test-msg-001"}'

# Пример: обновление статуса заказа
rabbitmqadmin publish exchange=erp.events routing_key="order.updated" \
  payload='{"event":"order.updated","uuid":"order-uuid","status":"confirmed","message_id":"test-msg-002"}'

# Пример: обновление баланса
rabbitmqadmin publish exchange=erp.events routing_key="balance.updated" \
  payload='{"event":"balance.updated","partner_uuid":"partner-erp-id","contractors":[{"contractor_inn":"7710140679","current_balance":-50000,"overdue_debt":10000}],"message_id":"test-msg-003"}'
```

### Проверка очередей через rabbitmqadmin

```bash
# Список очередей с количеством сообщений
rabbitmqadmin list queues name messages

# Получить сообщение из очереди без извлечения (peek)
rabbitmqadmin get queue=erp_out.orders count=1 ackmode=ack_requeue_true
```

---

## Сводная таблица всех событий

### 1С → Сайт (exchange: `erp.events`)

| Routing Key | Очередь | Описание |
|---|---|---|
| `partner.deleted` | `erp_in.partners` | Блокировка партнёра |
| `price.updated` | `erp_in.prices` | Обновление цены |
| `discount.created` | `erp_in.prices` | Создание скидки |
| `discount.updated` | `erp_in.prices` | Обновление скидки |
| `discount.deleted` | `erp_in.prices` | Удаление скидки |
| `exchange_rate.updated` | `erp_in.prices` | Обновление курса |
| `stock.updated` | `erp_in.stock` | Обновление остатков |
| `order.updated` | `erp_in.orders` | Обновление заказа |
| `order.deleted` | `erp_in.orders` | Удаление заказа |
| `return.updated` | `erp_in.returns` | Обновление возврата |
| `return.deleted` | `erp_in.returns` | Удаление возврата |
| `shipment.created` | `erp_in.documents` | Создание реализации |
| `shipment.updated` | `erp_in.documents` | Обновление реализации |
| `shipment.deleted` | `erp_in.documents` | Удаление реализации |
| `balance.updated` | `erp_in.balance` | Обновление баланса |
| `product_segment.created` | `erp_in.segments` | Создание сегмента товаров |
| `product_segment.updated` | `erp_in.segments` | Обновление сегмента товаров |
| `product_segment.deleted` | `erp_in.segments` | Удаление сегмента товаров |
| `partner_segment.created` | `erp_in.segments` | Создание сегмента партнёров |
| `partner_segment.updated` | `erp_in.segments` | Обновление сегмента партнёров |
| `partner_segment.deleted` | `erp_in.segments` | Удаление сегмента партнёров |
| `category.created` | `erp_in.catalog` | Создание категории |
| `category.updated` | `erp_in.catalog` | Обновление категории |
| `product.created` | `erp_in.catalog` | Создание товара |
| `product.updated` | `erp_in.catalog` | Обновление товара |

### Сайт → 1С (exchange: `site.events`)

| Routing Key | Очередь | Описание |
|---|---|---|
| `order.created` | `erp_out.orders` | Новый заказ/предзаказ |
| `return.created` | `erp_out.returns` | Новый запрос на возврат |
| `partner.created` | `erp_out.partners` | Активация партнёра |
