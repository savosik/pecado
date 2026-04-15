# Убрать атрибуты "Наша компания" и "Координаты" у контрагентов (Company)

**Приоритет:** средний
**Создано:** 2026-04-15
**Затронутые события:** order.created (Сайт → 1С)
**Затронутые схемы:** order.created.to_erp.json, order.created.json

## Описание

У контрагентов (модель `Company`) необходимо убрать атрибуты "Наша компания" (`is_our_company`) и "Координаты" (`latitude`/`longitude`). Эти поля больше не используются в бизнес-логике.

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: удалить `latitude`/`longitude` из `order.created.to_erp.json` и `order.created.json` (contractor)
- [ ] AsyncAPI YAML: удалить `latitude`/`longitude` из `OrderContractor`
- [ ] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [ ] Бизнес-правила: `docs-erp/content/rules/contractors.md` — отметить удаление полей
- [ ] Changelog: `docs-erp/content/changelog.md`
- [ ] Сборка: `mkdocs build`

### Код
- [ ] Миграция БД: удалить колонки `is_our_company`, `latitude`, `longitude` из `companies`
- [ ] Модель `Company`: убрать из `$fillable` и `$casts`
- [ ] `CompanyController`: убрать валидацию и `yandexMapsApiKey`
- [ ] `CompanyFactory`: убрать генерацию этих полей
- [ ] `PublishOrderToErp`: убрать `latitude`/`longitude` из payload
- [ ] Админка React: убрать поля из Create.jsx, Edit.jsx, Index.jsx
- [ ] Удалить `YandexMapPicker.jsx` (используется только в Companies)

### Тесты
- [ ] Feature-тест: обновить `ErpIncomingJobTest.php` если затронут

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
- [ ] Код закоммичен и запушен
