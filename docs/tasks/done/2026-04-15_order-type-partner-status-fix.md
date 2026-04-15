# Убрать type из входящих заказов и enum из client_status партнёров

**Приоритет:** высокий
**Создано:** 2026-04-15
**Затронутые события:** order.created, partner.created, partner.updated
**Затронутые схемы:** order.created.json, partner.created.json, partner.updated.json

## Описание

1. **order.created / order.updated (1С → Сайт)**: 1С не знает типы заказов (order/preorder) — это внутреннее понятие сайта. Поле `type` не должно быть обязательным во входящих схемах. В исходящем направлении (Сайт → 1С) `type` остаётся — сайт сам определяет тип заказа.

2. **partner.* (1С → Сайт)**: `client_status` — это просто строка (gold, silver и т.д.), а не enum. 1С может прислать любое значение, сайт резолвит его через `ClientStatus.external_id`.

## План изменений

### Спецификация (spec-first)
- [x] JSON Schema: `order.created.json` — убрать `type` из `required`
- [x] JSON Schema: `partner.created.json` — `client_status` уже без enum ✓
- [x] JSON Schema: `partner.updated.json` — `client_status` уже без enum ✓
- [x] AsyncAPI YAML — убрать `type` из `required` в OrderCreatedPayload, убрать enum из client_status
- [x] AsyncAPI YAML — обновить `version`
- [x] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [x] Бизнес-правила: `rules/orders.md` — обновить описание type
- [x] Бизнес-правила: `rules/partners.md` — убрать перечисление enum
- [x] Changelog: `docs-erp/content/changelog.md`
- [x] Сборка: `mkdocs build`

### Код
- [x] HandleOrderCreated — `type` уже опционален с дефолтом 'order' ✓
- [x] Тесты — проверить что всё проходит

## Критерии готовности
- [x] JSON Schema валидна
- [x] AsyncAPI YAML проходит валидацию
- [x] MkDocs собирается без ошибок
- [x] Тесты проходят
