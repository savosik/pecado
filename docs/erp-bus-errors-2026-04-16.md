# Анализ ошибок ERP-шины — 2026-04-16

**Источник:** `erp_bus_messages` на dev.pecado.ru  
**Всего сообщений:** 45 169 | **Успешных:** 31 989 (70.8%) | **Провальных:** 13 180 (29.2%)

---

## Сводная таблица по типам ошибок

| # | Событие | Ошибка | Кол-во | Сторона |
|---|---------|--------|-------:|---------|
| 1 | `order.created` | Неверный формат даты (`/date` не date-time ISO 8601) | **7 903** | 1С |
| 2 | `order.created` | Дубль номера заказа (нет upsert) | **1 975** | Сайт |
| 3 | `partner.created` | Пароль длиннее 8 символов (`maxLength: 8` в схеме) | **1 620** | Сайт (схема) |
| 4 | `product.created` | Дублирующийся INSERT без проверки | **1 315** | Сайт |
| 5 | `order.created` | `country` не может быть null | **200** | Оба |
| 6 | `order.created` | Неизвестный статус заказа (не в enum) | **23** | 1С |
| 7 | `partner.created` | Невалидный email | **46** | 1С |
| 8 | `product.created` | Пустой SKU | **45** | 1С |
| 9 | `contractor.created` | Пустые `tax_id`, `legal_name`, `account_number` | **16** | 1С |
| 10 | `shipment.created` | Отрицательный `discount_percent` (схема требует >= 0) | **15** | Сайт (схема) |
| 11 | `product.created` | Deadlock при конкурентной записи | **14** | Сайт |
| 12 | `order.created` | Отрицательный `discount_percent` в items | **3** | Сайт (схема) |
| 13 | `shipment.created` | Пустой массив `items` | **1** | 1С |

---

## Детали по стороне ответственности

### Исправить на стороне 1С (~8 350 случаев)

#### 1. Формат даты в `order.created` — 7 903 случая (самая массовая ошибка)

1С передаёт дату не в ISO 8601 datetime формате.

```
Приходит:  "2026-04-16"
Должно:    "2026-04-16T00:00:00+03:00"
```

Исправить: всегда передавать дату со временем и timezone.

#### 2. Невалидный email в `partner.created` — 46 случаев

Email не проходит format-проверку: пустые строки, пробелы, спецсимволы.

Исправить: валидировать/санировать email на стороне 1С перед отправкой.

#### 3. Пустой SKU в `product.created` — 45 случаев

Товары с пустым артикулом (`/sku: Minimum string length is 1, found 0`).

Исправить: не выгружать товары без SKU или заполнять артикул перед отправкой.

#### 4. Пустые обязательные поля в `contractor.created` — 16 случаев

- `/tax_id` — пустая строка (13 случаев)
- `/tax_id` + `/legal_name` — оба пустые (3 случая)
- `/bank_accounts/0/account_number` — пустой (2 случая)
- `/legal_name` — пустая строка (1 случай)

Исправить: валидировать перед отправкой, не выгружать контрагентов без ИНН и наименования.

#### 5. Неизвестный статус заказа в `order.created` — 23 случая

1С передаёт статус, которого нет в допустимом enum схемы.

Исправить: согласовать список статусов с командой сайта, обновить JSON Schema.

#### 6. Пустой массив `items` в `shipment.created` — 1 случай

Отгрузка без позиций (`/items: Array should have at least 1 items, 0 found`).

Исправить: не отправлять отгрузки без товарных позиций.

---

### Исправить на стороне сайта (~4 800 случаев)

#### 1. Дублирующийся номер заказа в `order.created` — 1 975 случаев

Обработчик делает `INSERT`, а не `updateOrCreate`. При повторной доставке сообщения (RabbitMQ retry) падает с:

```
SQLSTATE[23000]: Duplicate entry '30??-000054' for key 'orders.orders_number_unique'
```

Исправить: заменить `Order::create([...])` на `Order::updateOrCreate(['number' => ...], [...])` в обработчике `order.created`.

#### 2. Дублирующийся INSERT в `product.created` — 1 315 случаев

При повторной выгрузке продукта падает на дубле в таблицах:
- `product_models` (по `external_id`)
- `brands` (по `slug`)
- `attribute_values` (по `attribute_id + value`)
- `attribute_category` (по `attribute_id + category_id`)

Исправить: использовать `updateOrCreate` / `insertOrIgnore` для всех связанных сущностей.

#### 3. Ограничение `maxLength: 8` для пароля в `partner.created` — 1 620 случаев

97% всех ошибок `partner.created` — пароли длиной 9-10 символов. Ограничение 8 символов устаревшее.

```
/password: Maximum string length is 8, found 10  →  1 230 случаев
/password: Maximum string length is 8, found 9   →    390 случаев
```

Исправить: увеличить `maxLength` в JSON Schema `partner.created` до 32.

#### 4. Отрицательный `discount_percent` в схемах — 18 случаев

Схемы `order.created` (items) и `shipment.created` требуют `minimum: 0`, а 1С передаёт отрицательные скидки. Аналогичная проблема уже исправлялась для `manual_discount_percent` (коммиты v12.7.3, v12.7.4) — нужно применить к `discount_percent`.

Исправить: убрать `minimum: 0` для `discount_percent` в JSON Schema обоих событий.

#### 5. Deadlock при параллельной обработке `product.created` — 14 случаев

Race condition при конкурентном обновлении `attribute_values`:

```
SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction
```

Исправить: добавить retry-логику при `SQLSTATE[40001]` или сериализовать обработку продуктов (отдельная очередь с `--tries=3 --backoff=5`).

---

### Двустороннее: `country` в `order.created` — 200 случаев

1С не передаёт `country` компании покупателя, а в таблице `companies` колонка `NOT NULL`:

```
SQLSTATE[23000]: Column 'country' cannot be null
```

- **1С:** добавить поле `country` в payload компании.
- **Сайт:** сделать поле `nullable` или добавить дефолт `'RU'` — как временная мера, чтобы заказы не падали до исправления на стороне 1С.

---

## Приоритет исправлений

| Приоритет | Задача | Сторона | Эффект |
|-----------|--------|---------|-------:|
| P0 | Формат даты в `order.created` | 1С | −7 903 ошибки |
| P0 | Upsert для заказов | Сайт | −1 975 ошибок |
| P0 | Upsert для продуктов | Сайт | −1 315 ошибок |
| P1 | Убрать `maxLength: 8` для пароля | Сайт | −1 620 ошибок |
| P1 | `country` nullable + 1С добавляет поле | Оба | −200 ошибок |
| P2 | Убрать `minimum: 0` для `discount_percent` | Сайт | −18 ошибок |
| P2 | Email валидация, пустые поля, SKU | 1С | −107 ошибок |
| P3 | Deadlock retry для `product.created` | Сайт | −14 ошибок |
