# Аудит-метки 1С: `erp_created_at` / `erp_updated_at`

> **Версия:** v13.10 (2026-04-26) — расширение на `product.created` / `product.updated`. Базовая поддержка для документов введена в v13.7.
> **Аудитория:** разработчики 1С, отвечающие за выгрузку документов и номенклатуры в RabbitMQ
> **Затрагивает события:** `order.created`, `order.updated`, `shipment.created`, `shipment.updated`, `product.created`, `product.updated` (1С → Сайт)

---

## Зачем это нужно

Сайт хранит у `Order`, `Shipment` и `Product` два технических timestamp-а Laravel — `created_at` и `updated_at`. Это **момент INSERT/UPDATE на сайте**, а не момент действия в 1С. При задержках в шине (DLQ, ретраи, переоткрытие соединения) разница может составлять часы. У товаров после массовой первичной выгрузки `created_at` у тысяч записей оказывается одной и той же датой и теряет смысл «насколько новый этот товар».

Поля `erp_created_at` / `erp_updated_at` — **аудит-метки 1С**: фиксируют, **когда документ или номенклатура фактически был создан или изменён в учётной системе**. Это нужно:

- менеджерам поддержки — чтобы отвечать на «когда заказ ушёл в работу», не путаясь в задержках шины;
- юристам — для сверок и претензионной работы (момент проведения отгрузки);
- маркетингу и каталогизаторам — чтобы корректно показывать «новинки» и понимать реальный возраст карточки товара;
- разработчикам — чтобы отлавливать out-of-order события в логах.

---

## Что выгружать со стороны 1С

Для документа `ЗаказКлиента` или `РеализацияТоваровУслуг`:

| Поле payload | Реквизит документа в 1С | Формат |
|---|---|---|
| `erp_created_at` | `Документ.Дата` (если совпадает с моментом создания) **или** `МоментСоздания` из регистра версионирования объектов | ISO-8601 datetime с TZ, например `2026-04-26T10:15:32+03:00` |
| `erp_updated_at` | Дата последнего изменения из подсистемы «Версионирование объектов» / `ХранилищеИсторииДокумента` (`ДатаИзменения`) | то же самое |

Для номенклатуры (`product.created` / `product.updated`):

| Поле payload | Реквизит справочника в 1С | Формат |
|---|---|---|
| `erp_created_at` | `Номенклатура.Дата` (если есть) **или** `МоментСоздания` из регистра версионирования объектов справочника | ISO-8601 datetime с TZ |
| `erp_updated_at` | Дата последнего изменения карточки номенклатуры (регистр версионирования / `ХранилищеИсторииОбъекта`) | то же самое |

> **TZ — обязательно указывать.** Правило проекта — Europe/Moscow. Допускается `+03:00`, `Z` (UTC) или любой другой суффикс — сайт **всегда нормализует значение в Europe/Moscow** перед записью в БД. Это гарантирует, что стенограмма даты в админке (`26.04.2026 10:15`) совпадёт с тем, что менеджер видит в 1С, **независимо** от того, какой суффикс прислала ERP. Без таймзоны (naive datetime) — допустимо, но не рекомендуется: PHP применит default TZ (UTC), и значение сместится на 3 часа.

### Пример payload `order.created` (1С → Сайт)

```json
{
  "event": "order.created",
  "message_id": "msg-order-erp-2026-001",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "number": "ЗК00-00042",
  "status": "к выполнению",
  "partner_uuid": "0d3eb4f2-1e2c-4f4a-8a91-1d2e3f4a5b6c",
  "contractor": {
    "uuid": "44a4f8d6-9c12-4f3b-9b9a-22bb33cc44dd",
    "tax_id": "7710140679",
    "tax_code": "771001001",
    "name": "ООО \"Ромашка\""
  },
  "erp_created_at": "2026-04-26T10:15:32+03:00",
  "erp_updated_at": "2026-04-26T10:15:32+03:00",
  "items": [ /* ... */ ]
}
```

### Пример payload `order.updated`

```json
{
  "event": "order.updated",
  "message_id": "msg-order-upd-001",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "status": "к отгрузке",
  "erp_updated_at": "2026-04-26T14:42:09+03:00"
}
```

`erp_created_at` при апдейте можно **не передавать** — сайт уже зафиксировал её в `order.created`. Если всё-таки передадите — сайт перезапишет (это допустимо, но обычно не нужно).

### Пример payload `shipment.created`

```json
{
  "event": "shipment.created",
  "message_id": "msg-shipment-001",
  "uuid": "111f1234-1111-4f3b-9b9a-22bb33cc44dd",
  "contractor_uuid": "44a4f8d6-9c12-4f3b-9b9a-22bb33cc44dd",
  "tax_id": "7710140679",
  "number": "29УТ-003413",
  "date": "2026-04-26",
  "erp_created_at": "2026-04-26T11:05:00+03:00",
  "erp_updated_at": "2026-04-26T11:05:00+03:00",
  "status": "completed",
  "currency_code": "RUB",
  "items": [ /* ... */ ]
}
```

