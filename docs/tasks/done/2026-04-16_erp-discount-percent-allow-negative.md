# ERP: Разрешить отрицательный discount_percent в схемах order.created и shipment.created

**Приоритет:** средний (P2)
**Исполнитель:** -
**Создано:** 2026-04-16

## Описание

JSON Schema событий `order.created` (поле `items[].discount_percent`) и `shipment.created` (поле `items[].discount_percent` и `items[].auto_discount_percent`) устанавливает ограничение `minimum: 0`, а 1С передаёт отрицательные значения скидок.

```
/items/0/discount_percent: Number must be greater than or equal to 0
/items/1/auto_discount_percent: Number must be greater than or equal to 0
```

**Масштаб:** 18 failed-сообщений (3 в `order.created`, 15 в `shipment.created`).

Аналогичная проблема уже решалась для поля `manual_discount_percent` в коммитах v12.7.3 и v12.7.4 — нужно применить ту же логику к `discount_percent` и `auto_discount_percent`.

## Требования

- [ ] В JSON Schema `order.created` убрать `minimum: 0` для `items[].discount_percent`
- [ ] В JSON Schema `shipment.created` убрать `minimum: 0` для `items[].discount_percent` и `items[].auto_discount_percent`
- [ ] Обновить AsyncAPI-документацию
- [ ] Добавить/обновить интеграционный тест с отрицательным значением скидки

## Критерии готовности

- Сообщения с отрицательным `discount_percent` проходят валидацию
- Схема, AsyncAPI и документация обновлены
- Интеграционный тест проходит
