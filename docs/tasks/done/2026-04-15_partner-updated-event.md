# Событие partner.updated (1С → Сайт)

**Приоритет:** высокий
**Создано:** 2026-04-15
**Затронутые события:** partner.updated (новое), partner.created (уточнение scope)
**Затронутые схемы:** partner.updated.json (новая)

## Описание

Сейчас обновление атрибутов партнёра приходит через `partner.created` (идемпотентно). Нужно выделить отдельное событие `partner.updated` для обновления атрибутов существующего пользователя, включая:

- `name`, `phone`, `city`, `country`, `region` — демографические данные
- `is_active` — статус активности → `UserStatus::ACTIVE / BLOCKED`
- `client_status` — уровень лояльности → `ClientStatus.external_id`
- Привязка `erp_id` по совпадению email (если `erp_id` ещё не привязан)

`partner.created` остаётся для создания нового пользователя и первоначальной привязки.

## План изменений

### Спецификация (spec-first)
- [ ] JSON Schema: `app/Services/Erp/Schemas/partner.updated.json`
- [ ] AsyncAPI YAML: `docs/asyncapi/pecado-erp-integration.yaml`
- [ ] Валидация: `npm run asyncapi:validate`

### Документация (MkDocs)
- [ ] Бизнес-правила: `docs-erp/content/rules/partners.md`
- [ ] Тест-план: `docs-erp/content/tests/phase-1-inbound.md`
- [ ] Changelog: `docs-erp/content/changelog.md`
- [ ] Сборка: `mkdocs build`

### Код
- [ ] Handler: `app/Services/Erp/Handlers/HandlePartnerUpdated.php`
- [ ] Роутинг: `ErpIncomingJob` — добавить `partner.updated`
- [ ] Валидатор: `ErpMessageValidator` — добавить `partner.updated`

### Тесты
- [ ] Feature-тест: `tests/Unit/Services/Erp/Handlers/HandlePartnerUpdatedTest.php`

## Критерии готовности
- [ ] JSON Schema валидна
- [ ] AsyncAPI YAML проходит валидацию
- [ ] MkDocs собирается без ошибок
- [ ] Тесты проходят
- [ ] Код закоммичен и запушен
