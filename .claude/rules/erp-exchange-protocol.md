# Протокол обмена ERP (1С ↔ Сайт)

Любое изменение в протоколе обмена данными между сайтом и 1С нужно **начинать** с:

1. **Актуализации JSON Schema** — `app/Services/Erp/Schemas/*.json` (входящие и исходящие)
2. **Актуализации AsyncAPI спецификации** — `docs/asyncapi/pecado-erp-integration.yaml`
3. **Актуализации бизнес-правил** — `docs-erp/content/rules/*.md` и `docs-erp/content/tests/*.md`
4. **Добавления записи в Changelog** — `docs-erp/content/changelog.md`
5. **Сборки документации**: `docker exec pecado-node npm run asyncapi:build` + `mkdocs build`

Только после этого вносить изменения в код обработчиков, job-ов и listener-ов.

## Источники документации

| Файл | Назначение | Используется |
|---|---|---|
| `app/Services/Erp/Schemas/*.json` | Runtime-валидация payload-ов | `ErpMessageValidator` |
| `docs/asyncapi/pecado-erp-integration.yaml` | API-документация (payload-структура) | HTML, bundled YAML |
| `docs-erp/content/` | Бизнес-правила, критерии, тест-план | MkDocs HTML |

## npm-скрипты

| Команда | Описание |
|---|---|
| `npm run asyncapi:validate` | Проверка валидности YAML |
| `npm run asyncapi:bundle` | Генерация bundled YAML |
| `npm run asyncapi:html` | Генерация HTML |
| `npm run asyncapi:build` | Всё вместе: validate → bundle → html |

## Публичные URL

| URL | Формат | Для кого |
|---|---|---|
| `/docs/erp/` | HTML | AsyncAPI — интерактивная документация payload-ов |
| `/docs/erp/spec.yaml` | YAML (bundled) | AI-агент — полная спецификация одним файлом |
| `/docs/erp/schemas/` | JSON | AI-агент — отдельные JSON Schema файлы |
| `/docs/erp-guide/` | HTML | MkDocs — бизнес-правила, тест-план, changelog |

