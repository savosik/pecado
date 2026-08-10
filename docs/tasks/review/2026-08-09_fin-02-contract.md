# fin-02 · Контракт v16.0.0: схемы, AsyncAPI, документация

**Приоритет:** высокий
**Создано:** 2026-08-09
**Эпик:** [fin-00](2026-08-09_fin-00-epic.md)
**Статус:** ✅ выполнена 10.08.2026
**Зависимости:** `fin-01` — **строго после** ответа стороны 1С
**Блокирует:** волну 1 целиком

## Описание

Фиксация мажорной версии контракта после того, как 1С подтвердила выполнимость. Порядок —
канонический для проекта: **JSON Schema → AsyncAPI → MkDocs → changelog → сборка**.

Версия **16.0.0**, а не 15.18.0: из контракта **удаляются** поля, которые 1С сейчас присылает
(`allocations[]`, `payment_schedule[]`, `overdue_details[]`). Это ломающее изменение.

## Что делаем

### JSON Schema (`app/Services/Erp/Schemas/`)

Новые файлы:

| Файл | Ключевое |
|---|---|
| `agreement.created.json`, `agreement.updated.json`, `agreement.deleted.json` | `uuid`, `contractor_uuid`, `organization_uuid`, `number`, `date`, `currency_code`, `settlement_procedure`, `credit_limit`, `deferral_days` |
| `settlement.opening_balance.json` | `as_of_date` (**01.01.2026**), `amount`, `currency_code` |
| `settlement.checkpoint.json` | `as_of_date` (**01.07.2026**), `is_verified`, `amount` — разрез контрагент × организация × валюта |
| `settlement.posted.json` | Шапка: `document_*`, `agreement_*`, `contract_*`, `settlement_object_*`; тело: `entries[]` |
| `settlement.reverted.json` | `document_uuid` + `revision` |
| `payment_schedule.updated.json` | `document_uuid`, `lines[]` с `due_date`, `amount`, **`settled_amount`** |

`entries[]` в `settlement.posted` — `required`: `uuid`, `type`, `amount`. Остальное опционально.
`type` ограничен `enum`; `nature` выводится из `type`, отдельным полем **не** передаётся —
иначе появится возможность прислать несогласованную пару.

⚠️ **Число значений `type` зависит от ответа на вопрос Б** (движения `ЗаказКлиента` — факт
или план). Пока ответа нет, схемы не фиксируем: это 38 % объёма движений.

`settlement_object_kind` — `enum` **открытый**: незнакомый вид сохраняется как `other`
с заполненным `_name`. В отличие от `type`, отбрасывать здесь нельзя — `ОбъектРасчетов`
составного типа, и перечень на стороне 1С заранее неизвестен.

Правки существующих:

| Файл | Правка |
|---|---|
| `payment.created.json`, `payment.updated.json` | Удалить `allocations[]` |
| `shipment.created.json`, `shipment.updated.json` | Удалить `payment_schedule[]` |
| `balance.updated.json` | Удалить `overdue_details[]` на обоих уровнях. Новых уровней НЕ добавляем: ось сверки — контрагент × организация, разрез уже есть с v15.8.0 |

⚠️ `additionalProperties: true` стоит везде, поэтому 1С, продолжающая слать `allocations`,
ошибки валидации не получит — поле просто игнорируется. Это **намеренно**: переключение
не должно ронять сообщения в DLQ.

Регистрация в `ErpMessageValidator::SCHEMA_MAP` (`app/Services/Erp/ErpMessageValidator.php:26-75`)
— в `fin-04`, вместе с хендлерами. Здесь только файлы схем и тест валидатора.

### AsyncAPI (`docs/asyncapi/pecado-erp-integration.yaml`)

- `info.version` → **16.0.0**;
- новый канал `erpInSettlements` (очередь `erp_in.settlements`, routing keys `settlement.*`,
  `payment_schedule.*`) + пять operation-ов;
- `agreement.*` добавляется в существующий канал `erpInContractors`;
- переиспользуемые схемы: `SettlementEntry`, `PaymentScheduleLine`, `AgreementRef`, `SettlementObject`;
- `messageTraits.erpEnvelope` — необязательное `spec_version`;
- ⚠️ починить `info.description`: он ссылается на несуществующий «ACCEPTANCE_CRITERIA v12.0».

### MkDocs (`docs-erp/content/`)

Новые страницы (+ в `mkdocs.yml`):
- `rules/settlements.md` — регистр: типы движений, знак, идемпотентность, полная замена по
  документу, что делать с движением без соглашения;
