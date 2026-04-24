# Контрагенты

> **JSON Schema:** [`contractor.created.json`](/docs/erp/schemas/contractor.created.json) | [`contractor.updated.json`](/docs/erp/schemas/contractor.updated.json) | [`contractor.deleted.json`](/docs/erp/schemas/contractor.deleted.json) | [`contractor.created.to_erp.json`](/docs/erp/schemas/contractor.created.to_erp.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `contractor.created` | 1С → Сайт | `erp_in.partners` |
| `contractor.updated` | 1С → Сайт | `erp_in.partners` |
| `contractor.deleted` | 1С → Сайт | `erp_in.partners` |
| `contractor.created` | Сайт → 1С | `erp_out.contractors` (v13.2) |

## Стратегия матчинга (v13.2)

До v13.2 контрагент идентифицировался только по ИНН (`tax_id`). Если менеджер
в 1С исправлял ИНН после создания контрагента на сайте (например, убирал лишнюю
цифру), матчинг ломался — последующие реализации и балансы переставали привязываться
к Company. С v13.2 основной идентификатор — UUID контрагента (`Company.erp_id` на
сайте, ссылка в 1С), ИНН остаётся fallback-ом на переходный период.

Приоритет при поиске Company во всех входящих обработчиках:

1. **По UUID** — `Company.erp_id = contractor.uuid / contractor_uuid / contractors[].uuid`.
2. **Fallback по ИНН** — `Company.tax_id = tax_id` **+ `user_id` партнёра** (резолв через
   `partner_uuid → User.erp_id`). Без фильтра по `user_id` поиск не выполняется:
   в противном случае можно найти чужую Company с тем же ИНН.
3. **Ленивый backfill `erp_id`** — если Company найдена по ИНН, а в payload пришёл UUID,
   сайт привязывает `Company.erp_id = uuid` через `Company::withoutEvents()`.

## contractor.created (Сайт → 1С) — v13.2

### Триггер публикации

- Пользователь создаёт Company в ЛК или в checkout с непустым `tax_id` → публикуется.
- Пользователь обновляет Company и заполняет `tax_id` впервые (ранее был пуст) → публикуется.
- Пользователь обновляет уже отправленного контрагента (любое поле) → **не публикуется**.
  После первой публикации 1С становится авторитетом; дальнейшие изменения приходят
  от 1С через `contractor.updated`.

### Предусловия

- У партнёра-владельца должен быть заполнен `User.erp_id`. Если ещё нет —
  публикация откладывается (log info). После получения UUID партнёром через
  `partner.updated` listener `PublishUserToErp` догоняет все Company без
  `erp_id` у этого пользователя.

### Поле `uuid` в payload

- Локальный UUIDv4 сайта, генерируется при публикации.
- Нужен только для корреляции — 1С генерирует свой UUID независимо и возвращает
  его через `contractor.updated`.

### Формат

- **Обязательные поля:** `event`, `uuid`, `partner_uuid`, `tax_id`, `name`, `country`.
- Реквизиты и `bank_accounts[]` опциональны.

### Откат

- Фиче-флаг `PUBLISH_CONTRACTORS_TO_ERP=false` полностью отключает publisher
  без деплоя (listener молча пропускает события).

## contractor.updated (1С → Сайт) — v13.2

### Назначение

- **Привязка UUID** после первой публикации сайта — 1С генерирует свой UUID
  и возвращает его; сайт находит Company по `tax_id + user_id` и проставляет `erp_id`.
- **Частичное обновление атрибутов** существующего контрагента.

### Правила обработки

- Required поля: `event`, `message_id`, `uuid`. `partner_uuid` требуется для
  fallback-матчинга.
- Частичный апдейт: обновляются только переданные поля.
- `bank_accounts`: если массив передан — полностью заменяет существующие счета;
  если `null` / не передан — счета не трогаются.
- Если Company не найдена ни по UUID, ни по `tax_id+user_id` — warning,
  ничего не создаём (это задача `contractor.created`).

## contractor.deleted (1С → Сайт) — v13.2

### Правила

- Для идентификации достаточно `uuid` **или** `tax_id`.
- Soft-delete через `SoftDeletes`, `Company::withoutEvents()`.

## contractor.created (1С → Сайт) — существующее поведение

### Бизнес-правила

- Сайт создаёт контрагента с привязкой к партнёру через `partner_uuid`.
- `country` гарантированно не `null` — фолбэк на `СтранаПоУмолчанию`.
- Первый банковский счёт имеет `is_primary = true`.
- **(v12.7)** Удалены поля `latitude`/`longitude`.

## Критерии приёмки

- [ ] Сайт публикует `contractor.created` при создании Company с `tax_id`
- [ ] Публикация откладывается, если у партнёра нет `User.erp_id`
- [ ] Повторное сохранение Company (после первой публикации) НЕ триггерит публикацию
- [ ] Сайт принимает `contractor.updated` и привязывает `erp_id` к существующей Company (backfill по `tax_id + user_id`)
- [ ] `contractor.updated` обновляет только переданные поля; `bank_accounts` заменяется только при явной передаче массива
- [ ] Сайт принимает `contractor.deleted` и делает soft-delete Company по UUID или tax_id
- [ ] Все входящие обработчики (`order.*`, `shipment.*`, `balance.*`) ищут Company сначала по UUID, затем по `tax_id + user_id`
- [ ] UUID-поля (`contractor.uuid`, `contractor_uuid`, `contractors[].uuid`) опциональны — переходный период не требует синхронной выкатки 1С
- [ ] `country` не `null` в `contractor.created`
- [ ] Банковские счета создаются из `bank_accounts[]`, первый счёт — `is_primary = true`
