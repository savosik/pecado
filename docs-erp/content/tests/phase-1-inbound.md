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

## 1.2а — Деактивация категории (`category.updated`, v12)

**Зависимости:** Тест 1.1

🔵 **1С отправляет** `category.updated` с `is_active: false`.

> Структура payload → [JSON Schema](/docs/erp/schemas/category.created.json)

- [ ] `is_active = false` в БД
- [ ] Категория **НЕ** отображается на сайте
- [ ] Товары категории **НЕ** видны в каталоге

🔵 **1С отправляет** `category.updated` с `is_active: true`.

- [ ] `is_active = true` в БД
- [ ] Категория снова отображается на сайте

---

## 1.2б — Создание активной/неактивной категории (`category.created`, v12)

**Зависимости:** нет

🔵 **1С отправляет** `category.created` с `is_active: true`.

- [ ] Категория создана с `is_active = true`
- [ ] Отображается на сайте

🔵 **1С отправляет** `category.created` с `is_active: false`.

- [ ] Категория создана с `is_active = false`
- [ ] **НЕ** отображается на сайте

---

## 1.2в — Категория без is_active (обратная совместимость, v12)

**Зависимости:** нет

🔵 **1С отправляет** `category.created` **без** поля `is_active`.

- [ ] Категория создана с `is_active = true` (значение по умолчанию)

---

## 1.3 — Товары (`product.created`)

**Зависимости:** 1.1 (категории)

🔵 **1С отправляет** `product.created` с `category_uuid`, `brand`, `attributes` (полный набор), `description_html`, `is_marked`, габаритами и классификацией.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.created.json)

- [ ] Товар создан с `external_id`
- [ ] `category_id` указывает на правильную категорию
- [ ] Бренд создан/привязан
- [ ] Атрибуты созданы из массива `attributes[]` (v13.0: 1С обязана передавать **полный** актуальный набор)
- [ ] `hidden = false` — товар видим
- [ ] `description_html` сохранён в `products.description_html`; на карточке товара рендерится HTML-описание (v13.1.0)
- [ ] `is_marked` сохранён в `products.is_marked`; для отсутствующего поля — `false` по умолчанию (v12.9)
- [ ] Габариты и логистика (v13.3): `weight_gross`, `weight_net`, `width`, `height`, `depth`, `hs_code`, `abc_xyz`, `turnover` сохранены в одноимённые колонки `products`; отсутствие любого поля → `null`
- [ ] В админке `/admin/products/{id}/edit` появилась вкладка «Габариты и логистика» с тремя секциями (вес, габариты, классификация)

---

## 1.4 — Товар со скрытием (`hidden = true`) и регрессия HiddenScope

**Зависимости:** 1.3

🔵 **Шаг A.** 1С отправляет `product.created` с `hidden: true`.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.created.json)

- [ ] Товар создан с `hidden = true`
- [ ] Товар **НЕ** отображается в каталоге и поиске
- [ ] В админке (которая видит скрытые) товар присутствует

🔵 **Шаг B (v13.1.1).** 1С отправляет `product.updated` с `hidden: false` для этого же товара.

- [ ] Запись в БД обновлена: `hidden = false`
- [ ] Товар вернулся в каталог
- [ ] В `erp_bus_messages` сообщение со статусом `success`, **без** warning «товар не найден»

🔵 **Шаг C.** 1С отправляет для скрытого товара `price.updated` и `stock.updated`.

- [ ] Цены и остатки обновляются даже у скрытых товаров (v13.1.1: HiddenScope обходится во всех ERP-handler-ах)

---

## 1.5 — Обновление товара (`product.updated`)

**Зависимости:** 1.3

### A) Обновление имени и `is_marked`

🔵 **1С отправляет** `product.updated` с новым `name` и `is_marked: true`, **без** поля `attributes`.

> Структура payload → [JSON Schema](/docs/erp/schemas/product.updated.json)

- [ ] `name` обновлён
- [ ] `is_marked = true` в БД (v12.9)
- [ ] Состав атрибутов **не изменился** (поле не передано)
- [ ] `sku`, `code`, `category_uuid` НЕ изменились
- [ ] `description_html` НЕ изменился (поле не передано)

