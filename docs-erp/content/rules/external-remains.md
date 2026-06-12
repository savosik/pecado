# Внешние остатки (московский ESB)

!!! warning "Отключено 2026-06-11 (v15.2)"
    Потребитель сайта отключён: supervisor-процесс `external-remains-consumer`
    снят, очередь `external.remains_for_website` отвязана от fanout `external.remains`
    и удалена в `rabbitmq:setup`. Сайт **больше не потребляет** внешние остатки.
    1С продолжает получать их через `external.remains_for_erp`.

    Код потребителя (`ExternalRemainsJob`, `HandleExternalProductQuantityUpdated`,
    JSON Schema, connection `rabbitmq-external-remains`, config `erp.external_remains`)
    оставлен на случай возврата. Чтобы снова включить: вернуть очередь в
    `EXTERNAL_FANOUTS` и убрать из `DELETED_QUEUES` в `SetupRabbitMQTopology`,
    вернуть supervisor-программу `external-remains-consumer`. Описание ниже —
    историческое, для справки.

Этот раздел описывает обработку событий `product.quantity.updated`, приходящих с внешнего ESB через shovel `moscow-remains` в очередь `external.remains_for_website`. Это **отдельный протокол от 1С↔Сайт** (envelope `{service, uid, event: {name, payload}, ...}`), поэтому он не описан в AsyncAPI 1С↔Сайт — только локальной JSON Schema `external.product_quantity_updated.json`.

## Источник данных

| Звено | Значение |
|---|---|
| Публикатор | `service-products` (внешний ESB, `93.125.18.73:5672`) |
| Канал доставки | RabbitMQ Shovel `moscow-remains` → fanout `external.remains` → `external.remains_for_website` |
| Частота | При каждом изменении остатков/цен любого товара в 1С московского офиса |
| Размер сообщения | ~215 КБ (полная карточка товара + остатки + цены + медиа) |

## Что берёт сайт

Сайт использует **только остатки по складу «Тюмень Основной»**. Все прочие склады (Москва, региональные, технические) из сообщения игнорируются — они не относятся к ассортименту сайта Pecado.

UUID склада задаётся в `config/erp.php`:

```php
'external_remains' => [
    'tyumen_warehouse_uuid' => env('EXTERNAL_REMAINS_TYUMEN_WAREHOUSE_UUID', 'f8083799-0838-11e0-a1ea-505054503030'),
],
```

Соответствует записи в таблице `warehouses`:

| id | name | external_id |
|---|---|---|
| 3 | Тюмень Основной | `f8083799-0838-11e0-a1ea-505054503030` |

## Алгоритм обработки

`ExternalRemainsJob` принимает сырое сообщение, проверяет идемпотентность по `envelope.uid` и передаёт в `HandleExternalProductQuantityUpdated`. Handler делает следующее:

1. **Поиск товара:**
   - сначала `Product::where('external_id', payload.uid)` (основной путь — UUID в 1С);
   - если не найдено — fallback `Product::where('code', payload.code)` (на случай, если `external_id` ещё не проставлен, но код известен);
   - если всё ещё не найдено — событие молча пропускается (`Log::info`).
2. **Поиск склада:** `Warehouse::where('external_id', config('erp.external_remains.tyumen_warehouse_uuid'))`. Если склад не зарегистрирован — пропуск с `Log::warning`.
3. **Поиск записи по складу в `payload.remains[]`:** ищем элемент с `warehouse_uid = UUID Тюмени`. Если в сообщении по Тюмени нет записи — пропуск (`Log::info`).
4. **Расчёт доступного остатка:**

    ```
    available = max(0, remains.quantity - remains.reserve)
    ```

   Поле `total` игнорируется (оно эквивалентно `quantity + expected` по данным ESB и не отражает доступный остаток для продажи).
5. **Запись в БД:** `product->warehouses()->syncWithoutDetaching([warehouse->id => ['quantity' => $available]])`. Это гарантирует, что остатки других складов в `product_warehouse` **не затираются**.

## Структура подчёркнуто интересующей части payload

```json
{
  "service": "service-products",
  "uid": "0b3a6e11-0a77-4de7-b7c8-1fe0490288ce",
  "event": {
    "name": "product.quantity.updated",
    "payload": {
      "uid": "cda07397-d5e6-11e8-8127-00155d00e605",
      "code": "0T-00012400",
      "remains": [
        {
          "warehouse_uid": "f8083799-0838-11e0-a1ea-505054503030",
          "quantity": 7,
          "reserve": 2,
          "total": 7,
          "expected": 0,
          "nds": false,
          "updated_at": "2026-04-20 16:23:45"
        }
      ]
    }
  }
}
```

Полный envelope содержит ещё массив `organization_remains[]` (разрез по юрлицам), блок `parameters[]`, `prices[]`, `media[]` и т.д. — **всё это сайт не использует**, обработка каталога и цен идёт отдельным каналом из 1С Pecado (события `product.updated`, `price.updated`).

## Идемпотентность

В таблицу `erp_processed_messages` пишется ключ `external-remains:{envelope.uid}` — префикс отделяет ESB-сообщения от 1С-сообщений (чтобы UUID не пересекались).

## Что НЕ делает сайт в ответ на это событие

- Не обновляет название, описание, параметры, медиа товара (это делают `product.updated` и `product.created` из 1С Pecado, не из ESB).
- Не обновляет цены (для них отдельный канал — `price.updated`).
- Не разносит остатки по юрлицам (`organization_remains[]` игнорируется — отдельные организации не представлены в БД).
- Не создаёт товар, если он не найден — каталог управляется 1С Pecado.

## Ограничения и известные нюансы

- **Отсутствует склад в БД** → консьюмер логирует предупреждение и удаляет сообщение. Это корректно для локальных dev-сред без полного сид-дампа warehouses.
- **TTL 3 дня** (policy `external-remains-ttl`) — если consumer не работает дольше 3 суток, сообщения удаляются. Для восстановления остатков на сайте можно запросить у 1С перевыгрузку через ESB.
- **Consumer не конкурентный** — один процесс supervisor (`numprocs=1`). Поток сообщений из ESB невысокий (каждая карточка ≤1-2 раз в сутки в среднем), и при наличии двух воркеров одна запись по одному товару может обновляться двумя разными сообщениями конкурентно — лучше избегать.
