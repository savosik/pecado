# Changelog

Формат: [Keep a Changelog](https://keepachangelog.com/)

Payload-схемы: [AsyncAPI](/docs/erp/spec.yaml) | [JSON Schemas](/docs/erp/schemas/)

---

## [13.10.0] — 2026-04-26

> Аудит-метки 1С на номенклатуре. Добавлены опциональные поля
> `erp_created_at` / `erp_updated_at` (ISO-8601 datetime) в события
> `product.created` и `product.updated`. До v13.10 у `Product` была
> только локальная Laravel-метка `created_at` — момент первой обработки
> сообщения от 1С, что после массовой первичной выгрузки делало
> большинство товаров «созданными в один день» и теряло информацию
> о реальном возрасте карточки в 1С.

### Добавлено

- **`product.created` / `product.updated`** (1С → Сайт) — два опциональных поля
  `erp_created_at`, `erp_updated_at` (`format: date-time`, nullable). Семантика —
  момент создания/изменения номенклатуры в 1С. Не required, обратная
  совместимость с v13.9 сохранена.
- **БД:** в `products` добавлены колонки `erp_created_at`, `erp_updated_at`
  (`timestamp NULL`, без индексов). Миграция
  `2026_04_26_*_add_erp_timestamps_to_products`.
- **Модель `Product`** — оба поля в `$fillable`, cast `App\Casts\ErpDatetime`
  (тот же, что и у `Order` / `Shipment` — единая нормализация TZ к Europe/Moscow).
- **Обработчики:**
    - `HandleProductCreated` — пишет обе метки в БД при наличии в payload,
      иначе оставляет `null`. Нормализация TZ — в cast.
    - `HandleProductUpdated` — обновляет только те поля, которые
      присутствуют в payload (через `array_key_exists`); отсутствие ключа =
      существующее значение в БД не перезаписывается.
- **Админка:**
    - Карточка `/admin/products/{id}/edit` — на вкладке «Основные данные»
      добавлен read-only блок «Аудит-метки 1С» с полями «Создано в 1С» /
      «Изменено в 1С» (формат `dd.mm.yyyy HH:MM`). Если поле пустое —
      выводится «—». Поля **не редактируются вручную** — это аудит-метки
      1С, сайт ими не владеет.
- **Документация:**
    - `guides/erp-timestamps-for-1c.md` — гайд расширен примерами
      `product.created` / `product.updated`, добавлена строка про
      номенклатуру в таблицу источников 1С.
    - `rules/catalog.md` — короткие блоки с описанием новых полей в
      бизнес-правилах для `product.created` / `product.updated`, и
      отдельный раздел «Аудит-метки 1С (v13.10)».
- **Регрессионные тесты** в `HandleProductCreatedTest` /
  `HandleProductUpdatedTest` — payload с метками сохраняется в БД;
  payload без меток оставляет колонки `null`; апдейт без `erp_updated_at`
  не теряет ранее сохранённое значение.

### Действия для интеграторов 1С

- В правила конвертации `product.created`, `product.updated` добавить
  выгрузку `erp_created_at` / `erp_updated_at` в ISO-8601 с TZ
  Europe/Moscow. Источник — `Номенклатура.Дата` /
  `ХранилищеИсторииОбъекта` (или регистр версионирования объектов
  справочника номенклатуры).
- Для `product.updated` достаточно передавать только `erp_updated_at`.
- Поля **не обязательны** — старые правила без них продолжат работать,
  колонки на сайте останутся `NULL`. Подробный гайд:
  [«Аудит-метки 1С»](guides/erp-timestamps-for-1c.md).

### Совместимость

- На стороне 1С менять схему сообщений или routing keys **не требуется**.
- Сайт принимает payload без новых полей без ошибок (поля nullable,
  не required).
- В исходящих схемах (Сайт → 1С) поля **не добавлены** — это аудит-метки
  1С, сайт ими не владеет.

---

## [13.9.0] — 2026-04-26

> Унификация исходящих сообщений: `order.created`, `order.updated`,
> `order.deleted` и `return.created` теперь несут `message_id` —
> как и `partner.created`/`contractor.*`. До v13.9 эти сообщения
> уходили без идентификатора, что нарушало принцип идемпотентности
> и оставляло пустую колонку «Message ID» в админке `erp-bus/messages`.

### Изменено

- **Listener `PublishOrderToErp`** — добавлен `message_id` в формате
  `msg-<uuid>` в payload для всех трёх событий (`order.created`,
  `order.updated`, `order.deleted`).
- **Listener `PublishReturnToErp`** — добавлен `message_id` в формате
  `msg-<uuid>` в payload для `return.created`.
- **JSON Schema** [`order.created.to_erp.json`](/docs/erp/schemas/order.created.to_erp.json),
  [`return.created.to_erp.json`](/docs/erp/schemas/return.created.to_erp.json) —
  поле `message_id` (`type: string`, `minLength: 1`) добавлено в `properties`
  и в `required`.
- **AsyncAPI** `ReturnCreatedPayload` — `message_id` добавлен в `required`
  (свойство уже было описано).

### Совместимость

Изменение **обратно несовместимо** для consumer-ов в 1С, валидирующих
схему строго: исходящие `order.*` и `return.created` без `message_id`
теперь невалидны. С 13.9.0 сайт всегда генерирует `message_id`.

---

## [13.8.0] — 2026-04-26

> Двусторонний обмен данными контрагента: сайт теперь публикует
> `contractor.updated` (Сайт → 1С) при локальном изменении уже синхронизированной
> Company и при изменении её банковских реквизитов. До v13.8 такие правки
> оставались только на сайте.

### Добавлено

- **`contractor.updated` (Сайт → 1С)** — новое исходящее событие в очередь
  `erp_out.contractors` (тот же job `PublishContractorToErpJob`, отличие
  в `payload.event`). Триггеры:
    - `CompanyUpdated` для Company с заполненным `erp_id` и изменением
      хотя бы одного из значимых полей (`name`, `legal_name`, `tax_id`,
      `tax_code`, `registration_number`, `okpo_code`, `legal_address`,
      `actual_address`, `phone`, `email`, `country`).
    - Создание / обновление / удаление `CompanyBankAccount` у такой Company
      (Observer `CompanyBankAccountObserver`). В payload уезжает полный
      актуальный набор счетов в `bank_accounts[]`.
- **JSON Schema** [`contractor.updated.to_erp.json`](/docs/erp/schemas/contractor.updated.to_erp.json) —
  обязательные поля `event`, `uuid`, `partner_uuid`, `tax_id`, `name`, `country`.
  `uuid` — `format: uuid`, это `Company.erp_id` (UUID, выданный 1С).
- **AsyncAPI** — добавлены message `ContractorUpdatedFromSite`, payload
  `ContractorUpdatedFromSitePayload`, operation `sendContractorUpdated`.
- **Защита от петли** — три уровня:
    - `Company::withoutEvents()` в `HandleContractorCreated/Updated/Deleted`
      (уже было).
    - Транзиентный флаг `Company->fromErp = true` — выставляется в
      `HandleContractor*`, проверяется в listener `PublishContractorToErp`
      и в `CompanyBankAccountObserver`.
    - `CompanyBankAccount::withoutEvents()` вокруг блока пересборки
      банковских счетов в `HandleContractorCreated/Updated`.

### Изменено

- **Listener `PublishContractorToErp`** — обновлена логика `resolveFromUpdated`:
  для Company с `erp_id` публикует `contractor.updated` при изменении значимых
  полей; для Company без `erp_id` поведение прежнее (`contractor.created` при
  первом заполнении `tax_id`).
- **`HandleContractorCreated/Updated/Deleted`** — после резолва модели
  выставляют `$company->fromErp = true`.
- **`PublishContractorToErpJob`** — лог-сообщения параметризованы по `event`
  (раньше всегда писали «contractor.created опубликован»).

### Откат

- Тот же фиче-флаг `PUBLISH_CONTRACTORS_TO_ERP=false` гасит обе публикации —
  `contractor.created` и `contractor.updated`.

---

## [13.7.0] — 2026-04-26

> Аудит-метки 1С на заказах и реализациях. Добавлены опциональные поля
> `erp_created_at` / `erp_updated_at` (ISO-8601 datetime) в события `order.created`,
> `order.updated`, `shipment.created`, `shipment.updated`. Сайт теперь хранит и
> отображает в админке момент создания/изменения документа на стороне 1С отдельно
> от локальных Laravel-таймстампов.

### Добавлено

- **`order.created` / `order.updated`** (1С → Сайт) — два опциональных поля
  `erp_created_at`, `erp_updated_at` (`format: date-time`, nullable). Семантика —
  момент создания/изменения документа в 1С. Не required, обратная совместимость
  с v13.6 сохранена.
- **`shipment.created` / `shipment.updated`** (1С → Сайт) — те же два поля.
  **Не путать** с бизнес-полем `date` (день отгрузки): `date` — календарная дата
  проведения, аудит-метки — момент действия в 1С с TZ.
- **БД:** в `orders` и `shipments` добавлены колонки `erp_created_at`,
  `erp_updated_at` (`timestamp NULL`, без индексов). Миграция
  `2026_04_26_*_add_erp_timestamps_to_orders_and_shipments`.
- **Модели** `Order`, `Shipment` — оба поля в `$fillable`, cast `datetime`.
- **Часовой пояс приложения** — `config/app.php:timezone` переведён с `UTC`
  на `Europe/Moscow` (с возможностью override через `APP_TIMEZONE`). Все
  таймстампы в БД и в админке отображаются в MSK без явных конверсий.
- **Cast `App\Casts\ErpDatetime`** — единая точка нормализации аудит-меток.
  При записи приводит любой входящий ISO-8601 (`+03:00`, `Z`, `+05:00` и т.п.)
  к `app.timezone` и сохраняет в БД как `Y-m-d H:i:s` без TZ-маркера. Это
  гарантирует, что стенограмма в БД и в админке совпадает с тем, что менеджер
  видит в 1С, независимо от суффикса в payload.
- **Обработчики:**
    - `HandleOrderCreated` / `HandleShipmentCreated` — пишут обе метки в БД
      при наличии в payload, иначе оставляют `null`. Нормализация TZ — в cast.
    - `HandleOrderUpdated` / `HandleShipmentUpdated` — обновляют только те
      поля, которые присутствуют в payload (через `array_key_exists`); отсутствие
      ключа = существующее значение в БД не перезаписывается.
- **Админка:**
    - Карточки `/admin/orders/{id}` и `/admin/shipments/{id}` — строки
      «Создано в 1С» / «Изменено в 1С» (формат `dd.mm.yyyy HH:MM`). Если
      поле пустое — выводится «—».
    - Списки `/admin/orders`, `/admin/shipments` — отдельная колонка
      «Создано в 1С».
- **Документация:**
    - Новая страница [`guides/erp-timestamps-for-1c.md`](guides/erp-timestamps-for-1c.md) —
      инструкция для 1С-разработчиков (какие реквизиты выгружать, формат, чек-лист).
    - `rules/orders.md`, `rules/shipments.md` — короткие блоки с описанием
      новых полей и предупреждением о различии с бизнес-`date` для отгрузок.
- **Регрессионные тесты** в `HandleOrderCreatedTest`,
  `HandleOrderUpdatedTest`, `HandleShipmentCreatedTest`,
  `HandleShipmentUpdatedTest` — payload c метками сохраняется в БД; payload
  без меток оставляет колонки `null`; апдейт без `erp_updated_at` не теряет
  ранее сохранённое значение.

### Действия для интеграторов 1С

- В правила конвертации `order.created`, `order.updated`, `shipment.created`,
  `shipment.updated` добавить выгрузку `erp_created_at` / `erp_updated_at` в
  ISO-8601 с TZ Europe/Moscow. Источник — `Документ.Дата` /
  `ХранилищеИсторииДокумента` (или регистр версионирования объектов).
- Для `*.updated` достаточно передавать только `erp_updated_at`.
- Поля **не обязательны** — старые правила без них продолжат работать,
  колонки на сайте останутся `NULL`. Подробный гайд: [«Аудит-метки 1С»](guides/erp-timestamps-for-1c.md).

### Совместимость

- На стороне 1С менять схему сообщений или routing keys **не требуется**.
- Сайт принимает payload без новых полей без ошибок (поля nullable,
  не required).
- В исходящих схемах (Сайт → 1С) поля **не добавлены** — это аудит-метки
  1С, сайт ими не владеет.

### Документация

- `rules/orders.md`, `rules/shipments.md` — упомянуты новые поля.
- `guides/erp-timestamps-for-1c.md` — отдельный гайд для интеграторов 1С
  (новый раздел навигации «Гайды для 1С»).

---

## [13.6.0] — 2026-04-25

> **BREAKING.** Контрагенты: устранение дублей. UUID контрагента теперь обязателен
> в `order.created`, `shipment.created`, `shipment.updated`. На уровне БД добавлен
> уникальный индекс `(tax_id, tax_code, deleted_at)`. Регрессия инцидента
> 2026-04-25 (3093 дубля контрагентов на dev из-за отсутствия защиты).

### Изменено (BREAKING)

- **`order.created`** — `contractor` и `partner_uuid` теперь required в верхнем
  уровне; внутри `contractor` — `uuid` и `tax_id` required. Сообщения без этих
  полей **отклоняются валидатором** и попадают в `erp_validation_errors`.
- **`shipment.created` / `shipment.updated`** — `contractor_uuid` теперь required.
  `partner_uuid` остаётся optional (отгрузка может быть технической / внутреннее
  перемещение).

### Изменено (внутреннее)

- **`HandleOrderCreated`** и **`HandleContractorCreated`** — переписан блок
  поиска и создания Company:
    - Fallback по `tax_id + tax_code` без фильтра `user_id` (ИНН/КПП юридически
      уникальны).
    - `lockForUpdate` внутри `DB::transaction` против race-condition в окне
      SELECT → INSERT.
    - Soft-deleted Company восстанавливается (`restoreQuietly`), а не дублируется.
    - Backfill `Company.erp_id` если найдено по ИНН/КПП и UUID есть в payload.
- **`HandleContractorCreated`** допускает создание Company с `user_id=NULL`,
  если `partner.created` ещё не дошёл (раньше делал early return).
- **БД:** `companies.tax_code` теперь `NOT NULL DEFAULT ''` (NULL → '' через
  backfill). Добавлен `UNIQUE(tax_id, tax_code, deleted_at)`.

### Добавлено

- **`artisan erp:cleanup-contractor-by-tax-id {tax_id} {--dry-run|--force}`** —
  hard delete всех Company по ИНН + связанных Order. Используется для
  адресной очистки демо-данных и подготовки к UNIQUE-миграции.
- **5 регрессионных интеграционных тестов** в `CompanyDeduplicationTest`.

### Действия для интеграторов 1С

- Все `order.created` события **обязаны** содержать `partner_uuid` и блок
  `contractor` с `uuid` и `tax_id`. Сообщения без этих полей теперь отклоняются.
- Все `shipment.created` / `shipment.updated` события **обязаны** содержать
  `contractor_uuid`.
- Если контрагент в 1С ещё не имеет UUID — сначала отправьте `contractor.created`
  / `contractor.updated` для генерации UUID, потом `order` / `shipment`.

### Совместимость

- Сценарий «1С шлёт `contractor.created` раньше `partner.created`» теперь
  поддерживается: Company создаётся с `user_id=NULL`, привязка к партнёру
  происходит post-factum.
- Soft-delete Company перестаёт быть источником дублей: повторный
  `order.created` или `contractor.created` восстанавливает запись.

### Документация

- `rules/contractors.md` — обновлена стратегия матчинга на v13.6.
- `migrations/v13.6-uniq-companies.md` — инструкция по миграции для прода.

---

## [13.5.0] — 2026-04-25

> Контрагенты: выделена собственная очередь `erp_in.contractors` (с DLQ `erp_dlq.contractors` и отдельным supervisor-консьюмером). Раньше события `contractor.*` шли в общую очередь с партнёрами `erp_in.partners`. Заодно очередь `erp_in.promotions` явно объявлена в топологии.

### Изменено

- **`erp_in.contractors`** — новая выделенная durable-очередь для входящих событий `contractor.created` / `contractor.updated` / `contractor.deleted`. Routing keys `contractor.*` теперь биндятся **только** к ней (с `erp_in.partners` снимаются при первом запуске `artisan rabbitmq:setup` после деплоя).
- **`erp_dlq.contractors`** — собственная DLQ. `x-dead-letter-routing-key` = `contractor.created`.
- **Отдельный supervisor consumer** `erp-contractors-consumer` (1 процесс, connection `rabbitmq-erp-incoming`, `--tries=3 --backoff=15`).
- **Админка `/admin/erp-bus`** теперь показывает `erp_in.contractors`, `erp_dlq.contractors`, `erp_out.contractors`. Удалены отображения мёртвых ссылок `erp_in.segments` / `erp_dlq.segments`.

### Действия для интеграторов 1С

- На стороне 1С менять ничего не нужно: routing keys `contractor.*` остались прежними, exchange `erp.events` тот же. Сообщения автоматически попадут в новую очередь.
- Если в момент деплоя в `erp_in.partners` находились необработанные сообщения `contractor.*` — они дочитываются прежним консьюмером (`erp-partners-consumer`) до полного опустошения, новые сообщения сразу идут в `erp_in.contractors`.

### Совместимость

- 1С продолжает отвечать `contractor.updated` с UUID после `contractor.created` от сайта — но теперь ответ оказывается в `erp_in.contractors`, а не в `erp_in.partners`. Поведение сайта не меняется.
- Документация: `rules/contractors.md`, `migrations/v13.2-contractor-uuid.md`, `tests/phase-2-outbound.md`, `infrastructure.md` приведены в соответствие.

---

## [13.3.0] — 2026-04-25

> `product.created` / `product.updated`: восемь новых опциональных системных полей — физические габариты, вес и аналитические показатели (классификация ABC/XYZ, оборачиваемость, ТН ВЭД)

### Добавлено

- **`weight_gross`** (number, nullable, кг) — вес брутто из `Упаковки.Вес` (первая непомеченная упаковка), сохраняется в `products.weight_gross` `decimal(10, 3)`
- **`weight_net`** (number, nullable, кг) — вес нетто из `Номенклатура.ЕдиницаИзмерения.Вес`, сохраняется в `products.weight_net` `decimal(10, 3)`
- **`width`**, **`height`**, **`depth`** (number, nullable, м) — габариты упаковки из `Упаковки.Ширина/Высота/Глубина`, сохраняются в `products.width/height/depth` `decimal(10, 2)`
- **`hs_code`** (string, nullable, ≤20) — нормализованный код ТН ВЭД из `Номенклатура.КодТНВЭД.Код`, сохраняется в `products.hs_code` `varchar(20)`. Legacy-поле `tnved` остаётся неизменным для обратной совместимости со старыми сценариями выгрузок
- **`abc_xyz`** (string, nullable, ≤5) — класс ABC/XYZ (например, `AX`), сохраняется в `products.abc_xyz` `varchar(5)`
- **`turnover`** (number, nullable) — коэффициент оборачиваемости товара, сохраняется в `products.turnover` `decimal(12, 4)`
- **Вкладка «Габариты и логистика»** в админке (`/admin/products/{id}/edit`, `/admin/products/create`) с тремя секциями: «Вес», «Габариты упаковки», «Классификация»

### Семантика

- Все восемь полей **опциональны** в обеих событиях. В `product.created` отсутствие поля → `null`
- В `product.updated` сохраняется обычная для протокола семантика частичного обновления (как у `description_html`, `is_marked`): отсутствие поля → значение в БД не меняется, передача (включая `null`) → перезаписываем
- Числовые значения должны быть `≥ 0`. Отрицательные значения отбрасываются и сохраняются как `null`

### Миграция (1С)

- Версия минорная (13.2 → 13.3), обмен **обратно совместим**: старые payload-ы без новых полей продолжают обрабатываться корректно (новые колонки получат `null`)
- В правилах конвертации `product.created`/`product.updated` 1С добавить выгрузку:
    - вес брутто и габариты — из табличной части `Упаковки` (первая непомеченная упаковка)
    - вес нетто — из `Номенклатура.ЕдиницаИзмерения.Вес` (если задана единица измерения с весом)
    - `hs_code` — из реквизита `Номенклатура.КодТНВЭД.Код`
    - `abc_xyz`, `turnover` — из аналитических расчётов 1С
- Если значение в 1С пустое — поле в payload **не передавать** (или передать `null`)

### Критерии приёмки

- [ ] `product.created` с восемью полями сохраняет значения в соответствующие колонки `products`
- [ ] `product.created` без новых полей — все восемь колонок `products` получают `null`
- [ ] `product.updated` с подмножеством полей обновляет только переданные колонки
- [ ] `product.updated` без новых полей не меняет уже сохранённые значения
- [ ] `product.updated` с `null` в любом из восьми полей очищает соответствующую колонку
- [ ] В админке вкладка «Габариты и логистика» отображает текущие значения, валидирует `numeric|min:0` для чисел и `string|max:20` / `max:5` для строк
- [ ] Миграция `2026_04_25_*_add_dimensions_and_classification_to_products_table` накатывается на dev без потерь данных

---

## [13.2.0] — 2026-04-24

> Контрагенты: выравнивание workflow с партнёрами — добавлены `contractor.updated`, `contractor.deleted`, исходящее направление `contractor.created` (Сайт → 1С) через новую очередь `erp_out.contractors`. UUID становится основным идентификатором контрагента во всех сообщениях; ИНН остаётся fallback на переходный период

### Добавлено

- **Новый канал `erp_out.contractors`** — сайт публикует `contractor.created` при первом заполнении `tax_id` у Company. 1С слушает очередь напрямую через AMQP (у сайта нет воркера)
- **`contractor.updated` (1С → Сайт)** — частичное обновление атрибутов контрагента. Основной сценарий: 1С возвращает назначенный UUID после обработки `contractor.created` от сайта. Обработчик `HandleContractorUpdated` находит Company по `erp_id`, fallback по `tax_id + user_id`, проставляет `erp_id` ленивым backfill-ом
- **`contractor.deleted` (1С → Сайт)** — soft-delete Company. Достаточно `uuid` или `tax_id`. Обработчик `HandleContractorDeleted`
- **Опциональные UUID-поля в существующих payload-ах:**
    - `order.created.contractor.uuid` — UUID контрагента (обе стороны)
    - `shipment.created.contractor_uuid`, `shipment.updated.contractor_uuid` — UUID контрагента; `partner_uuid` — для резолва user_id при fallback
    - `balance.updated.contractors[].uuid` — UUID контрагента; заполняется в `ContractorBalance.contractor_uuid` ленивым backfill
- **Listener `PublishContractorToErp`** + Job `PublishContractorToErpJob` — публикация при создании Company с непустым `tax_id` или при первом заполнении `tax_id` на апдейте. Откладывается, если у партнёра нет `User.erp_id`; догон через `PublishUserToErp` после выгрузки партнёра
- **Env-флаг `PUBLISH_CONTRACTORS_TO_ERP`** (default `true`) — быстрый откат publisher без деплоя

### Исправлено

- **`HandleShipmentCreated` / `HandleShipmentUpdated`**: поиск Company без фильтра `user_id` мог найти чужую Company с тем же ИНН. Теперь поиск всегда требует `user_id` (резолв через `partner_uuid`), без него выполняется только UUID-поиск или shipment сохраняется с `company_id = null`
- **`HandleOrderCreated`**: при fallback-поиске Company по `tax_id` добавлен фильтр `user_id`; при совпадении ИНН и наличии UUID в payload — backfill `Company.erp_id`

### Изменено

- **Стратегия матчинга контрагента во всех входящих обработчиках** — единый приоритет: `Company.erp_id = uuid` → `tax_id + user_id` → создать (только в `order.created` и `contractor.created`). Fallback-поиск без `user_id` больше не выполняется
- **`ContractorBalance.contractor_uuid`** — поле было в БД, но не использовалось. С v13.2 заполняется при приёме `balance.updated`, если в payload передан `contractors[].uuid`

### Причина

- До v13.2 контрагент идентифицировался только по ИНН, который не гарантирован уникальный и может быть изменён менеджером в 1С после создания контрагента на сайте. Типичный кейс: пользователь создал контрагента с опечаткой в ИНН (лишняя цифра), заказ ушёл в 1С, менеджер исправил ИНН в 1С → все последующие реализации и балансы приходили с новым ИНН и не привязывались к Company на сайте. Переход на UUID-идентификацию по аналогии с партнёрами закрывает этот класс проблем
- Security: поиск Company в `HandleShipmentCreated` без фильтра `user_id` — потенциальная утечка между партнёрами с совпадающим ИНН; устраняется тем же релизом

### Миграция (1С)

- Создать durable queue `erp_out.contractors` в RabbitMQ **до** выкатки consumer 1С. Очередь declared сайтом при первой публикации, но лучше объявить заранее
- 1С должна слушать `erp_out.contractors` и обрабатывать `contractor.created` от сайта: матчинг по `tax_id` в рамках партнёра (`partner_uuid`), при создании — генерация собственного UUID и отправка `contractor.updated` в `erp_in.partners` с этим UUID
- Добавить опциональный `contractor.uuid` в `order.created` (обе стороны), `contractor_uuid` + `partner_uuid` в `shipment.created` / `shipment.updated`, `uuid` в `balance.updated.contractors[]`. Все поля опциональны — старое поведение сохраняется до полной миграции
- Поле `uuid` в исходящем `contractor.created` от сайта — локальный UUIDv4 для корреляции; 1С генерирует собственный UUID независимо

### Критерии приёмки

- [ ] Сайт публикует `contractor.created` в `erp_out.contractors` при создании Company с непустым `tax_id`
- [ ] Публикация откладывается, если у партнёра нет `User.erp_id`; после получения UUID партнёром Company догоняется автоматически
- [ ] Сайт принимает `contractor.updated` и привязывает `erp_id` к существующей Company (backfill по `tax_id + user_id`)
- [ ] Сайт принимает `contractor.deleted` и делает soft-delete Company
- [ ] `shipment.created` с `contractor_uuid` находит Company по UUID, не по `tax_id`
- [ ] `shipment.created` без `partner_uuid` и без `contractor_uuid` не находит Company даже при совпадении `tax_id` (регрессия security-fix)
- [ ] `balance.updated` с `contractors[].uuid` заполняет `ContractorBalance.contractor_uuid` и находит Company по UUID
- [ ] `order.created` с `contractor.uuid` находит Company по UUID; без UUID работает fallback по `tax_id + user_id`

---

## [13.1.1] — 2026-04-23

> Фикс: ERP-обработчики игнорируют `HiddenScope` — скрытый товар снова можно обновлять/включать из 1С

### Исправлено

- `HandleProductUpdated`, `HandlePriceUpdated`, `HandleStockUpdated`, `HandleOrderCreated`, `HandleOrderUpdated`, `HandleShipmentCreated`, `HandleShipmentUpdated`, `ProcessIndividualPricesFile` — поиск товара по `external_id` теперь выполняется с `Product::withoutGlobalScopes()`
- Ранее глобальный `HiddenScope` (Eloquent) исключал товары с `hidden = true` из любых запросов `Product::query()` на сайте. Как побочный эффект, после того как 1С присылала `product.updated` с `hidden: true`, все последующие сообщения по этому товару (включая `product.updated` с `hidden: false`, обновления цен, остатков, позиций заказов и реализаций) не находили товар и тихо игнорировались с warning `товар не найден`, но при этом попадали в `erp_processed_messages` (идемпотентность) и логировались в `erp_bus_messages` со статусом `success`

### Причина

- 1С — мастер-каталог, бэкофисные обработчики обязаны «видеть» все товары, включая скрытые. Иначе скрытый товар навсегда становится «мёртвой зоной»: обратно включить через `product.updated` его нельзя, обновлять остатки и цены тоже нельзя
- Видимость в публичном каталоге регулируется полем `products.hidden`, а не фильтрацией ERP-обработчиков

---

## [13.1.0] — 2026-04-23

> `product.created` / `product.updated`: появилось поле `description_html` — HTML-версия описания товара

### Добавлено

- **`product.created.description_html`** — HTML-версия описания (rich-text). Полностью перезаписывает `products.description_html` на сайте. `null` или пустая строка очищают поле
- **`product.updated.description_html`** — частичное обновление: поле обрабатывается только если присутствует в payload. Если передано (включая `null` / пустую строку) — перезаписывает `products.description_html`; отсутствие поля оставляет текущее значение

### Причина

- На сайте уже есть колонка `products.description_html`, которую редактор заполняет в админке, и она имеет приоритет над `description` в выгрузках маркетплейсов. До v13.1 1С могла передавать только `description` (plain-text), из-за чего при синхронизации карточки с 1С HTML-версия не обновлялась
- Добавление отдельного поля вместо переиспользования `description` сохраняет разделение plain-text / rich-text и совместимость со старым поведением (обработчик по-прежнему пишет `description` в `products.description`)

### Миграция (1С)

- Если в номенклатуре 1С есть rich-text описание — передавать его в `product.created.description_html` / `product.updated.description_html`
- Если HTML-описания нет — поле можно не передавать; сайт сохранит текущее значение `products.description_html`

### Критерии приёмки

- [ ] `product.created` с `description_html: "<p>…</p>"` → `products.description_html` содержит переданный HTML
- [ ] `product.updated` с `description_html` → `products.description_html` перезаписан
- [ ] `product.updated` без поля `description_html` → `products.description_html` не изменился
- [ ] `product.updated` с `description_html: null` → `products.description_html` очищен

---

## [13.0.0] — 2026-04-22

> Атрибуты товаров: смена семантики с **merge** на **full-replace** + выравнивание `product.updated` handler-а с `product.created` (retry на deadlock, `insertOrIgnore` на `attribute_category`)

### Изменено (BREAKING для 1С)

- **`product.created.attributes` и `product.updated.attributes`** — новая семантика **full-replace**. Массив `attributes` теперь считается полным актуальным списком атрибутов товара; записи в `product_attribute_values`, отсутствующие в массиве, **удаляются** у товара
- **`product.updated`** — правила поля `attributes`:
    - **отсутствие поля** → состав атрибутов не трогаем (поведение не изменилось)
    - **`attributes: []`** → удалить все атрибуты у товара (**новое поведение**, раньше игнорировалось)
    - **`attributes: [...]`** → полная замена (раньше выполнялся merge — атрибуты, пропавшие из payload, оставались у товара)
- **`product.created`** — при каждом создании 1С обязана передавать **полный набор атрибутов**

### Добавлено

- **`HandleProductUpdated`** — retry на deadlock/1062 (`retry([100, 250], ...)`) и атомарный `insertOrIgnore` для привязки атрибутов к категории вместо `syncWithoutDetaching`. Под 6 параллельными воркерами `erp_in.catalog` это снимает гонки на `attribute_category`, `attribute_values.(attribute_id, value)` и `attributes.slug`

### Причина

- Под merge-семантикой атрибут, удалённый в 1С, оставался у товара на сайте навсегда — это накапливало тихое рассогласование в каталоге без видимых ошибок
- 1С — мастер-каталог, поэтому проще гарантировать передачу полного списка, чем вести в протоколе отдельное поле `attributes_removed[]`: меньше состояний, меньше ошибок на стороне 1С
- `HandleProductUpdated` при массовой выгрузке в 6 потоков регулярно ловил `Duplicate entry` и deadlock на общих справочных таблицах; `HandleProductCreated` уже был защищён retry с момента релиза 12.x — выравнивание закрывает оставшуюся дыру

### Миграция (1С)

- При записи номенклатуры (создание или изменение состава/значений атрибутов) 1С обязана формировать `attributes[]` как полный актуальный список всех атрибутов товара, а не только изменённых
- Если в рамках `product.updated` атрибуты не трогаются — поле `attributes` **не передавать**. Не передавать пустой массив, если только не требуется удалить все атрибуты у товара

### Критерии приёмки

- [ ] 1С: при создании товара с атрибутами А, Б, В — на сайте у товара ровно эти три атрибута
- [ ] 1С: при обновлении товара с `attributes: [А]` (был А, Б, В) — на сайте у товара остаётся только А; Б и В удалены из `product_attribute_values`
- [ ] 1С: `product.updated` без поля `attributes` — состав атрибутов товара на сайте не меняется
- [ ] 1С: `product.updated` с `attributes: []` — на сайте все связи товара с атрибутами удалены
- [ ] Интеграционный тест: два параллельных `product.updated` с общим атрибутом не дают 1062/deadlock

---

## [12.15.0] — 2026-04-21

> Consumer внешних остатков: сайт забирает из `external.remains_for_website` только остатки по складу «Тюмень Основной»

### Добавлено

- **`ExternalRemainsJob`** (`app/Queue/Jobs/ExternalRemainsJob.php`) — потребитель очереди `external.remains_for_website`. Парсит envelope ESB (`{service, uid, event: {name, payload}}`), маршрутизирует `product.quantity.updated` на handler, обеспечивает идемпотентность через `erp_processed_messages` с префиксом `external-remains:`
- **`HandleExternalProductQuantityUpdated`** — в `payload.remains[]` отфильтровывает только склад «Тюмень Основной» (UUID из `config/erp.php`), пишет в `product_warehouse.quantity` значение `max(0, quantity - reserve)`. Прочие склады и `organization_remains[]` игнорируются
- **JSON Schema** `app/Services/Erp/Schemas/external.product_quantity_updated.json` — минимальная (envelope + payload.uid/code + remains[]), описывает только поля, которые сайт реально читает
- **Supervisor-процесс** `[program:external-remains-consumer]` в `docker/supervisor/conf.d/worker.conf` (1 процесс, `tries=3`, `backoff=30`, отдельный лог)
- **Config** `erp.external_remains.tyumen_warehouse_uuid` + ENV `EXTERNAL_REMAINS_TYUMEN_WAREHOUSE_UUID` (дефолт `f8083799-0838-11e0-a1ea-505054503030`)
- **Раздел документации** [Внешние остатки (ESB)](rules/external-remains.md) с описанием протокола, алгоритма и ограничений

### Причина

- На момент релиза 12.14.0 очередь `external.remains_for_website` была создана, shovel тянул сообщения с ESB, но потребителя не было — сообщения копились и удалялись по TTL (3 дня), остатки центрального склада не попадали в БД сайта
- Из 12 складов, по которым 1С присылает остатки, сайт Pecado торгует только одним — «Тюмень Основной». Фильтрация по UUID склада на стороне consumer-а проще, чем настраивать routing на стороне ESB (нет headers-exchange, топология fanout)
- Доступный остаток = `quantity - reserve`, чтобы не показывать в каталоге зарезервированные под чужие заказы позиции

### Ограничения

- Поле `organization_remains[]` игнорируется: на сайте нет справочника юрлиц-продавцов, и названия организаций в сообщениях не передаются (только UUID). Если в будущем потребуется разрез по ООО Андрей — добавим отдельной миграцией и фильтром
- Если товар не найден ни по `payload.uid`, ни по `payload.code` — сообщение молча пропускается (каталог ведёт 1С Pecado, не ESB)

---

## [12.14.0] — 2026-04-21

> Инфраструктура: shovel с московского ESB для внешних остатков, persistent-volume и автопровижининг пользователей RabbitMQ

### Добавлено

- **Fanout-обменник `external.remains`** и две durable-очереди `external.remains_for_website`, `external.remains_for_erp` — автоматически создаются командой `php artisan rabbitmq:setup`
- **Dynamic RabbitMQ Shovel `moscow-remains`** — тянет сообщения из очереди `remains_for_moscow` на ESB (`93.125.18.73:5672`) и публикует их в `external.remains`. Каждое сообщение размножается в обе очереди через fanout. Параметры: `ack-mode: on-confirm`, `reconnect-delay: 5`, `delete-after: never`
- **Плагины `rabbitmq_shovel` и `rabbitmq_shovel_management`** — включены через смонтированный файл `docker/rabbitmq/enabled_plugins`
- **Persistent-volume `rabbitmq-data`** — Mnesia теперь переживает рестарт контейнера (раньше всё было эфемерно)
- **Идемпотентный провижининг пользователей** в `deploy-dev.yml` (шаг `[5.2/7]`): `pecado_admin`, `pecado_app`, опционально `erp_1c` — создаются через `rabbitmqctl` из паролей в `/srv/pecado/.env`. Дефолтный `guest` удаляется
- **Новые env-переменные**: `MOSCOW_ESB_AMQP_URI`, `MOSCOW_ESB_SRC_QUEUE`, `MOSCOW_ESB_SHOVEL_PREFETCH`, `MOSCOW_ESB_SHOVEL_RECONNECT_DELAY`, `RABBITMQ_ERP_USER`, `RABBITMQ_ERP_PASSWORD`, `EXTERNAL_REMAINS_TTL_MS` (см. `.env.example`)
- **Policy `external-remains-ttl`** (`pattern=^external\.remains_for_.*$`, `message-ttl=259200000`) — сообщения в fanout-очередях `external.remains_for_{website,erp}` удаляются через 3 дня, чтобы при простое потребителей не забивали диск. Регистрируется через Management API в `rabbitmq:setup`

### Причина

- Остатки центрального склада Москвы приходят не из 1С:КА2 Pecado, а через внешнюю ESB-шину. Нужен был независимый канал, который параллельно кормит и сайт, и локальную 1С — отсюда fanout-раздвоение
- Без persistent-volume любой рестарт контейнера терял все очереди и пользователей. Пара «volume + автопровижининг» делает деплой стабильно повторяемым
- Shovel-плагин выбран вместо самописного consumer-а: он встроен в RabbitMQ, обеспечивает `ack-mode: on-confirm`, автоматический reconnect и перемещает ответственность за reliable-доставку на уровень брокера

### Потребители

- `external.remains_for_website` и `external.remains_for_erp` на момент релиза **не имеют потребителей** — сообщения накапливаются. Написание consumer-а на стороне сайта (`Stock`-домен) и подключение AMQP-чтения со стороны 1С — отдельные задачи
- TTL на pecado-rabbitmq не установлен; ограничением является только дисковое место. На ESB TTL 3 дня — если и источник и целевая очередь долго лежат без прочтения, старые сообщения источника уходят по TTL

---

## [12.13.0] — 2026-04-21

> Возвраты покупателя переводятся с привязки к заказам на привязку к реализациям; в payload `return.created` добавляется цена позиции

### Изменено (BREAKING)

- **`return.created` (Сайт → 1С)** — поле `order_uuid` **удалено полностью** из корня сообщения. Возврат больше не связан с заказом как с целым, а привязан к позициям реализаций на уровне каждого `items[]`
- **`items[]` в `return.created`** — новые обязательные поля: `shipment_uuid` (uuid реализации), `shipment_number` (человекочитаемый номер), `price` (snapshot цены из `ShipmentItem.price`), `currency_code` (валюта реализации). Добавлено опциональное `subtotal = price × quantity`
- **Схема БД сайта** — в `return_items` добавлены колонки `shipment_item_id` и `shipment_id` (обе NOT NULL, FK `RESTRICT`). Колонки `return_items.order_id` и `returns.order_id` **удалены**
- **Все существующие возвраты в таблицах `returns` / `return_items` удалены** миграцией. Backfill не предусмотрен — цепочку `order_id → order_uuid → ShipmentItem` в общем случае восстановить однозначно нельзя
- **`PublishReturnToErp`** — собирает payload из `ReturnItem → ShipmentItem → Shipment`; поля `order_uuid` больше не публикуются

### Миграция (1С)

- 1С обязана начать читать `items[].shipment_uuid` и `items[].shipment_number` вместо корневого `order_uuid`. Привязка возврата к конкретной реализации однозначна через UUID
- До выката правки на стороне 1С все исходящие `return.created` от сайта будут падать валидацией на стороне 1С (новые обязательные поля)
- Рекомендуемый порядок релиза: сначала 1С учится принимать новую схему (допускает отсутствие `order_uuid`, требует `shipment_uuid/number/price/currency_code`), затем катится сайт

### Причина

- Возвращают то, что физически отгружено. Привязка возврата к заказу, а не к реализации, не отражала реальной бизнес-модели — заказ мог быть раздроблен на несколько реализаций с разными ценами и датами, и возврат «по заказу» ломал проведение в 1С
- `ShipmentItem.price` уже хранит актуальную цену с учётом скидок. Без передачи этой цены в 1С бухгалтерия возвратов неизбежно расходилась с реализацией
- Обязательный `shipment.number` (v12.12) гарантирует, что каждая реализация имеет ERP-номер, пригодный для человекочитаемого сопоставления

---

## [12.12.0] — 2026-04-21

> Поле `number` становится обязательным в `shipment.created` и `shipment.updated`

### Изменено (BREAKING)

- **`number` добавлено в `required`** у схем `ShipmentCreatedPayload` и `ShipmentUpdatedPayload` (AsyncAPI) и в `shipment.created.json` / `shipment.updated.json` (JSON Schema)
- **Тип `number`** уточнён с `["string", "null"]` на `string` с `minLength: 1` — null-значения и пустые строки больше не принимаются
- **Поведение валидатора** — `ErpMessageValidator` отклоняет сообщения без `number`; сообщение попадает в `failed` статус `erp_bus_messages`, реализация не создаётся/не обновляется
- **Бизнес-правило** — см. `docs-erp/content/rules/shipments.md` (v12.11)

### Миграция (1С)

- 1С обязана начать заполнять поле `number` в публикуемых payload'ах `shipment.created` и `shipment.updated` непустой строкой (например, `"29УТ-003413"`)
- До выката правки на стороне 1С все входящие `shipment.*` сообщения будут падать с ошибкой валидации. Восстановление — переотправка событий 1С после релиза
- Существующие реализации с `erp_number = null` в БД сайта актуализируются при следующем `shipment.updated` от 1С

### Причина

- Без ERP-номера покупатель в ЛК видит технический `id` сайта, менеджер в 1С — ERP-номер. Это ломает коммуникацию клиент-менеджер при обсуждении конкретной реализации. См. канбан-задачу «в отгрузках нужно показывать ERP number (если есть) вместо id»

---

## [12.11.0] — 2026-04-21

> **US-16:** Управление промо-флагами товаров (новинка / бестселлер / ликвидация) через события `promotion.*`

### Добавлено

- **Новые события `promotion.created` / `promotion.updated` / `promotion.deleted`** (1С → Сайт) — отдельная очередь `erp_in.promotions`, routing keys `promotion.*`
- **Payload** содержит `uuid` промо-группы, `type` (`new` / `bestseller` / `liquidation`) и массив `items[]` из `{uuid товара}`
- **JSON Schema** — `app/Services/Erp/Schemas/promotion.created.json`, `promotion.updated.json`, `promotion.deleted.json`
- **AsyncAPI** — канал `erpInPromotions`, сообщения `PromotionCreated/Updated/Deleted`, схемы `PromotionCreatedPayload/PromotionUpdatedPayload/PromotionDeletedPayload/PromotionItem/PromotionType`
- **Модель данных сайта** — новая таблица `erp_promotions` (`uuid`, `type enum`) и pivot `erp_promotion_product`, модель `App\Models\ErpPromotion`
- **Обработчики** — `HandlePromotionCreated`, `HandlePromotionUpdated`, `HandlePromotionDeleted`, общий сервис пересчёта флагов `RecalculateProductPromoFlags`
- **Агрегация на товаре** — колонки `products.is_new`, `is_bestseller`, `is_liquidation` пересчитываются как `EXISTS()` по привязкам. Товар может быть в нескольких промо-группах одного `type` одновременно
- **Supervisor-воркер** — `erp-promotions-consumer` на очереди `erp_in.promotions`
- **Документация** — `docs-erp/content/rules/promotions.md` с бизнес-правилами и критериями приёмки

### Примечания

- События `promotion.*` существуют **параллельно** уже имеющейся сущности `Promotion` (маркетинговые акции/страницы сайта). Это разные модели с разным назначением: `Promotion` — витрина, `ErpPromotion` — агрегатор флагов из 1С
- Идемпотентность: `promotion.created` с тем же `uuid` ведёт себя как `promotion.updated` (upsert состава)

---

## [12.10.0] — 2026-04-21

> Разделение JSON Schema для `shipment.created` и `shipment.updated`

### Изменено

- **Отдельная JSON Schema для `shipment.updated`** — создан файл `app/Services/Erp/Schemas/shipment.updated.json`. Ранее `shipment.updated` валидировался схемой `shipment.created.json` (одинаковая структура)
- **AsyncAPI** — общий `ShipmentPayload` разделён на `ShipmentCreatedPayload` и `ShipmentUpdatedPayload`. Поле `event` теперь `const` у каждой схемы (вместо `enum`)
- **`ErpMessageValidator`** — в `SCHEMA_MAP` для ключа `shipment.updated` используется `shipment.updated.json`

### Примечания

- Структура payload-ов `shipment.created` и `shipment.updated` **остаётся идентичной**; разделение выполнено на вырост — для независимой эволюции схем при появлении у `shipment.updated` собственных полей
- Логика обработчиков `HandleShipmentCreated` / `HandleShipmentUpdated` не менялась

---

## [12.9.0] — 2026-04-21

> Признак маркированного товара `is_marked` в `product.created` и `product.updated`

### Добавлено

- **`is_marked`** — новое поле boolean в `product.created` и `product.updated` (1С → Сайт). Признак маркированного товара (обязательная маркировка «Честный знак»). Сохраняется в колонке `products.is_marked`
- Для `product.created` отсутствие поля трактуется как `false` (значение по умолчанию)
- Для `product.updated` работает семантика частичного обновления: поле применяется только если присутствует в payload
- Обновлены JSON Schema (`app/Services/Erp/Schemas/product.created.json`, `product.updated.json`) и AsyncAPI (`ProductCreatedPayload`, `ProductUpdatedPayload`)
- Обработчики `HandleProductCreated` и `HandleProductUpdated` прокидывают `is_marked` в модель `Product`

---

## [12.8.0] — 2026-04-17

> Регистрация пользователя как триггер `partner.created`, поля `is_active` и `comment` в исходящем направлении

### Изменено

- **Триггер `partner.created` (Сайт → 1С)** — событие теперь публикуется при регистрации (`UserCreated`), а не при активации (`UserUpdated`)
- **`is_active`** — новое поле boolean в `partner.created.to_erp`. При регистрации всегда `false` (пользователь в статусе PROCESSING до проверки менеджером)
- **`comment`** — новое поле string|null в `partner.created.to_erp`. Содержит секретную ссылку на превью-страницу пользователя для менеджеров (`/preview/user/{token}`)

### Добавлено

- **Превью-страница пользователя** (`/preview/user/{token}`) — публичная страница только по секретной ссылке (view_token). Показывает данные пользователя и анкету (если заполнена). Предназначена для менеджеров без доступа к админке
- **`view_token`** — новое поле в таблице `users`. Генерируется автоматически при создании, длина 48 символов
- **Регион по умолчанию** — при регистрации пользователю автоматически назначается первый регион (по id)
- **Middleware `EnsureUserIsNotBlocked`** — заблокированные пользователи (статус BLOCKED) немедленно разлогиниваются при любом запросе с сообщением об ошибке
- **Каталог в гостевом режиме** — пользователи в статусе PROCESSING видят каталог как гости (без цен, без корзины, без статуса наличия)

---

## [12.7.4] — 2026-04-16

> Нижняя граница скидок снята — наценка не ограничена

### Изменено

- **Все поля скидок** (`discount_percent`, `auto_discount_percent`, `manual_discount_percent`) — ограничение `minimum` удалено полностью; отрицательное значение означает наценку произвольного размера

## [12.7.3] — 2026-04-16

> Отрицательные скидки разрешены во всех схемах

### Изменено

- **`discount_percent` в `order.created` / `order.updated` / `order.created.to_erp`** — добавлено `minimum: -100` в JSON Schema и AsyncAPI
- **`auto_discount_percent` / `manual_discount_percent` в `shipment.created`** — `minimum` изменён с `0` на `-100`; описания обновлены (отрицательное значение — наценка)

---

## [12.7.2] — 2026-04-16

> Новый статус заказа `deleted`

### Изменено

- **Статусы заказов** — добавлен новый статус `deleted` со значением «Удалён»
- **`order.deleted`** — теперь переводит заказ в статус `deleted`, а не `closed`
- **JSON Schema / AsyncAPI / UI** — enum, маппинги, валидация и отображение статусов обновлены под `deleted`

---

## [12.7.1] — 2026-04-16

> Поддержка отрицательного `discount_percent` в заказах

### Изменено

- **`discount_percent` в `order.created` / `order.updated`** — убрано ограничение `minimum: 0` в JSON Schema для входящего и исходящего направления
- **Семантика `discount_percent`** — положительное значение означает скидку, отрицательное — наценку, `0` — отсутствие корректировки
- **Источник истины для суммы позиции** — зафиксирован как `final_price`; отрицательный `discount_percent` не считается ошибкой
- **UI и история изменений заказа** — формулировки изменены с «Скидка» на нейтральную «Корректировка цены»

---

## [12.7.0] — 2026-04-15

> Удаление атрибутов «Наша компания» и «Координаты» у контрагентов

### Удалено

- **Поля `latitude` / `longitude`** — убраны из модели `Company`, JSON Schema (`order.created.json`, `order.created.to_erp.json`), AsyncAPI (`OrderContractor`) и payload исходящего `order.created`
- **Поле `is_our_company`** — убрано из модели `Company`, админки и API
- **Компонент `YandexMapPicker`** — удалён из админки (использовался только для координат контрагентов)

---

## [12.6.0] — 2026-04-15

> Логирование сообщений ERP-шины

### Добавлено

- **Таблица `erp_bus_messages`** — хранение полного payload всех входящих и исходящих RabbitMQ-сообщений с direction, event, status, error_message
- **Модель `ErpBusMessage`** — Eloquent-модель с scopes (incoming, outgoing, failed)
- **Сервис `ErpBusLogger`** — логирование входящих/исходящих сообщений, управляется через `ERP_BUS_LOGGING_ENABLED`
- **Конфиг `config/erp.php`** — переменная `bus_logging_enabled` (по умолчанию выключено)
- **Хуки в `ErpIncomingJob`** — логирование успешных, невалидных и ошибочных входящих сообщений
- **Хуки в `PublishOrderToErpJob`, `PublishReturnToErpJob`, `PublishUserToErpJob`** — логирование исходящих сообщений
- **Admin UI: Лог сообщений** (`/admin/erp-bus/messages`) — список с фильтрами (направление, событие, статус, дата, поиск)
- **Admin UI: Просмотр сообщения** (`/admin/erp-bus/messages/{id}`) — интерактивный JSON-вьювер с подсветкой синтаксиса, сворачиванием веток, копированием
- **Artisan-команда `erp:cleanup-messages`** — автоматическая очистка логов старше N дней (по умолчанию 30)

---

## [12.5.0] — 2026-04-15

> Уточнение спецификации: order.type и partner.client_status

### Изменено

- **Поле `type`** удалено из обязательных (`required`) в `order.created` (1С → Сайт) — 1С не знает о типах заказов (order/preorder), это внутреннее понятие сайта. При отсутствии поля сайт использует значение по умолчанию `"order"`. Исходящее направление (Сайт → 1С) не затронуто.
- **Поле `client_status`** в `partner.created` и `partner.updated` — убрано ограничение `enum`. Статус партнёра теперь произвольная строка, резолвится через `ClientStatus.external_id`. Примеры: `silver`, `gold`, `diamond`, `individual`.

---

## [12.4.0] — 2026-04-15

> Типизация дат и поддержка date-time для атрибутов товаров

### Изменено

- **Атрибуты товаров** (`product.created`, `product.updated`) — добавлен новый `value_type`: `date-time`. Позволяет передавать даты со временем (в БД сохраняется в `datetime_value`).
- **Строгая типизация дат** — поля `date`, `timestamp`, `updated_at` теперь строго регламентированы `format: date-time` (ISO 8601) во всех JSON схемах (`order.created`, `balance.updated` и т.д.).

## [12.3.1] — 2026-04-15

> Синхронизация номера заказа/возврата из 1С

### Добавлено

- **`erp_number`** — новое поле в таблицах `orders`, `returns` и `shipments` для хранения номера из 1С
- **`order.updated`** — поле `number` добавлено в payload; сохраняется как `erp_number`
- **`order.created` (1С → Сайт)** — `number` из payload дублируется в `erp_number`
- **`return.updated`** — поле `number` добавлено в payload; сохраняется как `erp_number`
- **`shipment.created` / `shipment.updated`** — поле `number` добавлено в payload; сохраняется как `erp_number`
- Пользователь видит номер из 1С для коммуникации с менеджером

---

## [12.3.0] — 2026-04-15

> Источник: рефакторинг статусов заказов

### Изменено

- **Статусы заказов** — оставлены только 4: `pending`, `confirmed`, `ready_to_ship`, `closed`
- Удалены статусы: `processing`, `completed`, `shipped`, `delivered`, `cancelled`
- `READY_TO_SHIP` — значение исправлено с `к_отгрузке` на `ready_to_ship`
- Маппинг 1С → Сайт обновлён:
    - Не согласован → `pending`
    - К выполнению → `confirmed`
    - К отгрузке → `ready_to_ship`
    - Закрыт → `closed`
- `order.deleted` — заказ теперь получает статус `closed` вместо `cancelled`
- **`contractor_uuid` удалён** из протокола обмена (`balance.updated`, `ContractorBalance`) — матчинг контрагентов исключительно по ИНН (`contractor_inn`)
- Версия спецификации обновлена до v12.3.0
- Добавлены тест-кейсы: order.updated → ready_to_ship, order.updated → closed

---

## [12.2.0] — 2026-04-15

> Источник: управление активностью категорий из 1С

### Добавлено

- **Поле `is_active`** (boolean) в `category.created` / `category.updated` — 1С управляет активностью категорий на сайте
- Тест-кейсы 1.2а, 1.2б, 1.2в — деактивация, создание неактивной, обратная совместимость

### Удалено

- **Поле `is_group`** из протокола обмена категорий — внутренний атрибут сайта, не должен передаваться из 1С

### Изменено

- Версия спецификации обновлена до v12.2.0
- Обработчик `HandleCategoryCreated` — читает `is_active` из payload, перестал читать `is_group` из payload

---

## [12.1.0] — 2026-04-15

> Источник: исправление синхронизации адреса доставки при получении заказа из 1С

### Добавлено

- **Обработка `delivery_address`** в `HandleOrderCreated` и `HandleOrderUpdated` — поле из payload (строка) теперь сохраняется напрямую в текстовое поле `orders.delivery_address` (FK `delivery_address_id` удалён)
- Поле `delivery_address` добавлено в JSON Schema `order.updated.json` (в `order.created.json` уже было)

### Исправлено

- Адрес доставки из заказов 1С ранее полностью игнорировался — теперь корректно сохраняется

---

## [12.0.0] — 2026-04-15

> Источник: выделение partner.updated как самостоятельного события

### Добавлено

- **Событие `partner.updated`** (1С → Сайт) — самостоятельное событие обновления атрибутов партнёра: `name`, `phone`, `city`, `country`, `region`, `is_active`, `client_status`
- Обработчик `HandlePartnerUpdated` — поиск по `erp_id`, фолбэк по `email`/`login` с привязкой `erp_id`
- JSON Schema `partner.updated.json` — без `password` (пароль не меняется при обновлении)

### Изменено

- `partner.created` теперь используется **только для создания** нового партнёра из 1С
- `partner.updated` используется для обновления атрибутов существующего партнёра (ранее это было через повторный `partner.created`)
- Тест-план: кейсы обновления партнёра переведены на `partner.updated`

### Удалено

- Поле `currency` из входящих событий `partner.created` и `partner.updated` — валюта определяется через регион пользователя
- Поле `region` из входящих событий `partner.created` и `partner.updated` — 1С не присылает регион

---

## [11.0.0] — 2026-04-08

> Источник: решение по реализации клиентских сегментов (08.04.2026)

### Добавлено

- **Клиентские сегменты** — в `partner.created` (1С → Сайт) добавлено поле `client_status` (string, nullable). Передаёт уровень клиента: `silver`, `gold`, `diamond`, `individual` или `null`
- **История изменений заказов** — при обработке `order.updated` сайт фиксирует diff позиций в `order_change_logs`

### Изменено

- `partner.created` (1С → Сайт) — при смене типового соглашения партнёра повторно отправляется с обновлённым `client_status`
- Первоначальная выгрузка партнёров включает актуальный `client_status`

### Заметки по наименованию

- `client_status` (active/closed) из v10 переименован в `is_active` (boolean)
- Освободившееся имя `client_status` используется для кода статуса клиента (уровень лояльности)

---

## [10.0.0] — 2026-04-07

> Источники: рабочая встреча 07.04.2026, документ «Цены, скидки, категории, маркетинг.docx»

### Добавлено

- **Ценообразование** — 5 категорий маржинальности (Импорт / РФ / СТМ / Тюмень / Фикс), 3 типовых соглашения (10% / 15% / 20%)
- **Атрибуты номенклатуры** — Категория маржинальности, Коэффициент наценки, Снять с продажи, Скрыть в интернете
- **Клиентские сегменты** — уровни Silver / Gold / Diamond / Индивидуальный
- **Нормализация данных** — правила очистки наименований партнёров, телефонов, банковских счетов

### Изменено

- `partner.created` — поле `client_status` (active/closed) заменено на `is_active` (boolean)
- Каталог — добавлен флаг `hidden` в `product.created` / `product.updated`
- Настройки — добавлены параметры `МаксимальнаяРучнаяСкидка`, `ОсновныеВидыЦен`

### Сроки

- Целевой запуск перенесён с 1 мая на **1 июня 2026**

---

## [9.0.0] — 2026-03

### Добавлено

- **Оптимизация дельты индивидуальных цен** — пересчёт только затронутых товаров
- Регистр `ОчередьВыгрузкиИндивидуальныхЦенPecado` — измерение `Номенклатура` и ресурс `ПолныйПересчет`
- Батчевая `ПолучитьКартуСегментов()` вместо N+1 запросов `ТоварВСегменте()`
- delta → UPSERT на стороне сайта

---

## [8.0.0] — 2026-03

### Удалено

- **Раздельные поля ФИО** — `first_name`, `last_name`, `middle_name` удалены из `partner.created`
- Поля `surname`, `patronymic` удалены из модели User

### Изменено

- Единое поле `name` хранит ФИО или название организации
- Формы регистрации и админки обновлены

---

## [7.0.0] — 2026-02

### Удалено

- **Синхронизация скидок** (US-04) — `discount.*` удалён
- **Сегменты номенклатуры** (US-12) — `product_segment.*` удалён
- **Сегменты партнёров** (US-13) — `partner_segment.*` удалён
- **Соглашения** (US-14 v6) — `agreement.*` удалён

### Добавлено

- **Индивидуальные цены** — файловый обмен MinIO + `individual_prices.ready`
- В `order.created` / `order.updated` — поля `base_price`, `discount_percent`, `final_price`