### B) Full-replace атрибутов (v13.0, BREAKING)

Исходное состояние товара: атрибуты `А`, `Б`, `В`.

🔵 **1С отправляет** `product.updated` с `attributes: [А]` (только А).

- [ ] У товара остался ровно один атрибут — `А`
- [ ] `Б` и `В` **удалены** из `product_attribute_values`
- [ ] Если в payload передать `attributes: []` — у товара удаляются **все** связи с атрибутами

### C) Обновление `description_html` (v13.1.0)

🔵 **1С отправляет** `product.updated` с `description_html: "<p>новое</p>"`.

- [ ] `products.description_html` перезаписан
- [ ] На карточке отображается новый HTML

🔵 **1С отправляет** `product.updated` с `description_html: null`.

- [ ] `products.description_html` очищен

🔵 **1С отправляет** `product.updated` **без** поля `description_html`.

- [ ] `products.description_html` НЕ изменился (частичное обновление)

### D) Обновление габаритов и классификации (v13.3)

🔵 **1С отправляет** `product.updated` с `weight_gross: 1.250`, `width: 0.30`, `hs_code: "8517620000"` (без остальных полей).

- [ ] `products.weight_gross`, `products.width`, `products.hs_code` обновлены
- [ ] `weight_net`, `height`, `depth`, `abc_xyz`, `turnover` НЕ изменились (частичное обновление)

🔵 **1С отправляет** `product.updated` с `abc_xyz: null`.

- [ ] `products.abc_xyz` очищен (передача `null` = очистка)

🔵 **1С отправляет** `product.updated` с `weight_gross: -5`.

- [ ] Значение отброшено и сохранено как `null` (отрицательные числа не принимаются)

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
- [ ] Может войти с паролем из payload (десятичный CRC32 от email)
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

🔵 **1С отправляет** `contractor.created` с `uuid`, `tax_id`, `bank_accounts`.

> Структура payload → [JSON Schema](/docs/erp/schemas/contractor.created.json)

- [ ] Контрагент создан, привязан к пользователю
- [ ] `Company.erp_id` = `uuid` из payload (v13.2: UUID — основной идентификатор)
- [ ] `tax_id`, `legal_name` заполнены
- [ ] 2 банковских счёта, первый `is_primary = true`
- [ ] Контрагент в ЛК → «Компании»

---

## 1.12а — Привязка `erp_id` через `contractor.updated` (v13.2)

**Зависимости:** 1.9 (партнёр), у пользователя на сайте уже есть Company с `tax_id`, но **без** `erp_id` (например, создана до v13.2)

🔵 **1С отправляет** `contractor.updated` с `uuid`, `partner_uuid`, `tax_id`.

> Структура payload → [JSON Schema](/docs/erp/schemas/contractor.updated.json)

- [ ] Сайт нашёл Company по `tax_id + user_id` (резолв через `partner_uuid`)
- [ ] `Company.erp_id` заполнен `uuid` из payload (ленивый backfill)
- [ ] Повторный `contractor.updated` с тем же `uuid` идемпотентен — обновляет по `erp_id`

🔵 **1С отправляет** `contractor.updated` с тем же `uuid`, но новым `tax_id` (менеджер исправил ИНН).

- [ ] `Company.tax_id` обновлён, `erp_id` остался прежним
- [ ] Все последующие `shipment.*` и `balance.updated` находят ту же Company по UUID

---

## 1.12б — Удаление контрагента (`contractor.deleted`, v13.2)

**Зависимости:** 1.12 или 1.12а

🔵 **1С отправляет** `contractor.deleted` с `uuid` (или `tax_id` + `partner_uuid`).

> Структура payload → [JSON Schema](/docs/erp/schemas/contractor.deleted.json)

- [ ] Company сделан soft-delete (`deleted_at` заполнен)
- [ ] Контрагент пропал из ЛК → «Компании»
- [ ] Существующие реализации/балансы остались, но контрагент в них помечен как удалённый

---

