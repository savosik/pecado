# Инфраструктура RabbitMQ

## Архитектура

```
1С → erp.events (exchange, topic, durable) → Очереди сайта (erp_in.*)
Сайт → site.events (exchange, topic, durable) → Очереди 1С (erp_out.*)
```

## Входящие очереди (1С → Сайт)

| Очередь | Routing keys | События |
|---|---|---|
| `erp_in.partners` | `partner.*`, `contractor.*` | `partner.created`, `partner.updated`, `partner.deleted`, `contractor.created` |
| `erp_in.prices` | `price.*`, `exchange_rate.*`, `individual_prices.*` | `price.updated`, `exchange_rate.updated`, `individual_prices.ready` |
| `erp_in.stock` | `stock.*` | `stock.updated` |
| `erp_in.orders` | `order.*` | `order.created`, `order.updated`, `order.deleted` |
| `erp_in.returns` | `return.*` | `return.updated`, `return.deleted` |
| `erp_in.documents` | `shipment.*` | `shipment.created`, `shipment.updated`, `shipment.deleted` |
| `erp_in.balance` | `balance.*` | `balance.updated` |
| `erp_in.catalog` | `category.*`, `product.*` | Каталог и номенклатура |

## Исходящие очереди (Сайт → 1С)

| Очередь | Routing key | Описание |
|---|---|---|
| `erp_out.orders` | `order.created` | Новые заказы с сайта |
| `erp_out.returns` | `return.created` | Новые возвраты с сайта |
| `erp_out.partners` | `partner.created` | Активированные пользователи |

## Формат конверта

Все сообщения — **raw JSON**. Обязательные мета-поля: `event`, `message_id`.

## Идемпотентность

Все обработчики **идемпотентны**: повторная обработка по `message_id` не приводит к дублированию. Обработанные `message_id` записываются в таблицу `erp_processed_messages`.

## DLQ (Dead Letter Queue)

Для каждой входящей очереди есть DLQ с префиксом `erp_dlq.*`. Сообщения, вызвавшие ошибку обработки, перенаправляются в DLQ.

## Shovel: остатки с московского ESB (external.remains)

Часть событий об остатках приходит не напрямую из 1С:КА2 Pecado, а через **RabbitMQ Shovel**, тянущий сообщения из очереди `remains_for_moscow` на внешнем ESB (`93.125.18.73:5672`) и публикующий их в локальный **fanout-обменник** `external.remains`. Оттуда каждое сообщение копируется в две durable-очереди:

| Очередь | Потребитель |
|---|---|
| `external.remains_for_website` | Сайт Pecado |
| `external.remains_for_erp` | 1С:КА2 (ERP) |

```
ESB (93.125.18.73)                     pecado-rabbitmq
  ┌──────────────────────┐            ┌────────────────────────────────┐
  │ remains_for_moscow   │  shovel    │  external.remains (fanout)     │
  │ (TTL 3 дня)          │ ─────────► │             │                  │
  └──────────────────────┘            │             ├─► external.remains_for_website
                                      │             └─► external.remains_for_erp
                                      └────────────────────────────────┘
```

### Параметры shovel-а (dynamic, через Management API)

| Параметр | Значение |
|---|---|
| `name` | `moscow-remains` |
| `src-protocol` | `amqp091` |
| `src-queue` | `remains_for_moscow` (на ESB) |
| `dest-protocol` | `amqp091` |
| `dest-uri` | `amqp://` (локальный брокер) |
| `dest-exchange` | `external.remains` |
| `ack-mode` | `on-confirm` |
| `add-forward-headers` | `false` |
| `delete-after` | `never` |
| TTL сообщений в источнике | 3 дня |

Shovel создаётся/обновляется автоматически командой `php artisan rabbitmq:setup` при деплое (одновременно с другими exchange/queue). Конфиг — `config/erp.php → moscow_shovel`.

Плагины `rabbitmq_shovel` и `rabbitmq_shovel_management` включены в `docker/rabbitmq/enabled_plugins`.

> Креды (`MOSCOW_ESB_AMQP_URI`) хранятся в `/srv/pecado/.env` на dev-сервере. Полные значения — в `docs/DEV_SERVER_CREDENTIALS.md`.

## Сводная таблица событий

| Событие | Направление | Exchange | Очередь |
|---|---|---|---|
| `partner.created` | Сайт → 1С | `site.events` | `erp_out.partners` |
| `partner.created` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `partner.updated` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `partner.deleted` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `contractor.created` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `price.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` |
| `individual_prices.ready` | 1С → Сайт | `erp.events` | `erp_in.prices` |
| `exchange_rate.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` |
| `stock.updated` | 1С → Сайт | `erp.events` | `erp_in.stock` |
| `order.created` | Сайт → 1С | `site.events` | `erp_out.orders` |
| `order.created` | 1С → Сайт | `erp.events` | `erp_in.orders` |
| `order.updated` | 1С → Сайт | `erp.events` | `erp_in.orders` |
| `order.deleted` | 1С → Сайт | `erp.events` | `erp_in.orders` |
| `return.created` | Сайт → 1С | `site.events` | `erp_out.returns` |
| `return.updated` | 1С → Сайт | `erp.events` | `erp_in.returns` |
| `return.deleted` | 1С → Сайт | `erp.events` | `erp_in.returns` |
| `shipment.created` | 1С → Сайт | `erp.events` | `erp_in.documents` |
| `shipment.updated` | 1С → Сайт | `erp.events` | `erp_in.documents` |
| `shipment.deleted` | 1С → Сайт | `erp.events` | `erp_in.documents` |
| `balance.updated` | 1С → Сайт | `erp.events` | `erp_in.balance` |
| `category.created` | 1С → Сайт | `erp.events` | `erp_in.catalog` |
| `category.updated` | 1С → Сайт | `erp.events` | `erp_in.catalog` |
| `product.created` | 1С → Сайт | `erp.events` | `erp_in.catalog` |
| `product.updated` | 1С → Сайт | `erp.events` | `erp_in.catalog` |
