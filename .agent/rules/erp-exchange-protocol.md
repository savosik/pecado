# Протокол обмена ERP (1С ↔ Сайт)

Любое изменение в протоколе обмена данными между сайтом и 1С нужно **начинать** с:

1. **Актуализации AsyncAPI спецификации** — `docs/asyncapi/pecado-erp-integration.yaml`
2. **Актуализации JSON Schema** — `app/Services/Erp/Schemas/*.json` (входящие и исходящие)
3. **Генерации актуального HTML** из AsyncAPI спецификации

Только после этого вносить изменения в код обработчиков, job-ов и listener-ов.

## Важно: два источника схем

Сейчас payload-схемы описаны в двух местах:

- **YAML** (`docs/asyncapi/pecado-erp-integration.yaml`) — полные описания с примерами, вложенными типами, descriptions. Для документации.
- **JSON** (`app/Services/Erp/Schemas/*.json`) — плоские standalone JSON Schema Draft-07 для runtime-валидации через `ErpMessageValidator`.

**При любом изменении структуры payload нужно обновлять ОБА файла**, чтобы документация и валидация были консистентны.
