# AsyncAPI — Спецификация интеграции 1С ↔ Сайт Pecado

## Обзор

Директория содержит **AsyncAPI 3.0** спецификацию, описывающую все асинхронные сообщения между **1С:Предприятие 8.3 (КА2)** и **Сайтом Pecado** через **RabbitMQ (AMQP)**.

## Файлы

| Файл | Описание |
|---|---|
| `pecado-erp-integration.yaml` | Основная AsyncAPI спецификация (единый файл) |
| `html/` | Сгенерированная HTML-документация (не в git) |

## Зачем это нужно

1. **Единый источник правды** — обе команды (1С и Сайт) работают по одной спецификации
2. **Машиночитаемость** — JSON Schema для каждого payload позволяет автоматически валидировать сообщения
3. **Документация** — генерируется автоматически из спецификации
4. **Уменьшение рассогласований** — любое изменение контракта делается в одном файле

## Просмотр спецификации

### AsyncAPI Studio (Online)

1. Откройте [studio.asyncapi.com](https://studio.asyncapi.com/)
2. Вставьте содержимое `pecado-erp-integration.yaml`

### Генерация HTML-документации

```bash
# Из корня проекта (внутри контейнера)
docker exec pecado-node npx -y @asyncapi/cli generate fromTemplate \
  docs/asyncapi/pecado-erp-integration.yaml \
  @asyncapi/html-template \
  -o docs/asyncapi/html \
  --force-write
```

Или через npm-скрипт:
```bash
docker exec pecado-node npm run asyncapi:html
```

### Валидация спецификации

```bash
docker exec pecado-node npx -y @asyncapi/cli validate docs/asyncapi/pecado-erp-integration.yaml
```

Или через npm-скрипт:
```bash
docker exec pecado-node npm run asyncapi:validate
```

## Runtime-валидация сообщений

На стороне сайта настроена runtime-валидация входящих сообщений из 1С.

### Как работает

1. `ErpIncomingJob` при получении сообщения вызывает `ErpMessageValidator`
2. Валидатор находит JSON Schema по типу события (`event`)
3. Если payload не соответствует схеме — сообщение логируется с ошибкой и удаляется из очереди
4. Если валидация пройдена — сообщение передаётся обработчику

### Файлы схем

JSON Schema файлы расположены в `app/Services/Erp/Schemas/` и автоматически извлечены из AsyncAPI спецификации.

## Версионирование

Версия спецификации (поле `info.version`) соответствует версии `ACCEPTANCE_CRITERIA`.

| Версия спецификации | ACCEPTANCE_CRITERIA |
|---|---|
| 11.0.0 | v11.0 |

При обновлении контракта обмена:
1. Обновить `pecado-erp-integration.yaml`
2. Обновить JSON Schema в `app/Services/Erp/Schemas/`
3. Перегенерировать HTML-документацию
4. Обновить `ACCEPTANCE_CRITERIA`

## Структура спецификации

### Каналы (Channels)

| Канал | Exchange | Очередь | Направление |
|---|---|---|---|
| erpInPartners | erp.events | erp_in.partners | 1С → Сайт |
| erpInPrices | erp.events | erp_in.prices | 1С → Сайт |
| erpInStock | erp.events | erp_in.stock | 1С → Сайт |
| erpInOrders | erp.events | erp_in.orders | 1С → Сайт |
| erpInReturns | erp.events | erp_in.returns | 1С → Сайт |
| erpInDocuments | erp.events | erp_in.documents | 1С → Сайт |
| erpInBalance | erp.events | erp_in.balance | 1С → Сайт |
| erpInCatalog | erp.events | erp_in.catalog | 1С → Сайт |
| erpOutOrders | site.events | erp_out.orders | Сайт → 1С |
| erpOutReturns | site.events | erp_out.returns | Сайт → 1С |
| erpOutPartners | site.events | erp_out.partners | Сайт → 1С |

### Операции (24)

Полный список операций с описанием — в спецификации.
