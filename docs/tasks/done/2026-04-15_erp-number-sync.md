# Синхронизация номера заказа/возврата из 1С

**Приоритет:** высокий
**Создано:** 2026-04-15
**Затронутые события:** order.created, order.updated, return.updated
**Затронутые схемы:** order.created.json, order.updated.json, return.updated.json

## Описание

Когда пользователь создаёт заказ на сайте, ему присваивается автоматический номер (ORD-YYYY-NNNN).
Однако при получении 1С заказа через шину, 1С присваивает ему свой внутренний номер.
Когда клиент звонит менеджеру и называет номер заказа с сайта — менеджер не может найти
его в 1С, т.к. в 1С используется другая нумерация.

**Решение:** Сохранять номер из 1С (`erp_number`) как отдельное поле.
Пользователь и менеджер смогут видеть номер из 1С для коммуникации.

Аналогично для возвратов.

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: `order.updated.json` — добавить поле `number`
- [ ] JSON Schema: `return.updated.json` — добавить поле `number`
- [ ] AsyncAPI YAML — обновить OrderUpdatedPayload и ReturnUpdatedPayload
- [ ] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [ ] Бизнес-правила: `rules/orders.md`, `rules/returns.md`
- [ ] Changelog: `docs-erp/content/changelog.md`
- [ ] Сборка

### Код
- [ ] Миграция: добавить `erp_number` в `orders` и `returns`
- [ ] Handler `HandleOrderUpdated` — сохранять `number` → `erp_number`
- [ ] Handler `HandleOrderCreated` — сохранять `number` → `erp_number`
- [ ] Handler `HandleReturnUpdated` — сохранять `number` → `erp_number`
- [ ] Модели: добавить `erp_number` в fillable
- [ ] Frontend: отображать `erp_number` в кабинете и админке

### Тесты
- [ ] Unit-тест HandleOrderUpdated — проверка сохранения erp_number
- [ ] Unit-тест HandleReturnUpdated — проверка сохранения erp_number

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
