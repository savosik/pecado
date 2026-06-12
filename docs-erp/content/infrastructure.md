# Инфраструктура RabbitMQ

## Архитектура

```
1С          → erp.events      (topic, durable) → Очереди сайта      (erp_in.*)
Сайт        → site.events     (topic, durable) → Очереди 1С         (erp_out.*)
Внешний ESB → shovel → external.remains (fanout, durable) → external.remains_for_{website,erp}
```

Брокер — `pecado-rabbitmq` (образ `rabbitmq:3-management`). Плагины `rabbitmq_shovel` и `rabbitmq_shovel_management` включены через смонтированный файл `docker/rabbitmq/enabled_plugins`. Данные Mnesia персистятся в named volume `rabbitmq-data` — topology и пользователи переживают рестарт контейнера.

## Входящие очереди (1С → Сайт)

| Очередь | Routing keys | События |
|---|---|---|
| `erp_in.partners` | `partner.*` | `partner.created`, `partner.updated`, `partner.deleted` |
| `erp_in.contractors` | `contractor.*` | `contractor.created`, `contractor.updated`, `contractor.deleted` |
| `erp_in.prices` | `price.*`, `exchange_rate.*`, `individual_prices.*` | `price.updated`, `exchange_rate.updated`, `individual_prices.ready` |
| `erp_in.stock` | `stock.*` | `stock.updated` |
| `erp_in.orders` | `order.*` | `order.created`, `order.updated`, `order.deleted` |
| `erp_in.returns` | `return.*` | `return.updated`, `return.deleted` |
| `erp_in.documents` | `shipment.*` | `shipment.created`, `shipment.updated`, `shipment.deleted` |
| `erp_in.balance` | `balance.*` | `balance.updated` |
| `erp_in.catalog` | `category.*`, `product.*` | Каталог и номенклатура |
| `erp_in.promotions` | `promotion.*` | `promotion.created`, `promotion.updated`, `promotion.deleted` |

## Исходящие очереди (Сайт → 1С)

| Очередь | Routing key | Описание |
|---|---|---|
| `erp_out.orders` | `order.created` | Новые заказы с сайта |
| `erp_out.returns` | `return.created` | Новые возвраты с сайта |
| `erp_out.partners` | `partner.created` | Активированные пользователи |
| `erp_out.contractors` | `contractor.created` | Контрагенты с заполненным `tax_id` (v13.2) |

## Формат конверта

Все сообщения — **raw JSON**. Обязательные мета-поля: `event`, `message_id`.

## Идемпотентность

Все обработчики **идемпотентны**: повторная обработка по `message_id` не приводит к дублированию. Обработанные `message_id` записываются в таблицу `erp_processed_messages`.

## DLQ (Dead Letter Queue)

Для каждой входящей очереди есть DLQ с префиксом `erp_dlq.*`. Сообщения, вызвавшие ошибку обработки, перенаправляются в DLQ.

## Пользователи и права

Пользователи создаются **автоматически** на каждом деплое (шаг `[5.2/7] Провижининг пользователей RabbitMQ` в `.github/workflows/deploy-dev.yml`). Если пользователь уже есть — обновляется только пароль и права; ручного вмешательства не требуется даже при полном recreate контейнера.

| Пользователь | Tag | Права (`configure` / `write` / `read`) | Назначение |
|---|---|---|---|
| `pecado_admin` | `administrator` | `.*` / `.*` / `.*` | Management UI, дебаг, техподдержка |
| `pecado_app` | — | `.*` / `.*` / `.*` | Laravel-приложение (AMQP + Management API) |
| `erp_1c` | — | `erp\..*\|erp_out\..*` / `erp\..*` / `erp_out\..*` | 1С:КА2 — читает `erp_out.*`, публикует в `erp.events` |

Пароли берутся из `/srv/pecado/.env` (`RABBITMQ_MANAGEMENT_*`, `RABBITMQ_USER`/`RABBITMQ_PASSWORD`, `RABBITMQ_ERP_USER`/`RABBITMQ_ERP_PASSWORD`). Дефолтный `guest` удаляется.

> Если `RABBITMQ_ERP_*` не заданы — пользователь `erp_1c` пропускается (используется на dev-сервере, но не нужен в CI/локальных средах).

## Shovel: остатки с московского ESB (external.remains)