## 1.13 — Заказ от менеджера (`order.created`)

**Зависимости:** 1.3 (товар), 1.9 (партнёр)

🔵 **1С отправляет** `order.created` со статусом `confirmed`.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.created.json)

- [ ] Заказ создан с `external_id`
- [ ] Статус = `confirmed`
- [ ] Поле `type` в payload 1С отсутствует; сайт использует значение по умолчанию `"order"`
- [ ] Позиции с `base_price`, `discount_percent`, `final_price`; `discount_percent < 0` трактуется как наценка
- [ ] `delivery_address` сохранён в текстовое поле `orders.delivery_address` (v12.1)
- [ ] Заказ в ЛК

---

## 1.14 — Обновление заказа (`order.updated`)

**Зависимости:** 1.13

### A) Только статус → confirmed

> Структура payload → [JSON Schema](/docs/erp/schemas/order.updated.json)

🔵 `order.updated` с `status: "confirmed"` (без `items`).

- [ ] Статус → `confirmed`
- [ ] Запись в `order_status_histories`
- [ ] Позиции НЕ изменились

### B) Статус + позиции

🔵 `order.updated` с `items[]` (изменённая цена/кол-во).

- [ ] Позиции полностью пересозданы
- [ ] Количество и цены обновлены
- [ ] Diff зафиксирован в `order_change_logs`

### C) Обновление статуса → ready_to_ship (v12.3)

🔵 `order.updated` с `status: "ready_to_ship"`.

- [ ] Статус → `ready_to_ship`
- [ ] В интерфейсе отображается лейбл «К отгрузке»

### D) Обновление статуса → closed (v12.3)

🔵 `order.updated` с `status: "closed"`.

- [ ] Статус → `closed`
- [ ] В интерфейсе отображается лейбл «Закрыт»

### E) Обновление статуса → deleted

🔵 `order.updated` с `status: "deleted"`.

- [ ] Статус → `deleted`
- [ ] В интерфейсе отображается лейбл «Удалён»

---

## 1.15 — Удаление заказа (`order.deleted`)

**Зависимости:** создать тестовый заказ

🔵 **1С отправляет** `order.deleted`.

> Структура payload → [JSON Schema](/docs/erp/schemas/order.deleted.json)

- [ ] Заказ → `deleted`
- [ ] Не отображается в активных заказах

---

## 1.16 — Реализации (`shipment.created`)

**Зависимости:** 1.12 (контрагент), 1.13 (заказ)

🔵 **1С отправляет** `shipment.created` с **обязательным** `number`, `partner_uuid`, `contractor_uuid`, `tax_id` и `items`.

> Структура payload → [JSON Schema](/docs/erp/schemas/shipment.created.json)

- [ ] Реализация создана с `erp_number` = `number` из payload (v12.12: `number` обязателен, непустая строка)
- [ ] Привязка к контрагенту: приоритет `contractor_uuid` (Company.erp_id), fallback `tax_id + user_id` (v13.2)
- [ ] `user_id` определён по `partner_uuid` (User.erp_id)
- [ ] Позиция связана с заказом через `order_uuid`
- [ ] В ЛК отображается `erp_number` (а не технический `id`), v12.12

🔵 **Негативный кейс.** 1С отправляет `shipment.created` **без** `number` (или `number: null` / пустая строка).

- [ ] Сообщение **отклонено** валидатором (v12.12)
- [ ] Запись в `erp_bus_messages` со статусом `failed` и сообщением валидатора
- [ ] Реализация **НЕ** создана

🔵 **Регрессия security (v13.2).** 1С отправляет `shipment.created` без `partner_uuid` и без `contractor_uuid`, только с `tax_id`, который случайно совпадает с Company другого пользователя.

- [ ] Сайт **НЕ** привязывает Shipment к чужой Company по совпадению `tax_id` (поиск без `user_id` запрещён)
- [ ] Shipment сохраняется с `company_id = null` (либо вообще не сохраняется — зависит от обязательности FK; см. бизнес-правила)

---

## 1.16а — Обновление реализации (`shipment.updated`)

**Зависимости:** 1.16

