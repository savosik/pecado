# Добавление типа date-time для атрибутов товаров и стандартизация дат

**Приоритет:** высокий
**Создано:** 2026-04-15
**Затронутые события:** product.created, product.updated, order.created, order.updated, balance.updated
**Затронутые схемы:** 
- product.created.json
- product.updated.json
- order.created.json
- order.created.to_erp.json
- order.updated.json
- balance.updated.json

## Описание

В данный момент даты передавались без четкой спецификации `date-time` в схемах, а атрибуты товаров поддерживали только строки, числа, булевы значения и ссылки. Требуется явно добавить поддержку передачи дат и времени (`date-time`) для атрибутов товаров, поддержать это на уровне базы данных Laravel (добавить `datetime_value`), а также строго типизировать форматы дат в существующих JSON-схемах, согласно ISO8601Date и ISO8601DateTime из AsyncAPI YAML.

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: Обновить схемы событий.
- [ ] AsyncAPI YAML: Обновить `docs/asyncapi/pecado-erp-integration.yaml`.
- [ ] Валидация: `npm run asyncapi:validate`.

### Документация (MkDocs)
- [ ] Бизнес-правила: `docs-erp/content/rules/product-attributes.md`.
- [ ] Changelog: `docs-erp/content/changelog.md`.
- [ ] Сборка: `mkdocs build`.

### Код
- [ ] Миграция БД: Добавление колонки `datetime_value` (timestamp) к таблице `product_attribute_values`.
- [ ] Handler: Добавить обработку `date-time` в `HandleProductCreated.php` и `HandleProductUpdated.php` и вывод в `ProductAttributeValue.php`.

### Тесты
- [ ] Миграция применяется корректно, нет фатальных ошибок в хендлерах.

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
- [ ] Код закоммичен и запушен
