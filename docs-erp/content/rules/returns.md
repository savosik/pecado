# Возвраты

> **JSON Schema:** [`return.created.to_erp.json`](/docs/erp/schemas/return.created.to_erp.json) | [`return.updated.json`](/docs/erp/schemas/return.updated.json) | [`return.deleted.json`](/docs/erp/schemas/return.deleted.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

!!! warning "Отложено"
    US-09 отложен на следующий скоп. JSON-шаблоны и обработчики созданы.

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `return.created` | Сайт → 1С | `erp_out.returns` |
| `return.updated` | 1С → Сайт | `erp_in.returns` |
| `return.deleted` | 1С → Сайт | `erp_in.returns` |

---

## return.updated (1С → Сайт)

### Бизнес-правила

- Обновляет статус возврата по UUID
- **(v12.3)** Если передан `number` — сайт сохраняет его как `erp_number`. Это номер возврата из 1С, который отображается пользователю для коммуникации с менеджером
