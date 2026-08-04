# Фаза 0: Подготовка инфраструктуры

!!! important "Перед началом тестирования"
    Необходимо убедиться, что инфраструктура работает корректно.

## 0.1 Проверка RabbitMQ

| # | Проверка | Действие | Ожидаемый результат |
|---|---|---|---|
| 0.1.1 | RabbitMQ доступен | Management UI | Интерфейс доступен |
| 0.1.2 | Exchange `erp.events` | UI → Exchanges | `topic`, `durable` |
| 0.1.3 | Exchange `site.events` | UI → Exchanges | `topic`, `durable` |
| 0.1.4 | Входящие очереди | UI → Queues | `erp_in.*` (включая `erp_in.promotions`, v12.11) |
| 0.1.5 | Исходящие очереди | UI → Queues | `erp_out.partners`, `erp_out.orders`, `erp_out.returns`, `erp_out.contractors` (v13.2) |
| 0.1.6 | DLQ очереди | UI → Queues | `erp_dlq.*` |
| 0.1.7 | Внешние остатки (ESB) | UI → Queues | `external.remains_for_erp` + exchange `external.remains` (fanout, v12.14). Очередь `external.remains_for_website` удалена в v15.2 — её быть **не должно** |
| 0.1.8 | Shovel `moscow-remains` | UI → Admin → Shovel Status | Состояние `running`, тянет из ESB (v12.14) |
| 0.1.8а | Заказы из чужой 1С (ESB Andrey) | UI → Queues | `external.orders_from_andrey_for_erp` + exchange `external.orders_from_andrey` (fanout). Очередь-зеркало `external.orders_from_andrey_for_website` удалена в v15.9.1 — её быть **не должно** |
| 0.1.9 | Пользователи и права | `rabbitmqctl list_users` / `list_user_permissions erp_1c` | `pecado_admin`, `pecado_app`, `erp_1c`; у `erp_1c` есть configure/write/read на `external.remains_for_erp` (v12.14, fix 2026-04-25) |
| 0.1.10 | Пересоздание | `docker exec pecado-app php artisan rabbitmq:setup` | Топология (включая promotions, contractors, external.remains) создана идемпотентно |

## 0.2 Проверка воркеров

| # | Проверка | Команда | Результат |
|---|---|---|---|
| 0.2.1 | Supervisor | `docker exec pecado-worker supervisorctl status` | Все `erp-*-consumer` + `erp-promotions-consumer` в `RUNNING`. Процесса `external-remains-consumer` **больше нет** (снят в v15.2) |
| 0.2.2 | Concurrency на каталоге/ценах | `supervisorctl status erp-catalog-consumer` | `numprocs=6` для `catalog` и `prices` (v13.0) |
| 0.2.3 | Перезапуск | `docker exec pecado-worker supervisorctl restart all` | Все `RUNNING` |

## 0.3 Проверка MinIO

| # | Проверка | Действие |
|---|---|---|
| 0.3.1 | MinIO доступен | Открыть Console |
| 0.3.2 | Бакет `prices-exchange` | MinIO → Buckets |

## 0.4 Подготовка справочных данных

!!! important "На dev-сервере"
    Создать через админку минимальный набор справочных данных, которые **не приходят из 1С**.

| # | Что создать | Где | Зачем |
|---|---|---|---|
| 0.4.1 | **Регионы** (мин. 2) | Админка → Регионы | Привязка складов и пользователей |
| 0.4.2 | **Склады** (мин. 2, с UUID) | Админка → Склады | Для приёма остатков |
| 0.4.3 | Привязка складов к регионам | Админка → Регионы → Склады | Доступность товаров |
| 0.4.4 | **Статусы клиентов** | Админка → Статусы клиентов | Резолвинг `client_status` |

**Статусы клиентов:**

| Название | `external_id` |
|---|---|
| Silver | `silver` |
| Gold | `gold` |
| Diamond | `diamond` |
| Индивидуальный | `individual` |

!!! tip "Запишите UUID складов"
    Они понадобятся 1С-нику для формирования payload.

## 0.5 Внешние остатки (ESB, v12.15)

!!! warning "Отключено в v15.2 (2026-06-11)"
    Потребитель внешних остатков на стороне сайта отключён, очередь
    `external.remains_for_website` удалена. Проверки 0.5.1–0.5.2 ниже —
    исторические (актуальны только при возврате потребителя).

| # | Что проверить | Где | Ожидаемый результат |
|---|---|---|---|
| 0.5.1 | UUID склада «Тюмень Основной» | `config/erp.php` → `external_remains.tyumen_warehouse_uuid` или env `EXTERNAL_REMAINS_TYUMEN_WAREHOUSE_UUID` | Совпадает с UUID этого склада в 1С Pecado (по умолчанию `f8083799-0838-11e0-a1ea-505054503030`) |
| 0.5.2 | Consumer внешних остатков | `supervisorctl status external-remains-consumer` | ~~`RUNNING`~~ — процесс снят в v15.2 |
| 0.5.3 | Логирование шины | env `ERP_BUS_LOGGING_ENABLED=true` (опционально) | Все входящие/исходящие пишутся в `erp_bus_messages` (v12.6) |
