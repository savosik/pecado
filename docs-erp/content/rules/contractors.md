# Контрагенты

> **JSON Schema:** [`contractor.created.json`](/docs/erp/schemas/contractor.created.json) | [`contractor.updated.json`](/docs/erp/schemas/contractor.updated.json) | [`contractor.deleted.json`](/docs/erp/schemas/contractor.deleted.json) | [`contractor.created.to_erp.json`](/docs/erp/schemas/contractor.created.to_erp.json) | [`contractor.updated.to_erp.json`](/docs/erp/schemas/contractor.updated.to_erp.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `contractor.created` | 1С → Сайт | `erp_in.contractors` |
| `contractor.updated` | 1С → Сайт | `erp_in.contractors` |
| `contractor.deleted` | 1С → Сайт | `erp_in.contractors` |
| `contractor.created` | Сайт → 1С | `erp_out.contractors` (v13.2) |
| `contractor.updated` | Сайт → 1С | `erp_out.contractors` (v13.8) |

> **v13.5:** входящие `contractor.*` выделены в отдельную очередь `erp_in.contractors`
> с собственной DLQ `erp_dlq.contractors` и отдельным supervisor-консьюмером.
> Ранее (v13.2–v13.4) они шли в общую очередь `erp_in.partners`.

## Стратегия матчинга (v13.6)

> **BREAKING с v13.6:** `contractor.uuid` обязателен в `order.created`,
> `contractor_uuid` обязателен в `shipment.created` / `shipment.updated`.
> Уникальность Company гарантируется на уровне БД через
> `UNIQUE(tax_id, tax_code, deleted_at)`.

### Бизнес-правило

Уникальность контрагента — пара **(ИНН, КПП)**, юридически. Разные КПП у одного
ИНН — это разные подразделения (например, головной офис и филиал) и должны
существовать как разные Company. У ИП КПП отсутствует — храним как пустую строку
`''` (NOT NULL DEFAULT '').

### Алгоритм (применяется во всех входящих обработчиках)

1. **По UUID** — `Company.erp_id = contractor.uuid / contractor_uuid`.
2. **Fallback по (ИНН, КПП)** — `Company.tax_id = tax_id AND Company.tax_code = tax_code`.
   `user_id` в фильтр **не входит** (ИНН+КПП юридически уникальны), что позволяет
   найти Company даже до того, как сайт привязал её к партнёру.
3. **Ленивый backfill `erp_id`** — если Company найдена по ИНН/КПП, а в payload
   пришёл UUID, сайт привязывает `Company.erp_id = uuid` через `withoutEvents()`.
4. **Soft-deleted** — найденная мягко-удалённая Company восстанавливается
   (`restoreQuietly()`).
5. **Lock** — поиск выполняется внутри `DB::transaction` с `lockForUpdate()`,
   защищая от race-condition при параллельной обработке. UNIQUE-индекс БД —
   последний рубеж защиты от дублей.

## contractor.created (Сайт → 1С) — v13.2

### Триггер публикации

- Пользователь создаёт Company в ЛК или в checkout с непустым `tax_id` → публикуется.
- Пользователь обновляет Company и заполняет `tax_id` впервые (ранее был пуст) → публикуется.
- Пользователь обновляет уже синхронизированного контрагента → публикуется как
  `contractor.updated` (Сайт → 1С), см. отдельный раздел ниже. Однонаправленность
  «1С — авторитет» сохраняется только в смысле UUID: сайт не присваивает свой UUID,
  но фактические изменения данных в админке должны доезжать до 1С.

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

## contractor.updated (Сайт → 1С) — v13.8

### Назначение

Передавать в 1С локальные изменения данных уже синхронизированного контрагента —
включая создание / обновление / удаление банковских реквизитов. До v13.8 такие
изменения оставались только на сайте и не доезжали до 1С, что приводило к
расхождению данных.

### Триггер публикации

- `Company` с уже заполненным `erp_id` обновлена в админке, и изменилось хотя
  бы одно из значимых полей: `name`, `legal_name`, `tax_id`, `tax_code`,
  `registration_number`, `okpo_code`, `legal_address`, `actual_address`,
  `phone`, `email`, `country`.
- `CompanyBankAccount` создан / обновлён / удалён у такой Company
  (Observer `CompanyBankAccountObserver`). В payload уезжает полный актуальный
  набор счетов в `bank_accounts[]`.

Если у Company ещё нет `erp_id` (не синхронизирована с 1С), вместо
`contractor.updated` публикуется `contractor.created` при первом заполнении
`tax_id` — см. раздел выше.

### Защита от петли

«1С прислала `contractor.updated` → сайт обновил Company → сайт отправил
`contractor.updated` обратно → 1С обновила → …» — три уровня защиты:

1. **`Company::withoutEvents()`** в `HandleContractorCreated/Updated/Deleted` —
   событие `CompanyUpdated` не диспатчится, listener `PublishContractorToErp`
   не вызывается.
2. **Транзиентный флаг `Company->fromErp = true`** — выставляется в
   `HandleContractor*` сразу после резолва модели. И listener
   `PublishContractorToErp`, и `CompanyBankAccountObserver` проверяют флаг
   и пропускают публикацию. Страховка на случай, если кто-то забудет
   `withoutEvents()`.
3. **`CompanyBankAccount::withoutEvents()`** в `HandleContractorCreated/Updated`
   при пересборке банковских счетов — Observer не вызывается на каждом счёте.

### Поле `uuid` в payload

- `Company.erp_id` (UUID, выданный 1С). Обязателен — 1С матчит контрагента
  строго по этому UUID.

### Формат

- **Обязательные поля:** `event`, `uuid`, `partner_uuid`, `tax_id`, `name`, `country`.
- `bank_accounts[]` — полный актуальный набор счетов (1С полностью замещает
  свой набор полученным из payload).

### Откат

- Тот же фиче-флаг `PUBLISH_CONTRACTORS_TO_ERP=false` отключает обе
  публикации — `contractor.created` и `contractor.updated`.

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
- [ ] Повторное сохранение Company с `erp_id` теперь публикует `contractor.updated`
      (с v13.8) — но **только** при изменении хотя бы одного значимого поля.
- [ ] Сайт принимает `contractor.updated` и привязывает `erp_id` к существующей Company (backfill по `tax_id + user_id`)
- [ ] `contractor.updated` обновляет только переданные поля; `bank_accounts` заменяется только при явной передаче массива
- [ ] Сайт принимает `contractor.deleted` и делает soft-delete Company по UUID или tax_id
- [ ] Все входящие обработчики (`order.*`, `shipment.*`, `balance.*`) ищут Company сначала по UUID, затем по `tax_id + user_id`
- [ ] UUID-поля (`contractor.uuid`, `contractor_uuid`, `contractors[].uuid`) опциональны — переходный период не требует синхронной выкатки 1С
- [ ] `country` не `null` в `contractor.created`
- [ ] Банковские счета создаются из `bank_accounts[]`, первый счёт — `is_primary = true`
- [ ] Сайт публикует `contractor.updated` (Сайт → 1С) при изменении Company с `erp_id`
- [ ] Сайт публикует `contractor.updated` (Сайт → 1С) при создании/обновлении/удалении `CompanyBankAccount` у такой Company
- [ ] Входящий `contractor.updated` от 1С **не** триггерит обратную публикацию (петля разорвана через `withoutEvents()` + `Company->fromErp`)
