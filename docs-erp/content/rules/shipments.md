# Реализации

> **JSON Schema:** [`shipment.created.json`](/docs/erp/schemas/shipment.created.json) | [`shipment.updated.json`](/docs/erp/schemas/shipment.updated.json) | [`shipment.deleted.json`](/docs/erp/schemas/shipment.deleted.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

**Направление:** 1С → Сайт | **Очередь:** `erp_in.documents`

## События

| Событие | Описание |
|---|---|
| `shipment.created` | При первом проведении реализации |
| `shipment.updated` | При перепроведении (структура идентична `created`, отличается `event`) |
| `shipment.deleted` | При отмене проведения |

## Бизнес-правила

- Одна реализация может включать позиции из **нескольких заказов**
- Связь с заказами через `order_uuid` в каждой позиции
- Расчёт задолженности ведётся по реализациям, не по заказам
- Привязка к контрагенту:
    - **(v13.2)** Приоритет — по `contractor_uuid` (опциональное поле, `Company.erp_id` на сайте). Глобально уникальный идентификатор, не ломается при правке ИНН в 1С
    - **Fallback** — по `tax_id` (ИНН) **в паре с `partner_uuid`**. `partner_uuid` нужен для резолва `user_id` и фильтра Company по владельцу. Без `partner_uuid` fallback-поиск не выполняется (security-fix v13.2)
- **(v12.3)** Если передан `number` — сайт сохраняет его как `erp_number`. Это номер реализации из 1С, который отображается пользователю
- **(v12.10)** У `shipment.updated` **собственная JSON Schema** (`shipment.updated.json`), отделённая от `shipment.created.json`. Пока структуры payload-ов идентичны; разделение выполнено на вырост — для независимой эволюции `updated` при появлении у него собственных полей
- **(v12.11) `number` обязательно для `shipment.created` и `shipment.updated`.** 1С обязана передавать номер реализации непустой строкой. Сообщения без `number` отклоняются валидатором и отправляются в DLQ. Причина: без ERP-номера покупатель в ЛК видит только технический `id` сайта, а менеджер в 1С — ERP-номер; это ломает коммуникацию клиент-менеджер при обсуждении отгрузки
- **(v13.7)** Опциональные аудит-метки `erp_created_at` / `erp_updated_at` (ISO-8601 datetime с TZ) — момент создания/изменения документа на стороне 1С. Сохраняются в `shipments.erp_created_at` / `shipments.erp_updated_at` и отображаются в админке. **Не путать** с бизнес-полем `date` — это дата проведения реализации (день отгрузки), а аудит-метки фиксируют момент действия в учётной системе. При `shipment.updated` достаточно передавать только `erp_updated_at`. Подробнее: [«Аудит-метки 1С: erp_created_at / erp_updated_at»](../guides/erp-timestamps-for-1c.md)

## Критерии приёмки

- [ ] Сайт принимает `shipment.created`, `shipment.updated`, `shipment.deleted`
- [ ] **(v13.2)** Company находится сначала по `contractor_uuid`, затем fallback по `tax_id + user_id` (резолв через `partner_uuid`). Поиск без `partner_uuid` / `user_id` не выполняется — иначе можно найти чужую Company с тем же ИНН
- [ ] Позиции связаны с заказами через `order_uuid`
- [ ] Реализация отображается в ЛК
- [ ] **(v12.10)** Валидация `shipment.updated` использует схему `shipment.updated.json` (поле `event` = `shipment.updated`)
- [ ] **(v12.11)** `shipment.created` / `shipment.updated` без поля `number` или с пустой строкой отклоняются на стадии валидации и не создают/не обновляют реализацию
