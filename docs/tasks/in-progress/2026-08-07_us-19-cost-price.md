# US-19 Себестоимость товаров из 1С (протокол v15.13.0)

**Приоритет:** высокий
**Исполнитель:** savosik
**Создано:** 2026-08-07

## Описание

Сайт знал цену продажи, но не знал, во сколько товар обошёлся. Из-за этого невозможны
ни отчёт по прибыли и марже, ни предупреждение о продаже ниже себестоимости — в
`docs-erp/content/open-questions.md` этот пробел висел вопросом №4 с самого начала
интеграции.

Себестоимость приезжает **новым событием** `cost.updated` в существующую очередь
`erp_in.prices` (routing key `cost.*`). Новой очереди и новых воркеров нет:
`erp-prices-consumer` уже держит 12 процессов на этой очереди, а поток себестоимости
по природе и объёму тот же, что поток цен.

Отчёты по прибыли в CRM в эту задачу не входят — здесь приём, хранение и фиксация
исторического значения, на котором отчёт потом построится.

## Что сделано

**Контракт (spec-first):**

- JSON Schema — `app/Services/Erp/Schemas/cost.updated.json`. Обязательны `event`,
  `product_uuid`, `cost`; опционально `currency_code` (только `RUB`).
- AsyncAPI `15.12.0` → **`15.13.0`**: канал `erpInPrices` + операция `receiveCostUpdated`,
  сообщение `CostUpdated`, схема `CostUpdatedPayload`.
- MkDocs: новая страница `rules/cost-prices.md`, задание разработчику 1С
  `guides/cost-price-for-1c.md`, тест-кейс `1.6а` в `tests/phase-1-inbound.md`,
  changelog `[15.13.0]` с блоком «Требуется от 1С», routing keys в `infrastructure.md`.

**Данные:**

- `products.cost_price` (decimal 15,2, nullable) + `cost_price_updated_at` — текущее
  значение из 1С.
- `shipment_items.cost_price_snapshot` — снимок на момент создания строки реализации.
  Себестоимость меняется во времени, а реализация — исторический документ: без снимка
  прибыль за прошлый месяц пересчитывалась бы при каждом `cost.updated`.
  Бэкфилла нет и быть не может — истории себестоимости не существует ни на сайте,
  ни в контракте.

**Логика:**

- `HandleCostUpdated` — по образцу `HandlePriceUpdated`: `withoutGlobalScopes()` (себестоимость
  нужна и по скрытым товарам — из них состоят прошлые отгрузки), товар не найден →
  тихий `Log::info`, валюта ≠ `RUB` → `Log::warning` и отказ от записи. Пересчёт по курсу
  сознательно не делается: себестоимость поехала бы задним числом вместе с курсом.
- Регистрация в трёх точках: `ErpIncomingJob::EVENT_HANDLERS`,
  `ErpMessageValidator::SCHEMA_MAP`, `SetupRabbitMQTopology::INCOMING_QUEUES`.
- `ShipmentItem::fillSnapshotFields()` расширен снимком себестоимости. Попутно исправлено:
  метод уходил в ранний `return`, когда имя и бренд уже заполнены, и читал товар под
  `HiddenScope` — для скрытых товаров снимки не заполнялись вовсе.

**Доступ:**

- Новое право `product-costs.view` — супер-админ, закупщик (`buyer-manager`),
  руководитель отдела продаж (`sales-head`). Каталоговед намеренно не получает.
- `Product::$hidden` и `ShipmentItem::$hidden` — единственный надёжный барьер: товар
  сериализуется целиком в десятках мест. В админке поле открывается точечно
  через `makeVisible()`.
- `BiSyncGrants::CONFIDENTIAL_COLUMNS` — колонки вырезаются вьюхами `v_products`
  и `v_shipment_items`, грант выдаётся на вьюху вместо таблицы. Иначе себестоимость
  утекла бы рядовым менеджерам мимо права через аналитический MCP.

**Интерфейс:** поле «Себестоимость» (read-only) во вкладке «Цена и статусы» карточки
товара, строка в `Show.jsx`, колонка в списке товаров — всё под `can_view_cost`.
В правилах валидации `store`/`update` поля нет: значение пишет только 1С.

## Тесты

| Набор | Кейсов |
|---|---|
| `HandleCostUpdatedTest` | 10 |
| `ErpIncomingJobTest` (cost.*) | 4 |
| `CostTopologyIntegrationTest` | 3 (на живом RabbitMQ) |
| `ShipmentCostSnapshotTest` | 5 |
| `ProductCostVisibilityTest` | 10 |

## Осталось

- [ ] 1С начинает присылать `cost.updated` — задание передано
      (`docs-erp/content/guides/cost-price-for-1c.md`)
- [ ] Первоначальная выгрузка себестоимости по всей активной номенклатуре
- [ ] После деплоя на серверах: `php artisan rabbitmq:setup` (биндинг `cost.*`)
      и `php artisan bi:sync-grants` (пересборка вьюх BI)
- [ ] Прибыль и маржа в `/crm/analytics` — отдельной задачей