🔵 **1С отправляет** `shipment.updated` для существующей реализации с обновлёнными `items` и обязательным `number`.

> Структура payload → [JSON Schema](/docs/erp/schemas/shipment.updated.json) (v12.10: отдельная схема)

- [ ] Реализация обновлена, `erp_number` синхронизирован
- [ ] `partner_uuid` + `contractor_uuid` корректно резолвят Company (v13.2)
- [ ] Сообщение **без** `number` отклоняется (v12.12)

---

## 1.17 — Баланс (`balance.updated`)

**Зависимости:** 1.9 (партнёр), 1.12 (контрагент), 1.16 (реализация)

🔵 **1С отправляет** `balance.updated` с `partner_uuid`, `contractors[].uuid`, `overdue_details`.

> Структура payload → [JSON Schema](/docs/erp/schemas/balance.updated.json)

- [ ] Баланс обновлён
- [ ] `ContractorBalance.contractor_uuid` заполнен значением `contractors[].uuid` (v13.2: ленивый backfill)
- [ ] Поиск Company идёт по UUID с приоритетом, fallback на `tax_id` (с фильтром `user_id`)
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

---

## 1.20 — Промо-флаги товаров (`promotion.created`, v12.11)

**Зависимости:** 1.3 (товары)

🔵 **1С отправляет** в `erp_in.promotions` сообщение `promotion.created` с `uuid`, `type: "new"` и массивом `items[]` из 3 товаров.

> Структура payload → [JSON Schema](/docs/erp/schemas/promotion.created.json)

- [ ] В таблице `erp_promotions` появилась запись с `uuid` и `type = new`
- [ ] В pivot `erp_promotion_product` — три привязки
- [ ] У всех трёх товаров `products.is_new = true` (агрегат через `EXISTS()`)
- [ ] В админке/каталоге у товаров отображается бейдж «Новинка»

🔵 **1С отправляет** `promotion.updated` с тем же `uuid` и **другим** составом (1 товар вместо 3).

> Структура payload → [JSON Schema](/docs/erp/schemas/promotion.updated.json)

- [ ] Состав в pivot обновлён (только один товар)
- [ ] У выбывших товаров `is_new = false`, у оставшегося — `is_new = true`

🔵 **1С отправляет** `promotion.created` с `type: "bestseller"` и `type: "liquidation"` для других UUID и наборов товаров.

- [ ] У соответствующих товаров включились `is_bestseller` и `is_liquidation`
- [ ] Один товар может быть в нескольких промо-группах одного типа одновременно

🔵 **1С отправляет** `promotion.deleted` с `uuid`.

> Структура payload → [JSON Schema](/docs/erp/schemas/promotion.deleted.json)

- [ ] Запись `erp_promotions` удалена, pivot очищен
- [ ] Флаги `is_new` / `is_bestseller` / `is_liquidation` пересчитаны (если товар не остался в другой группе того же типа — флаг сбрасывается в `false`)

---

## 1.21 — Внешние остатки склада «Тюмень Основной» (v12.15)

**Зависимости:** 1.3 (товары), 0.1.7 (очередь `external.remains_for_website`), 0.5 (UUID склада в config)

🔵 **ESB Москва публикует** в `external.remains_for_website` сообщение `product.quantity.updated` (envelope ESB) с `payload.uid` товара и массивом `remains[]`, где есть склад «Тюмень Основной» и несколько других складов.

> Структура payload → [JSON Schema](/docs/erp/schemas/external.product_quantity_updated.json)

- [ ] В `product_warehouse.quantity` для склада «Тюмень Основной» записано `max(0, quantity - reserve)`
- [ ] Остальные склады **проигнорированы** (только Тюмень Основной)
- [ ] `organization_remains[]` проигнорировано
- [ ] В `erp_processed_messages` запись с префиксом `external-remains:`
- [ ] Идемпотентность: повторное сообщение с тем же `uid` не дублирует запись

🔵 **ESB присылает** сообщение для товара с неизвестным `payload.uid` и `payload.code`.

- [ ] Сообщение тихо пропущено (warning в логах), очередь не блокируется
