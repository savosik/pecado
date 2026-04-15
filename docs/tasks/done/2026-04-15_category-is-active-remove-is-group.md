# Категории: удалить is_group из протокола, добавить is_active

**Приоритет:** высокий
**Создано:** 2026-04-15
**Затронутые события:** category.created, category.updated
**Затронутые схемы:** category.created.json

## Описание

- Удалить `is_group` из протокола обмена (JSON Schema, AsyncAPI) — это внутренний атрибут сайта, 1С не должна его присылать
- Добавить `is_active` (boolean) в протокол обмена для категорий — 1С будет управлять активностью категорий
- Обновить версию спецификации до v12
- Обновить handler для обработки `is_active` и игнорирования `is_group` из payload
- Добавить тест-кейсы

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: `app/Services/Erp/Schemas/category.created.json`
- [ ] AsyncAPI YAML: `docs/asyncapi/pecado-erp-integration.yaml`
- [ ] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [ ] Бизнес-правила: `docs-erp/content/rules/catalog.md`
- [ ] Тест-план: `docs-erp/content/tests/phase-1-inbound.md`
- [ ] Changelog: `docs-erp/content/changelog.md`
- [ ] Сборка: `mkdocs build`

### Код
- [ ] Handler: `app/Services/Erp/Handlers/HandleCategoryCreated.php`
- [ ] Миграция БД: не нужна (is_active уже есть в таблице)

### Тесты
- [ ] Feature-тест: `tests/Feature/Erp/ErpIncomingJobTest.php`

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
- [ ] Код закоммичен и запушен
