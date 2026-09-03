# res-02 · Spec-first: схемы, AsyncAPI, MkDocs по итогам resolution

**Приоритет:** высокий
**Создано:** 2026-09-02
**Эпик:** [res-00](2026-09-02_res-00-epic.md)
**Зависимости:** res-01 (resolution топика — единственный вход этой карточки)
**Волна:** 0
**Блокирует:** res-03, res-05 — весь код шины и модели

## Описание

Перенести договорённости из resolution топика в контракт. Строго по порядку протокола
обмена ERP (`.claude/rules/erp-exchange-protocol.md`):

### 1. JSON Schema — `app/Services/Erp/Schemas/`

- `order.created.to_erp.json` — добавить признак резерва (имя поля — из resolution);
- новые исходящие схемы: `order.updated.to_erp.json`, `order.deleted.to_erp.json`
  (или `order.cancelled.to_erp.json` — как решат агенты);
- входящий `order.updated.json` — статус «Резерв» в перечислении статусов, если
  перечисление там ограничено.

### 2. AsyncAPI — `docs/asyncapi/pecado-erp-integration.yaml`

- новые operations «сайт → 1С» для обновления/отмены заказа;
- признак резерва в `sendOrderCreated`;
- обновить описание «после отправки пользователь изменять заказ НЕ может» — оно
  перестаёт быть правдой для резервов и ранних статусов.

### 3. Бизнес-правила и тест-план — `docs-erp/content/`

- `rules/*.md` — правила резерва: жизненный цикл, таймаут, конфликты по `revision`;
- `tests/*.md` — критерии приёмки для 1С;
- `changelog.md` — запись об изменении контракта.

### 4. Сборка

- `docker exec pecado-node npm run asyncapi:build` (validate → bundle → html) зелёный;
- `mkdocs build` зелёный.

Код обработчиков в этой карточке **не трогаем** — только контракт и документация.

## Ход работ

- **03.09.2026** — редакция **v16.9.0** (16.8.0 оказалась занята записью changelog от
  25.08 при отставшем info.version=16.7.0 в YAML — выровнено). Сделано: 3 новые исходящие
  схемы (`order.updated.to_erp`, `order.deleted.to_erp`, `order.confirmed.to_erp`),
  в `order.created.to_erp` — reserve/reserved_until с if/then (reserved_until обязателен
  при reserve=true), аддитивные поля во входящих
  order.* и partner.*; AsyncAPI: канал erp_out.orders ×4 события, 3 операции send*,
  3 сообщения, 3 payload, правка sendOrderCreated («изменять не может» → окно резерва);
  MkDocs: rules/order-reserves.md, tests/reserves.md (Р-1…Р-6), changelog 16.9.0, nav.
  Тесты валидатора и публикации зелёные (145). Коммит `4f47d963` в dev.

## Критерии готовности

- [x] Все схемы обновлены/созданы и валидны.
- [x] AsyncAPI описывает полный протокол резервов в обе стороны.
- [x] Правила, тест-план и changelog в MkDocs обновлены.
- [x] `npm run asyncapi:build` и `mkdocs build` проходят.
- [x] Агент 1С получил ссылку на обновлённую спецификацию — CI на dev зелёный,
  spec.yaml отдаёт 16.9.0, ссылки досланы в топик №5 модераторским сообщением
  (seq 7, 03.09.2026).
