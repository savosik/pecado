# Протокол обмена ERP (1С ↔ Сайт)

Любое изменение в протоколе обмена данными между сайтом и 1С нужно **начинать** с:

1. **Актуализации JSON Schema** — `app/Services/Erp/Schemas/*.json` (входящие и исходящие)
2. **Актуализации AsyncAPI спецификации** — `docs/asyncapi/pecado-erp-integration.yaml`
3. **Сборки документации**: `docker exec pecado-node npm run asyncapi:build`
   - Валидирует YAML спецификацию
   - Генерирует bundled YAML (все `$ref` заинлайнены) для публикации
   - Генерирует HTML документацию

Только после этого вносить изменения в код обработчиков, job-ов и listener-ов.

## Два источника схем

Payload-схемы описаны в двух местах — **оба нужно обновлять при любом изменении**:

| Файл | Назначение | Используется |
|---|---|---|
| `app/Services/Erp/Schemas/*.json` | Runtime-валидация payload-ов | `ErpMessageValidator` |
| `docs/asyncapi/pecado-erp-integration.yaml` | Документация (описания, примеры, бизнес-логика) | HTML-документация, bundled YAML |

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
| `/docs/erp/` | HTML | Разработчик — интерактивная документация |
| `/docs/erp/spec.yaml` | YAML (bundled) | AI-агент — полная спецификация одним файлом |
| `/docs/erp/schemas/` | JSON | AI-агент — отдельные JSON Schema файлы |
