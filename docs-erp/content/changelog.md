# Changelog

Формат: [Keep a Changelog](https://keepachangelog.com/)

Payload-схемы: [AsyncAPI](/docs/erp/spec.yaml) | [JSON Schemas](/docs/erp/schemas/)

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