> **Важно.** Бизнес-поле `date` (день отгрузки) **не подменяется** аудит-метками. `date` — это календарная дата проведения реализации, как её видит менеджер в 1С (без часов и таймзоны). `erp_created_at` — это **момент действия** в учётной системе (с TZ).

### Пример payload `product.created`

```json
{
  "event": "product.created",
  "message_id": "msg-prod-erp-2026-001",
  "uuid": "abc4f8d6-9c12-4f3b-9b9a-22bb33cc44dd",
  "name": "Платье вечернее",
  "code": "0T-12345",
  "sku": "PL-0001",
  "category_uuid": "11111111-2222-3333-4444-555555555555",
  "hidden": false,
  "is_marked": false,
  "barcodes": ["4600000000001"],
  "erp_created_at": "2024-09-15T11:42:00+03:00",
  "erp_updated_at": "2026-04-26T08:11:09+03:00",
  "attributes": []
}
```

### Пример payload `product.updated`

```json
{
  "event": "product.updated",
  "message_id": "msg-prod-upd-001",
  "uuid": "abc4f8d6-9c12-4f3b-9b9a-22bb33cc44dd",
  "name": "Платье вечернее (обновлено)",
  "erp_updated_at": "2026-04-26T15:03:21+03:00"
}
```

`erp_created_at` при апдейте номенклатуры можно **не передавать** — сайт уже зафиксировал её в `product.created`.

---

## Что делает сайт при получении

1. Валидатор (`ErpMessageValidator`) проверяет `erp_created_at` / `erp_updated_at` по JSON Schema (`format: date-time`, nullable). Невалидный формат → сообщение в DLQ.
2. Обработчики (`HandleOrderCreated`, `HandleShipmentCreated`, `HandleProductCreated`, `HandleOrderUpdated`, `HandleShipmentUpdated`, `HandleProductUpdated`) пишут значение из payload в Eloquent-атрибут как есть.
3. **Кастовый аттрибут `App\Casts\ErpDatetime`** на полях `Order::erp_*`, `Shipment::erp_*` и `Product::erp_*` — единая точка нормализации TZ. При записи парсит ISO-8601 и приводит к `config('app.timezone')` (на проекте — `Europe/Moscow`), затем сохраняет в БД в формате `Y-m-d H:i:s`. **При обновлении** через handler срабатывает только если соответствующий ключ присутствует в payload (`array_key_exists`); отсутствие ключа = БД не трогается.
4. В админке (`/admin/orders/{id}`, `/admin/shipments/{id}`, `/admin/products/{id}/edit`) метки выводятся как «Создано в 1С» / «Изменено в 1С» в формате `dd.mm.yyyy HH:MM` MSK — стенограмма совпадает с тем, что менеджер видит в 1С. На индексных страницах заказов и реализаций — отдельная колонка «Создано в 1С».

---

## Чек-лист для интегратора 1С

- [ ] Реквизит «момент создания документа/номенклатуры в 1С» определён (см. таблицу выше) и доступен правилам конвертации.
- [ ] Реквизит «момент последнего изменения» определён (например, регистр сведений `ХранилищеИсторииДокумента` / `ХранилищеИсторииОбъекта`).
- [ ] Формат — строка ISO-8601 с TZ. Используйте `XMLString(<Дата>)` или собственный форматтер, гарантирующий `YYYY-MM-DDTHH:MM:SS±HH:MM`.
- [ ] Для `order.updated` / `shipment.updated` / `product.updated` достаточно передавать только `erp_updated_at`. `erp_created_at` — опционально, перезаписывает существующее значение.
- [ ] Для `*.deleted`-событий поля **не передаются** (это внутреннее действие на сайте, аудит идёт в `OrderStatusHistory`).

---

## Совместимость

- Поля **не required**. Старые правила конвертации без аудит-меток продолжают работать — сайт принимает payload без них и оставляет колонки `NULL`.
- На стороне 1С менять схему сообщений или routing keys не требуется.
- Колонки в БД сайта nullable, индексов на них нет — миграция безопасна на проде.

---

## Ссылки

- JSON Schemas: [`order.created.json`](/docs/erp/schemas/order.created.json), [`order.updated.json`](/docs/erp/schemas/order.updated.json), [`shipment.created.json`](/docs/erp/schemas/shipment.created.json), [`shipment.updated.json`](/docs/erp/schemas/shipment.updated.json), [`product.created.json`](/docs/erp/schemas/product.created.json), [`product.updated.json`](/docs/erp/schemas/product.updated.json)
- AsyncAPI: [`pecado-erp-integration.yaml`](/docs/erp/spec.yaml) — `OrderCreatedPayload`, `OrderUpdatedPayload`, `ShipmentCreatedPayload`, `ShipmentUpdatedPayload`, `ProductCreatedPayload`, `ProductUpdatedPayload`
- Бизнес-правила: [Заказы](../rules/orders.md), [Реализации](../rules/shipments.md), [Каталог (товары)](../rules/catalog.md)
- Changelog: [v13.7.0](../changelog.md#1370--2026-04-26) (документы), [v13.10.0](../changelog.md#13100--2026-04-26) (товары)