Часть событий об остатках приходит не напрямую из 1С:КА2 Pecado, а через **RabbitMQ Shovel**, тянущий сообщения из очереди `remains_for_moscow` на внешнем ESB (`93.125.18.73:5672`) и публикующий их в локальный **fanout-обменник** `external.remains`. Оттуда сообщение копируется в durable-очередь потребителя:

| Очередь | Потребитель |
|---|---|
| `external.remains_for_erp` | 1С:КА2 (ERP) |

> **Изменено 2026-06-11 (v15.2):** очередь `external.remains_for_website` (потребитель — сайт Pecado) отключена и удалена. Сайт больше не потребляет внешние остатки. Fanout оставлен для очереди 1С и на случай возврата потребителя сайта.

```
ESB (93.125.18.73)                     pecado-rabbitmq
  ┌──────────────────────┐            ┌────────────────────────────────┐
  │ remains_for_moscow   │  shovel    │  external.remains (fanout)     │
  │ (TTL 3 дня)          │ ─────────► │             │                  │
  └──────────────────────┘            │             └─► external.remains_for_erp
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

Shovel создаётся/обновляется автоматически командой `php artisan rabbitmq:setup` при деплое (одновременно с другими exchange/queue). Конфиг — `config/erp.php → moscow_shovel`. Регистрация идёт через RabbitMQ Management API `PUT /api/parameters/shovel/%2F/moscow-remains`.

### TTL и отказоустойчивость

- Очередь-источник `remains_for_moscow` на ESB имеет TTL 3 дня. Если shovel задержится с подключением, непрочитанные сообщения удаляются автоматически.
- `ack-mode: on-confirm` гарантирует, что shovel подтверждает сообщение источнику **только после** publisher-confirm от целевого брокера. Сообщения не теряются при нетворк-блипах.
- `reconnect-delay: 5` — после разрыва shovel переподключается к ESB каждые 5 секунд.

### Потребители очередей

| Очередь | Потребитель | Статус на 2026-06-11 |
|---|---|---|
| `external.remains_for_website` | — | **Отключена и удалена (v15.2):** сайт больше не потребляет внешние остатки. Supervisor-процесс `external-remains-consumer` снят, очередь удаляется в `rabbitmq:setup`. Код `ExternalRemainsJob` оставлен для возможного возврата (см. [бизнес-правила](rules/external-remains.md)) |
| `external.remains_for_erp` | 1С:КА2 (AMQP-чтение) | Не реализован |

### TTL сообщений (policy `external-remains-ttl`)

На очереди `external.remains_for_*` применяется RabbitMQ policy (после v15.2 матчится только `external.remains_for_erp`):

| Поле | Значение |
|---|---|
| `pattern` | `^external\.remains_for_.*$` |
| `apply-to` | `queues` |
| `definition` | `{"message-ttl": 259200000}` (3 дня в мс) |
| `priority` | 0 |

Пока потребителей нет, сообщения автоматически удаляются через 3 дня после публикации в fanout — чтобы очереди не разрастались бесконечно и не забивали диск `rabbitmq-data`. Величина совпадает с TTL очереди-источника на ESB: если сообщение не прочитано за 3 дня с момента прилёта на ESB и ещё 3 дня уже в наших очередях — оно всё равно устарело по бизнесу. TTL конфигурируется через `EXTERNAL_REMAINS_TTL_MS`.

Policy регистрируется автоматически командой `php artisan rabbitmq:setup` через Management API (`PUT /api/policies/%2F/external-remains-ttl`).

> Креды (`MOSCOW_ESB_AMQP_URI`, `MOSCOW_ESB_SRC_QUEUE`) хранятся в `/srv/pecado/.env` на dev-сервере. Полные значения — в `docs/DEV_SERVER_CREDENTIALS.md`.

## Сводная таблица событий

| Событие | Направление | Exchange | Очередь |
|---|---|---|---|
| `partner.created` | Сайт → 1С | `site.events` | `erp_out.partners` |
| `partner.created` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `partner.updated` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `partner.deleted` | 1С → Сайт | `erp.events` | `erp_in.partners` |
| `contractor.created` | Сайт → 1С | `site.events` | `erp_out.contractors` |
| `contractor.created` | 1С → Сайт | `erp.events` | `erp_in.contractors` |
| `contractor.updated` | 1С → Сайт | `erp.events` | `erp_in.contractors` |
| `contractor.deleted` | 1С → Сайт | `erp.events` | `erp_in.contractors` |
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