- `rules/agreements.md` — соглашения, `settlement_procedure`, оговорка «не измерение регистра»;
- `rules/payment-schedule.md` — график с остатками; явно: **сайт больше не раскладывает FIFO**.

Переписать:
- `rules/payments.md` — удалить разделы «Типы документов расшифровки» и «Что закрывает график
  оплаты»: они описывают снятую механику. Платёж остаётся документом;
- `rules/balances.md` — роль контрольной суммы, ось сверки контрагент × организация, удаление `overdue_details`;
- `rules/shipments.md` — убрать раздел про `payment_schedule`.

`changelog.md` — запись `## [16.0.0] — YYYY-MM-DD` с разделами **Удалено** (первым — это мажор),
**Добавлено**, **Изменено**, **Требуется от 1С**, **Порядок миграции**, **Что не меняется**.
Обязательно с цифрами из `fin-00`: почему предыдущая модель не сработала.

Заодно починить расхождения, найденные при проектировании:
- `docs-erp/content/index.md:5` заявляет «Версия протокола: 15.15.0» при фактических 15.17.0;
- `docs/asyncapi/README.md` — таблица версий с единственной строкой 11.0.0 и списком 11 каналов
  вместо 16.

### Сборка

```bash
docker exec pecado-node npm run asyncapi:build
mkdocs build --strict
```

## Edge-кейсы

- **1С ещё шлёт `allocations`, сайт уже на 16.0.0** — основной сценарий переходного периода.
  Поле игнорируется, сообщение обрабатывается. Проверить тестом.
- **Движение с `type` вне перечисления** → сообщение в DLQ. Здесь строго: неизвестный тип
  движения молча пропустить нельзя, это потерянные деньги в балансе.
- **`settled_amount > amount`** — легитимно при переплате по строке. Схема не должна запрещать.
- **`agreement_uuid` пустой** → валидно, движение без соглашения.

## Критерии готовности

- [x] Восемь новых файлов схем созданы, `enum` типов движений закрыт
- [x] Из `payment.*` и `shipment.*` удалены снятые поля; `additionalProperties: true` сохранён
- [x] `balance.updated.json` не содержит `overdue_details[]`, новых уровней не появилось
- [x] `npm run asyncapi:validate` и `asyncapi:build` проходят (0 ошибок)
- [x] `mkdocs build --strict` проходит
- [x] `info.version: 16.0.0`, `info.description` не ссылается на несуществующие документы
- [x] Три новые страницы правил написаны, три существующие переписаны
- [x] `changelog.md` содержит запись 16.0.0 с разделом «Удалено» и порядком миграции
- [x] `index.md` и `asyncapi/README.md` больше не врут о версии
- [x] Тест валидатора `ErpSettlementSchemaTest` — 31 кейс, все зелёные
- [x] Ни одна схема `*.to_erp.json` не изменена

## Что сделано (2026-08-10)

**Схемы** — восемь новых: `settlement.{posted,reverted,opening_balance,checkpoint}.json`,
`payment_schedule.updated.json`, `agreement.{created,updated,deleted}.json`.
Правки пяти существующих: снят `allocations` из платежей, `payment_schedule` из реализаций,
`overdue_details` из баланса. `additionalProperties: true` сохранён везде — присланное
по инерции поле игнорируется, а не роняет документ в DLQ.

**AsyncAPI** 15.17.0 → **16.0.0**: канал `erpInSettlements`, 8 операций, 9 схем payload
(`SettlementEntry` и `PaymentScheduleLine` переиспользуемые). `agreement.*` добавлен в
существующий канал `erpInContractors`. Починен `info.description`, ссылавшийся на
несуществующий ACCEPTANCE_CRITERIA.

**MkDocs** — новые `rules/settlements.md`, `rules/agreements.md`, `rules/payment-schedule.md`;
переписаны `rules/payments.md` и `rules/balances.md`, заменена секция графика в
`rules/shipments.md`. Запись `[16.0.0]` в changelog с разделами «Удалено», «Добавлено»,
«Изменено», «Требуется от 1С», «Порядок миграции», «Не меняется».

**Регистрация** — восемь событий в `ErpMessageValidator::SCHEMA_MAP`. `ErpRevisionGuard`
намеренно **не трогали**: ему нужна модель документа, которой ещё нет, — это `fin-04`.

**Тесты** — `ErpSettlementSchemaTest`, 31 кейс: минимальные и полные payload-ы, закрытый
`type` против открытого `settlement_object_kind`, переплата по строке, график заказа без
построчного остатка, и отдельно — что снятые поля больше не описаны схемой, но сообщения
с ними проходят.

Прогон `Erp|Payment|Shipment|Balance` — 1040 тестов зелёные, регрессий нет.
