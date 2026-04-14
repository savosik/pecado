# Критерии приёмки интеграции 1С (ERP) ↔ Сайт Pecado | v9.0

**Версия:** 9.0
**Дата:** 2026-04-06
**Платформа:** 1С:Предприятие 8.3.24 (УТ 11)
**Транспорт:** RabbitMQ (AMQP) + MinIO (S3) для индивидуальных цен

---

## Changelog v9.0 (по сравнению с v8.0)

1. **US-14: Оптимизация дельты индивидуальных цен** — дельта-выгрузка из 1С пересчитывает только затронутые товары, а не полный прайс-лист партнёра. Умная маршрутизация очереди по типу изменения (товар, документ цен, партнёр, курс валюты).
2. **Регистр `ОчередьВыгрузкиИндивидуальныхЦенPecado`:** добавлены измерение `Номенклатура` (СправочникСсылка.Номенклатура) и ресурс `ПолныйПересчет` (Булево). Очередь хранит конкретные товары для пересчёта, а не только партнёров.
3. **Устранение N+1 запросов:** функция `ТоварВСегменте()` (отдельный SQL на каждую пару товар×сегмент) заменена на батчевую `ПолучитьКартуСегментов()` — один запрос вместо до 100K.
4. **Сайт: delta → UPSERT:** при `upload_type: "delta"` сайт выполняет `INSERT ... ON DUPLICATE KEY UPDATE` (обновляет только пришедшие цены). При `upload_type: "full"` — поведение без изменений (DELETE + INSERT).
5. **Формат CSV:** без изменений (`product_uuid,warehouse_uuid,price`). Для delta файл содержит только затронутые товары (а не полный прайс-лист).
6. **Очистка очереди:** после обработки дельты удаляются только обработанные записи (а не вся очередь), предотвращая потерю записей, добавленных во время обработки.

---

## Changelog v8.0 (по сравнению с v7.2)

1. **УДАЛЕНО: Раздельные поля ФИО** — поля `first_name`, `last_name`, `middle_name` (в `partner.created` 1С→Сайт) и `surname`, `patronymic` (в модели User) полностью удалены. Используется единое поле `name` — ФИО физлица или название организации.
2. **partner.created (1С → Сайт):** JSON-payload упрощён — вместо 3 полей (`first_name`, `last_name`, `middle_name`) + `name` осталось только `name`.
3. **partner.created (Сайт → 1С):** payload не изменился (поле `name` было и раньше).
4. **Модель User:** поля `surname` и `patronymic` удалены из таблицы `users`. Аксессор `fullName` удалён. Единое поле `name` хранит ФИО или название организации.
5. **AI-нормализация:** `DataNormalizerService::normalizeUser()` принимает единое поле `name` вместо трёх. AI-промпт упрощён.
6. **Регистрация:** Форма регистрации использует единое поле «Имя / Название» вместо трёх полей.
7. **Админка:** Все формы и таблицы обновлены — единое поле `name`.

---

## Changelog v7.0 (по сравнению с v6.0)

1. **УДАЛЕНО: US-04 (Синхронизация скидок)** — раздел `discount.*` полностью удалён. Скидки больше не передаются как отдельные сущности; итоговые цены рассчитываются в 1С и передаются через механизм индивидуальных цен (US-14).
2. **УДАЛЕНО: US-12 (Синхронизация сегментов номенклатуры)** — `product_segment.*` удалён. Сегменты товаров используются только внутри 1С для расчёта индивидуальных цен.
3. **УДАЛЕНО: US-13 (Синхронизация сегментов партнёров)** — `partner_segment.*` удалён. Сегменты партнёров используются только внутри 1С.
4. **УДАЛЕНО: US-14 v6 (Синхронизация соглашений)** — `agreement.*` удалён. Индивидуальные соглашения учитываются в 1С при расчёте готовых цен.
5. **НОВОЕ: US-14 (Индивидуальные цены пользователей)** — полностью новый раздел. 1С рассчитывает готовые цены (товар + склад + партнёр) и передаёт через файлы MinIO (S3) + уведомление `individual_prices.ready` в RabbitMQ. Поддержка дельт (каждые 15–30 мин) и полной ночной выгрузки.
6. **order.created (Сайт → 1С):** в `items[]` добавлены поля `base_price` (базовая цена), `discount_percent` (процент скидки), `final_price` (конечная цена за единицу).
7. **order.created (1С → Сайт):** в `items[]` добавлены поля `base_price`, `discount_percent`, `final_price`.
8. **order.updated (1С → Сайт):** в `items[]` добавлены поля `base_price`, `discount_percent`, `final_price`.
9. **US-01 (Фильтрация):** удалены пункты про `СегментыНоменклатуры` и `СегментыПартнеров` (сегменты больше не выгружаются).
10. **Инфраструктура RabbitMQ:** очередь `erp_in.segments` удалена; в `erp_in.prices` добавлен routing key `individual_prices.*`; удалены `discount.*`, `agreement.*`.
11. **Первоначальная выгрузка:** удалены процедуры `ВыгрузитьСегментыНоменклатуры`, `ВыгрузитьСегментыПартнеров`, `ВыгрузитьВсеСкидки`, `ВыгрузитьВсеСоглашения`; добавлена `ВыгрузитьИндивидуальныеЦены`.
12. **Настройки:** удалены ссылки на `СегментыНоменклатуры.ВыгружатьНаСайт` и `СегментыПартнеров.ВыгружатьНаСайт`.
13. **Сводная таблица событий:** обновлена в соответствии с изменениями.
14. **v7.1: Оптимизация individual_prices** — таблица `individual_prices` переведена с UUID CHAR(36) на числовые INT FK (partner_id, product_id, warehouse_id). UUID→INT резолвинг выполняется на стороне Laravel при импорте. Удалены 2 избыточных индекса. Формат файлов от 1С (JSONL с UUID) не изменился.

---

## Настройки обмена

Константа `НастройкиОбменаPecado` (ХранилищеЗначения → Структура).  
Управление — общая форма `НастройкиОбменаPecado`.

| Параметр | Тип | Описание | Значение по умолчанию |
|---|---|---|---|
| `Организация` | СправочникСсылка.Организации | Организация для заказов с сайта | — |
| `ОсновнойВидЦен` | СправочникСсылка.ВидыЦен | Вид цен для выгрузки `price.updated` | — |
| `КоэффициентKZT` | Число(5,2) | Поправочный коэффициент для тенге | 1 |
| `КоэффициентBYN` | Число(5,2) | Поправочный коэффициент для белорусского рубля | 1 |
| `СвойствоМодель` | ПВХСсылка.ДопРеквизитыИСведения | Доп. реквизит «Модель» номенклатуры | — |
| `СтранаПоУмолчанию` | Строка(2) | ISO alpha-2 код страны по умолчанию | `"RU"` |
| `ОсновнойСклад` | СправочникСсылка.Склады | Склад по умолчанию для заказов | — |
| `СкладПредзаказа` | СправочникСсылка.Склады | Склад для предзаказов | — |

---

## US-01: Фильтрация выгрузки

Реквизит `ВыгружатьНаСайт` (Булево) добавлен к:

- **Справочник.ВидыНоменклатуры** — если `Ложь`, товары этого вида не выгружаются и не публикуются при записи категории

### Критерии приёмки

- [ ] При записи `ВидыНоменклатуры` с `ВыгружатьНаСайт = Ложь` событие `category.created/updated` **не отправляется**.
- [ ] При массовой выгрузке номенклатуры (`ВыгрузитьВсюНоменклатуру`) товары, чей вид имеет `ВыгружатьНаСайт = Ложь`, **пропускаются**.

---

## US-02: Активация и деактивация пользователей

**Направление:** Сайт → 1С (`partner.created`), 1С → Сайт (`partner.created`, `partner.deleted`)

### partner.created (Сайт → 1С)

**Routing key:** `partner.created`  
**Очередь:** `erp_out.partners`

```json
{
  "event": "partner.created",
  "message_id": "msg-partner-created-550e8400-...",
  "timestamp": "2026-03-17T12:00:00+03:00",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "login": "ivanov@example.com",
  "name": "Иванов Иван Иванович",
  "phone": "+77001234567",
  "email": "ivanov@example.com"
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"partner.created"` |
| `message_id` | string | ✅ | Уникальный ID сообщения |
| `timestamp` | string (ISO 8601) | — | Время создания |
| `uuid` | string (UUID) | ✅ | UUID партнёра на сайте |
| `login` | string | ✅ | Логин (= email) |
| `name` | string | ✅ | ФИО |
| `phone` | string | — | Телефон |
| `email` | string | ✅ | Email |

### partner.created (1С → Сайт)

**Routing key:** `partner.created`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.partners`

```json
{
  "event": "partner.created",
  "message_id": "msg-partner-erp-550e8400-...",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "login": "ivanov@example.com",
  "name": "Иванов Иван Иванович",
  "phone": "+77001234567",
  "email": "ivanov@example.com",
  "password": "1a2b3c4d",
  "city": "Екатеринбург",
  "region": "Свердловская область",
  "country": "RU",
  "currency": "RUB"
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"partner.created"` |
| `message_id` | string | ✅ | Уникальный ID сообщения |
| `uuid` | string (UUID) | ✅ | UUID партнёра в 1С |
| `login` | string | ✅ | Логин (= email) |
| `name` | string | ✅ | ФИО или название организации (НаименованиеПолное) |
| `phone` | string \| null | — | Телефон |
| `email` | string | ✅ | Email |
| `password` | string | ✅ | CRC32-хеш email (8 hex-символов) |
| `city` | string \| null | — | Город из фактического адреса |
| `region` | string \| null | — | Регион из фактического адреса |
| `country` | string | ✅ | ISO alpha-2 код страны (из адреса или фолбэк `СтранаПоУмолчанию`) |
| `currency` | string | ✅ | Валюта, всегда `"RUB"` |

> Поле `password` = CRC32-хеш от email (lowercase, trimmed), 8-символьная hex-строка.
> Если у партнёра нет email — он **не выгружается** (пропускается).
> Поле `country` никогда не `null` — при отсутствии адреса используется `СтранаПоУмолчанию` из настроек (по умолчанию `"RU"`).
> **v8: Поля `first_name`, `last_name`, `middle_name` удалены.** 1С отправляет только `name` (НаименованиеПолное). Разбор ФИО на составные части не требуется.

### partner.deleted (1С → Сайт)

**Routing key:** `partner.deleted`

```json
{
  "event": "partner.deleted",
  "message_id": "msg-partner-del-550e8400-...",
  "uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"partner.deleted"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID партнёра |

### Критерии приёмки

- [ ] Сайт публикует `partner.created` с данными партнёра через `erp_out.partners`.
- [ ] 1С ищет партнёра по email (`EmailПартнера`); если не найден — создаёт нового.
- [ ] 1С **не присваивает** UUID из JSON как ссылку — UUID генерирует платформа 1С.
- [ ] 1С публикует `partner.created` через `erp.events` для первоначальной выгрузки.
- [ ] Партнёры без email **пропускаются** при выгрузке.
- [ ] **v8:** Сообщение содержит единое поле `name` (НаименованиеПолное). Поля `first_name`, `last_name`, `middle_name` **не отправляются**.
- [ ] Сообщение содержит `city`, `region`, `country` из фактического адреса партнёра.
- [ ] `country` никогда не `null` — фолбэк на `СтранаПоУмолчанию` из настроек.
- [ ] Сообщение содержит `currency` = `"RUB"`.
- [ ] Сайт при получении `partner.created` создаёт/обновляет пользователя с `name` как единое поле.
- [ ] При первом входе пользователь **обязан сменить пароль**.
- [ ] Сайт принимает `partner.deleted` и переводит пользователя в статус «Не активен».

---

## US-03: Синхронизация базовых цен

**Направление:** 1С → Сайт

### price.updated

**Routing key:** `price.updated`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.prices`

```json
{
  "event": "price.updated",
  "message_id": "msg-price-a1b2c3d4-...",
  "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "price": 12500.50
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"price.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `product_uuid` | string (UUID) | ✅ | UUID товара |
| `price` | number | ✅ | Базовая цена |

### Критерии приёмки

- [ ] Сайт принимает `price.updated` и обновляет цену товара по UUID.
- [ ] Если товар с UUID не найден — событие игнорируется.
- [ ] Сайт **не** отправляет обновления цен в 1С.
- [ ] Выгрузка цен использует вид цен из `ОсновнойВидЦен` настроек.

---

## US-05: Синхронизация курсов валют

**Направление:** 1С → Сайт

### exchange_rate.updated

**Routing key:** `exchange_rate.updated`  
**Очередь (сайт):** `erp_in.prices`

```json
{
  "event": "exchange_rate.updated",
  "message_id": "msg-rate-kzt-20260317",
  "currency_code": "KZT",
  "official_rate": 5.40,
  "rate_coefficient": 1.01,
  "rate": 5.454,
  "base_currency_code": "RUB",
  "date": "2026-03-17"
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"exchange_rate.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `currency_code` | string | ✅ | ISO 4217 буквенный код |
| `official_rate` | number | ✅ | Курс нацбанка (с учётом кратности) |
| `rate_coefficient` | number | ✅ | Поправочный коэффициент из настроек |
| `rate` | number | ✅ | Итоговый курс = `official_rate × rate_coefficient` |
| `base_currency_code` | string | ✅ | Базовая валюта, всегда `"RUB"` |
| `date` | string | ✅ | Дата курса (yyyy-MM-dd) |

> `ВыгрузитьВсеКурсыВалют` выгружает **все валюты** из справочника `Валюты` (не помеченные на удаление).

### Критерии приёмки

- [ ] Все цены хранятся в базовой валюте (`RUB`).
- [ ] Сайт принимает `exchange_rate.updated` и сохраняет все три значения курса.
- [ ] Сайт **не** обновляет курсы из сторонних источников — только из 1С.
- [ ] При массовой выгрузке отправляются курсы **всех валют** из справочника.

---

## US-06: Синхронизация остатков

**Направление:** 1С → Сайт

### stock.updated

**Routing key:** `stock.updated`  
**Очередь (сайт):** `erp_in.stock`

```json
{
  "event": "stock.updated",
  "message_id": "msg-stock-a1b2c3d4-w1a2b3c4",
  "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "warehouse_uuid": "w1a2b3c4-d5e6-7890-abcd-ef1234567890",
  "quantity": 42
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"stock.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `product_uuid` | string (UUID) | ✅ | UUID товара |
| `warehouse_uuid` | string (UUID) | ✅ | UUID склада |
| `quantity` | number | ✅ | Свободный остаток (≥ 0) |

> `quantity` = ВНаличии − КОтгрузке. Отрицательные значения приводятся к 0.

### Критерии приёмки

- [ ] Склады заводятся в админке сайта вручную с привязкой к UUID.
- [ ] Сайт принимает `stock.updated` и обновляет остаток товара на складе.
- [ ] Пользователь привязан к региону; склады делятся на «для заказа» и «для предзаказа».
- [ ] 1С отправляет остатки по всем организациям в разрезе складов.
- [ ] Клиент не может заказать больше свободных остатков на доступных складах.

---

## US-07: Управление контрагентами

**Направление:** 1С → Сайт (`contractor.created`)

### contractor.created

**Routing key:** `contractor.created`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.partners`

```json
{
  "event": "contractor.created",
  "message_id": "msg-contractor-c1a2b3c4-...",
  "uuid": "c1a2b3c4-d5e6-7890-abcd-ef1234567890",
  "partner_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "name": "ТОО Компания",
  "legal_name": "Товарищество с ограниченной ответственностью «Компания»",
  "tax_id": "1234567890",
  "tax_code": "620101",
  "registration_number": "12345-1234-ТОО",
  "okpo_code": "12345678",
  "country": "KZ",
  "legal_address": "г. Алматы, ул. Абая, 10, офис 5",
  "actual_address": "г. Алматы, ул. Абая, 10",
  "phone": "+77001234567",
  "email": "info@company.kz",
  "bank_accounts": [
    {
      "bank_name": "АО «Казкоммерцбанк»",
      "bank_bik": "KZKOKZKX",
      "correspondent_account": "30101810400000000225",
      "account_number": "KZ123456789012345678",
      "is_primary": true
    },
    {
      "bank_name": "АО «Народный Банк»",
      "bank_bik": "HSBKKZKX",
      "correspondent_account": null,
      "account_number": "KZ987654321098765432",
      "is_primary": false
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"contractor.created"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID контрагента в 1С |
| `partner_uuid` | string (UUID) \| null | ✅ | UUID партнёра-владельца |
| `name` | string | ✅ | Краткое наименование |
| `legal_name` | string | ✅ | Полное юридическое наименование |
| `tax_id` | string | ✅ | ИНН |
| `tax_code` | string \| null | — | КПП |
| `registration_number` | string \| null | — | Регистрационный номер (ОГРН, БИН) |
| `okpo_code` | string \| null | — | Код ОКПО |
| `country` | string | ✅ | ISO alpha-2 код (фолбэк на `СтранаПоУмолчанию`, **никогда не null**) |
| `legal_address` | string \| null | — | Юридический адрес |
| `actual_address` | string \| null | — | Фактический адрес |
| `phone` | string \| null | — | Телефон |
| `email` | string \| null | — | Email |
| `bank_accounts` | array \| null | — | Массив банковских счетов |

**Структура `bank_accounts[]`:**

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `bank_name` | string \| null | — | Наименование банка |
| `bank_bik` | string \| null | — | БИК банка |
| `correspondent_account` | string \| null | — | Корреспондентский счёт |
| `account_number` | string | ✅ | Номер счёта |
| `is_primary` | boolean | ✅ | Основной счёт (первый = `true`, остальные = `false`) |

> Поле `country` **никогда не `null`**. Если у контрагента не заполнена `СтранаРегистрации` в 1С — используется `СтранаПоУмолчанию` из настроек.
> Поле `is_primary` автоматически проставляется: первый счёт = `true`, остальные = `false`.

### Критерии приёмки

- [ ] Пользователь создаёт контрагентов в личном кабинете сайта.
- [ ] При действиях с контрагентом на сайте в 1С **ничего не отправляется**.
- [ ] 1С публикует `contractor.created` через `erp.events`.
- [ ] Сайт принимает `contractor.created` и создаёт контрагента с привязкой к партнёру.
- [ ] Поле `country` гарантированно не `null`.
- [ ] Первый банковский счёт имеет `is_primary = true`.
- [ ] Сопоставление контрагента при заказе — по ИНН (`tax_id`).
- [ ] Если контрагент не найден по ИНН при заказе — 1С создаёт нового.

---

## US-08: Оформление и синхронизация заказов

**Направление:** Сайт → 1С (`order.created`), 1С → Сайт (`order.created`, `order.updated`, `order.deleted`)

### order.created (Сайт → 1С)

**Routing key:** `order.created`  
**Очередь:** `erp_out.orders`

```json
{
  "event": "order.created",
  "message_id": "msg-order-created-site-o1a2b3c4-...",
  "uuid": "o1a2b3c4-d5e6-7890-abcd-ef1234567890",
  "number": "ORD-2026-0001",
  "date": "2026-03-17T10:30:00+03:00",
  "status": "pending",
  "type": "order",
  "partner_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "warehouse_uuids": ["w1a2b3c4-...", "w2b3c4d5-..."],
  "timestamp": "2026-03-17T10:30:01+03:00",
  "contractor": {
    "country": "KZ",
    "name": "ТОО Компания",
    "legal_name": "Товарищество с ограниченной ответственностью «Компания»",
    "tax_id": "1234567890",
    "registration_number": "12345-1234-ТОО",
    "tax_code": "620101",
    "okpo_code": "12345678",
    "legal_address": "г. Алматы, ул. Абая, 10, офис 5",
    "actual_address": "г. Алматы, ул. Абая, 10",
    "phone": "+77001234567",
    "email": "info@company.kz",
    "latitude": 43.238,
    "longitude": 76.945,
    "bank_accounts": [
      {
        "bank_name": "АО «Казкоммерцбанк»",
        "bank_bik": "KZKOKZKX",
        "correspondent_account": "30101810400000000225",
        "account_number": "KZ123456789012345678",
        "is_primary": true
      }
    ]
  },
  "delivery_address": "г. Алматы, ул. Абая, 10",
  "currency_code": "KZT",
  "exchange_rate": 5.454,
  "rate_coefficient": 1.01,
  "comment": "Прошу доставить до 15:00",
  "items": [
    {
      "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "quantity": 5,
      "base_price": 3500.00,
      "discount_percent": 14.29,
      "final_price": 3000.00
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"order.created"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID заказа на сайте |
| `number` | string | — | Номер заказа на сайте |
| `date` | string (ISO 8601) | — | Дата заказа |
| `status` | string | ✅ | Статус (`"pending"`) |
| `type` | string | ✅ | `"order"` или `"preorder"` |
| `partner_uuid` | string (UUID) | ✅ | UUID партнёра |
| `warehouse_uuids` | array of string | — | UUID складов (1С выбирает первый найденный) |
| `contractor` | object \| null | — | Данные контрагента |
| `delivery_address` | string | — | Адрес доставки |
| `currency_code` | string | — | ISO 4217 код валюты |
| `exchange_rate` | number | — | Курс конвертации |
| `rate_coefficient` | number | — | Поправочный коэффициент |
| `comment` | string | — | Комментарий покупателя |
| `items` | array | ✅ | Позиции заказа |

**Структура `items[]` (v7 — обновлено):**

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `product_uuid` | string (UUID) | ✅ | UUID товара |
| `quantity` | number | ✅ | Количество |
| `base_price` | number | ✅ | **v7** Базовая цена за единицу (до скидки) |
| `discount_percent` | number | ✅ | **v7** Процент скидки (0 если нет скидки) |
| `final_price` | number | ✅ | **v7** Конечная цена за единицу (после скидки) |

> Если ни один склад из `warehouse_uuids` не найден в 1С — используется `ОсновнойСклад` из настроек обмена.
> Поле `comment` записывается в состав автоматического комментария к заказу в 1С.
> **v7:** Поле `price` из v6 заменено на `base_price`, `discount_percent`, `final_price`. Поле `final_price` = `base_price × (1 - discount_percent / 100)`.

### order.created (1С → Сайт)

**Routing key:** `order.created`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.orders`

Формат идентичен `order.created` от сайта, но отправляется из 1С (менеджер создал заказ вручную).

```json
{
  "event": "order.created",
  "message_id": "msg-order-erp-o2b3c4d5-...",
  "uuid": "o2b3c4d5-e6f7-8901-bcde-f12345678901",
  "number": "ПК-000123",
  "date": "2026-03-17T14:00:00",
  "status": "confirmed",
  "type": "order",
  "partner_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "warehouse_uuids": ["w1a2b3c4-..."],
  "contractor": {
    "name": "ТОО Компания",
    "legal_name": "Товарищество с ограниченной ответственностью «Компания»",
    "tax_id": "1234567890",
    "tax_code": "620101",
    "registration_number": "12345-1234-ТОО"
  },
  "delivery_address": "г. Алматы, ул. Абая, 10",
  "currency_code": "KZT",
  "exchange_rate": 5.454,
  "rate_coefficient": 1.01,
  "items": [
    {
      "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "quantity": 10,
      "base_price": 3200.00,
      "discount_percent": 12.50,
      "final_price": 2800.00
    }
  ]
}
```

### order.updated (1С → Сайт)

**Routing key:** `order.updated`

```json
{
  "event": "order.updated",
  "message_id": "msg-order-upd-o1a2b3c4-...",
  "uuid": "o1a2b3c4-d5e6-7890-abcd-ef1234567890",
  "status": "confirmed",
  "items": [
    {
      "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "quantity": 4,
      "base_price": 1500.00,
      "discount_percent": 20.00,
      "final_price": 1200.00
    }
  ]
}
```

> Если передан `items` — он **полностью заменяет** текущие позиции. Не передавайте `items`, если обновляете только статус.

### order.deleted (1С → Сайт)

**Routing key:** `order.deleted`

```json
{
  "event": "order.deleted",
  "message_id": "msg-order-del-o1a2b3c4-...",
  "uuid": "o1a2b3c4-d5e6-7890-abcd-ef1234567890"
}
```

### Маппинг статусов заказа

| Статус 1С | Значение на сайте |
|---|---|
| Не согласован | `pending` |
| К выполнению | `confirmed` |
| В работе | `processing` |
| Выполнен | `completed` |
| Закрыт | `closed` |

### Критерии приёмки

- [ ] Корзина разделяется на заказ + предзаказ (по типу `"order"` / `"preorder"`).
- [ ] Заказ фиксируется в валюте пользователя с курсом и коэффициентом.
- [ ] После отправки пользователь изменять заказ **не может**.
- [ ] 1С сохраняет UUID заказа с сайта в реквизите документа (не как ссылку).
- [ ] Если склад не найден — используется `ОсновнойСклад` из настроек.
- [ ] `comment` из JSON включается в комментарий к заказу в 1С.
- [ ] Комментарий в 1С автоматически формируется из: номер заказа, тип, партнёр, контрагент (ИНН+наименование), валюта, адрес доставки, комментарий покупателя.
- [ ] Сайт принимает `order.created` (от менеджера), `order.updated`, `order.deleted`.
- [ ] **v7:** Каждая позиция заказа содержит `base_price`, `discount_percent`, `final_price`.

---

## US-09: Оформление и синхронизация возвратов

> **Отложено на следующий скоп.** JSON-шаблоны созданы.

---

## US-10: Отображение реализаций

**Направление:** 1С → Сайт

### shipment.created / shipment.updated

**Routing key:** `shipment.created` / `shipment.updated`  
**Очередь (сайт):** `erp_in.documents`

`shipment.created` — при первом проведении, `shipment.updated` — при перепроведении.

```json
{
  "event": "shipment.created",
  "message_id": "msg-shipment-s1a2b3c4-...",
  "uuid": "s1a2b3c4-d5e6-7890-abcd-ef1234567890",
  "contractor_inn": "7710140679",
  "date": "2026-03-17",
  "status": "completed",
  "currency_code": "RUB",
  "items": [
    {
      "product_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "order_uuid": "o1a2b3c4-d5e6-7890-abcd-ef1234567890",
      "quantity": 10,
      "price": 500.00,
      "auto_discount_percent": 5.00,
      "manual_discount_percent": 5.00,
      "total": 4500.00,
      "vat_rate": 20
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"shipment.created"` / `"shipment.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID реализации |
| `contractor_inn` | string | ✅ | ИНН контрагента |
| `date` | string | ✅ | Дата (yyyy-MM-dd) |
| `status` | string | ✅ | Всегда `"completed"` |
| `currency_code` | string | ✅ | ISO 4217 код валюты |
| `items` | array | ✅ | Позиции |

**Структура `items[]`:**

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `product_uuid` | string (UUID) | ✅ | UUID товара |
| `order_uuid` | string (UUID) | — | UUID исходного заказа |
| `quantity` | number | ✅ | Количество |
| `price` | number | ✅ | Базовая цена |
| `auto_discount_percent` | number | ✅ | % автоматической скидки |
| `manual_discount_percent` | number | ✅ | % ручной скидки |
| `total` | number | ✅ | Итоговая сумма строки |
| `vat_rate` | number | ✅ | Ставка НДС (%) |

### shipment.deleted

```json
{
  "event": "shipment.deleted",
  "message_id": "msg-shipment-del-s1a2b3c4-...",
  "uuid": "s1a2b3c4-d5e6-7890-abcd-ef1234567890"
}
```

### Критерии приёмки

- [ ] Сайт принимает `shipment.created`, `shipment.updated`, `shipment.deleted`.
- [ ] Одна реализация может включать позиции из **нескольких заказов**.
- [ ] Сайт связывает реализацию с заказами через `order_uuid`.
- [ ] Расчёт задолженности ведётся по реализациям, не по заказам.

---

## US-11: Отображение баланса

**Направление:** 1С → Сайт

### balance.updated

**Routing key:** `balance.updated`  
**Очередь (сайт):** `erp_in.balance`

```json
{
  "event": "balance.updated",
  "message_id": "msg-balance-550e8400-...",
  "partner_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "updated_at": "2026-03-17T15:00:00",
  "contractors": [
    {
      "contractor_inn": "7710140679",
      "contractor_uuid": "c1a2b3c4-d5e6-7890-abcd-ef1234567890",
      "current_balance": -125000.00,
      "overdue_debt": 50000.00,
      "overdue_details": [
        {
          "shipment_uuid": "s1a2b3c4-d5e6-7890-abcd-ef1234567890",
          "amount": 30000.00,
          "due_date": "2026-02-15"
        },
        {
          "shipment_uuid": "s2b3c4d5-e6f7-8901-bcde-f12345678901",
          "amount": 20000.00,
          "due_date": "2026-03-01"
        }
      ]
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"balance.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `partner_uuid` | string (UUID) | ✅ | UUID партнёра |
| `updated_at` | string (ISO 8601) | ✅ | Время обновления |
| `contractors` | array | ✅ | Массив контрагентов |

**Структура `contractors[]`:**

| Поле | Тип | Описание |
|---|---|---|
| `contractor_inn` | string | ИНН контрагента |
| `contractor_uuid` | string (UUID) | UUID контрагента |
| `current_balance` | number | Текущий баланс (отрицательный = долг) |
| `overdue_debt` | number | Просроченная задолженность |
| `overdue_details` | array | Детализация просрочки |

**Структура `overdue_details[]`:**

| Поле | Тип | Описание |
|---|---|---|
| `shipment_uuid` | string (UUID) | UUID реализации |
| `amount` | number | Сумма просрочки |
| `due_date` | string | Дата погашения (yyyy-MM-dd) |

---

## US-14: Индивидуальные цены пользователей (NEW v7)

**Направление:** 1С → Сайт  
**Транспорт:** MinIO (S3) + RabbitMQ (уведомление)

В рамках отказа от зеркалирования логики скидок 1С на сайте, 1С передаёт **готовые** (рассчитанные) индивидуальные цены для каждого партнёра, товара и склада. Скидки, соглашения и сегменты больше не передаются как отдельные сущности — всё учитывается при расчёте итоговой цены в 1С.

### Масштаб данных

- Товаров: ~10 000, Складов: ~5, Партнёров: ~800
- **Итого:** до **40 000 000** уникальных цен
- Передача поштучно через RabbitMQ невозможна — используется модель «Файл + Уведомление»

### individual_prices.ready (1С → Сайт)

**Routing key:** `individual_prices.ready`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.prices`

> **Один файл = один партнёр.** 1С формирует отдельный CSV-файл на каждого партнёра и отправляет соответствующее уведомление.

```json
{
  "event": "individual_prices.ready",
  "message_id": "msg-price-dump-a1b2c3d4",
  "upload_type": "delta",
  "partner_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "file_url": "s3://prices-exchange/2026-03-26/partner_a1b2c3d4-e5f6-7890-abcd-ef1234567890.csv",
  "records_count": 50000,
  "timestamp": "2026-03-26T14:05:00+03:00"
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"individual_prices.ready"` |
| `message_id` | string | ✅ | Уникальный ID |
| `upload_type` | string | ✅ | `"full"` (полная выгрузка) или `"delta"` (только изменения) |
| `partner_uuid` | string (UUID) | ✅ | UUID партнёра (контрагента), которому принадлежат цены |
| `file_url` | string | ✅ | Путь к CSV-файлу в MinIO (S3) |
| `records_count` | number | ✅ | Количество записей в файле |
| `timestamp` | string (ISO 8601) | ✅ | Время формирования |

### Формат файла данных (CSV)

Файл в формате CSV (без заголовка, разделитель — запятая):

```csv
f9e8d7c6-e5f6-7890-abcd-ef1234567890,11223344-aabb-ccdd-eeff-112233445566,1250.00
a0b1c2d3-e5f6-7890-abcd-ef1234567890,11223344-aabb-ccdd-eeff-112233445566,1300.00
```

**Порядок колонок:** `product_uuid`, `warehouse_uuid`, `price`

> `partner_uuid` НЕ включается в CSV — он передаётся в событии RabbitMQ. Это позволяет уменьшить размер файла (UUID 36 символов × кол-во строк).

| Колонка | Индекс | Тип | Описание |
|---|---|---|---|
| `product_uuid` | 0 | string (UUID) | UUID номенклатуры |
| `warehouse_uuid` | 1 | string (UUID) | UUID склада |
| `price` | 2 | number | Индивидуальная цена (с НДС) |

**Требования к формату:**
- Кодировка: UTF-8
- Без BOM
- Без кавычек (UUID и числа не требуют экранирования)
- Разделитель десятичной части: точка
- Без заголовка

### Стратегия выгрузки из 1С

#### Инкрементальная выгрузка (дельта) — v9

- **Расписание:** Каждые 15–30 минут рабочего дня
- При изменении цены, скидки, курса валюты или параметров партнёра — 1С записывает в регистр `ОчередьВыгрузкиИндивидуальныхЦенPecado` конкретную пару (Партнёр, Номенклатура) или флаг полного пересчёта
- **Умная маршрутизация по типу изменения:**

| Тип изменения | Запись в очередь | Что пересчитывается |
|---|---|---|
| Изменение товара (Номенклатура) | Партнер=∅, Номенклатура=X | 1 товар × все партнёры |
| Документ установки цен | Партнер=∅, Номенклатура=X₁..Xₙ (из ТЧ документа) | N товаров × все партнёры |
| Изменение партнёра (соглашение, параметры) | Партнер=X, ПолныйПересчет=Да | Все товары × 1 партнёр |
| Курс валюты / прочее | Партнер=∅, Номенклатура=∅, ПолныйПересчет=Да | Все товары × все партнёры |

- Фоновое задание читает очередь, группирует по партнёрам, для каждого определяет набор затронутых товаров
- **Пересчитываются только затронутые товары** (а не весь прайс-лист партнёра)
- CSV содержит только пересчитанные товары; `upload_type: "delta"`
- После обработки из очереди удаляются только обработанные записи (записи, добавленные во время обработки, сохраняются для следующего цикла)
- **Батчевая проверка сегментов:** `ПолучитьКартуСегментов()` загружает принадлежность товаров к сегментам одним SQL-запросом (вместо N+1 вызовов `ТоварВСегменте()`)

#### Полная ночная выгрузка (Full Dump)
- **Расписание:** Один раз в сутки (03:00)
- Полный пересчёт всех цен, файл на каждого партнёра
- `upload_type: "full"` — Laravel заменяет все цены указанного партнёра (DELETE + INSERT)

#### Именование файлов

```
prices-exchange/
  2026-03-26/
    partner_a1b2c3d4-e5f6-7890-abcd-ef1234567890.csv
    partner_f9e8d7c6-b5a4-3210-fedc-ba9876543210.csv
    ...
```

**Паттерн:** `partner_{partner_uuid}.csv`, где `partner_uuid` — полный UUID контрагента из 1С.

### Обработка на стороне сайта (Laravel)

1. **Таблица `individual_prices`** — MySQL, композитный PK `(partner_id, product_id, warehouse_id)` (INT UNSIGNED), поле `price` DECIMAL(15,2), `updated_at` TIMESTAMP. UUID→INT резолвинг выполняется при импорте
2. **Consumer** слушает `individual_prices.ready` в очереди `erp_in.prices`
3. **Job** (`ProcessIndividualPricesFile`) скачивает CSV из MinIO, резолвит `partner_uuid` → `partner_id`, загружает маппинг UUID→INT для товаров/складов, читает CSV потоково (`fgetcsv`), батч-обработка по 5000 строк
4. **Стратегия обновления (v9):**
   - `upload_type: "full"` — полное удаление всех цен партнёра (`DELETE WHERE partner_id = ?`), затем вставка батчами (`INSERT` по 5000 строк). Один файл = полный прайс-лист одного партнёра
   - `upload_type: "delta"` — UPSERT только пришедших цен: `INSERT ... ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()` батчами по 5000 строк. Остальные цены партнёра **не удаляются**. Файл содержит только затронутые товары
5. **Очистка CSV после обработки:** Job удаляет файл из MinIO сразу после успешной обработки. Страховка: Artisan-команда `app:clean-price-dumps` удаляет файлы старше 3 дней ежедневно в 04:00 (подберёт осиротевшие файлы от упавших Job'ов)

### Критерии приёмки

- [ ] 1С формирует CSV файл **на каждого партнёра** с индивидуальными ценами и загружает в MinIO (бакет `prices-exchange`).
- [ ] CSV формат: `product_uuid,warehouse_uuid,price` (без заголовка).
- [ ] 1С отправляет `individual_prices.ready` с `partner_uuid` через `erp.events`.
- [ ] Дельта-выгрузка запускается каждые 15–30 минут при наличии изменений.
- [ ] **Дельта пересчитывает только затронутые товары:** при изменении цены 1 товара — CSV содержит 1 × N_складов строк, а не полный прайс-лист (v9).
- [ ] **Умная маршрутизация очереди:** регистр `ОчередьВыгрузкиИндивидуальныхЦенPecado` хранит конкретную пару (Партнер, Номенклатура) или флаг полного пересчёта (v9).
- [ ] **Батчевая проверка сегментов:** `ПолучитьКартуСегментов()` — один SQL-запрос вместо N+1 вызовов `ТоварВСегменте()` (v9).
- [ ] Полная выгрузка запускается ежедневно в 03:00 с `upload_type: "full"` (файл на каждого партнёра).
- [ ] Сайт принимает `individual_prices.ready` и загружает данные в таблицу `individual_prices`.
- [ ] Потоковое чтение CSV (`fgetcsv`, без загрузки всего файла в RAM).
- [ ] **full:** полное удаление всех цен партнёра (`DELETE WHERE partner_id = ?`) → вставка батчами (`INSERT`).
- [ ] **delta:** UPSERT только пришедших цен (`INSERT ... ON DUPLICATE KEY UPDATE`), остальные цены партнёра **не удаляются** (v9).
- [ ] Каталог отдаёт индивидуальную цену авторизованного пользователя (JOIN из `individual_prices`).
- [ ] CSV файл удаляется из MinIO сразу после успешной обработки.
- [ ] Страховка: `app:clean-price-dumps` ежедневно в 04:00 удаляет файлы старше 3 дней (осиротевшие от упавших Job'ов).

---

## US-15: Синхронизация каталога товаров

**Направление:** 1С → Сайт

### category.created / category.updated

**Routing key:** `category.created` / `category.updated`  
**Очередь (сайт):** `erp_in.catalog`

```json
{
  "event": "category.created",
  "message_id": "msg-cat-cat-001-...",
  "uuid": "cat-001-e5f6a7b8-c9d0-1234-efab-345678901234",
  "parent_uuid": null,
  "name": "Бельё и одежда",
  "is_group": true
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | Тип события |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID категории (ВидНоменклатуры) |
| `parent_uuid` | string (UUID) \| null | ✅ | UUID родительской категории (`null` = корневой) |
| `name` | string | ✅ | Наименование |
| `is_group` | boolean | ✅ | Признак группы |

> При записи категории с `ВыгружатьНаСайт = Ложь` событие **не отправляется**.

### product.created (1С → Сайт)

**Routing key:** `product.created`

```json
{
  "event": "product.created",
  "message_id": "msg-prod-a1b2c3d4-...",
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Вибро-яйцо XYZ",
  "code": "0T-123213",
  "sku": "AAS-123213",
  "description": "Описание товара...",
  "category_uuid": "cat-003-f6a7b8c9-...",
  "brand": {
    "uuid": "brand-001-a7b8c9d0-...",
    "name": "Jos",
    "label": "Jos"
  },
  "barcodes": ["4600000000001", "4600000000002"],
  "model": {
    "uuid": "model-001-b8c9d0e1-...",
    "name": "XYZ Standard"
  },
  "attributes": [
    {
      "property_uuid": "prop-001-b8c9d0e1-...",
      "property_label": "Цвет",
      "value_type": "reference",
      "value_uuid": "val-col-001-c9d0e1f2-...",
      "value_label": "Розовый"
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"product.created"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID номенклатуры |
| `name` | string | ✅ | Полное наименование |
| `code` | string | ✅ | Код номенклатуры |
| `sku` | string | ✅ | Артикул |
| `description` | string | — | Описание |
| `category_uuid` | string (UUID) | ✅ | UUID вида номенклатуры |
| `brand` | object \| null | — | Бренд (Марка) |
| `barcodes` | array | ✅ | Штрихкоды |
| `model` | object \| null | — | Модель (из доп. реквизита) |
| `attributes` | array | ✅ | Доп. реквизиты |

**Структура `brand`:**

| Поле | Тип | Описание |
|---|---|---|
| `uuid` | string (UUID) | UUID марки |
| `name` | string | Наименование марки |
| `label` | string | Отображаемое имя (= `name`) |

**Структура `model`:**

| Поле | Тип | Описание |
|---|---|---|
| `uuid` | string (UUID) | UUID значения доп. реквизита |
| `name` | string | Наименование модели |

**Структура `attributes[]`:**

| Поле | Тип | Описание |
|---|---|---|
| `property_uuid` | string (UUID) | UUID свойства |
| `property_label` | string | Наименование свойства |
| `value_type` | string | Тип: `"string"`, `"number"`, `"boolean"`, `"reference"` |
| `value_uuid` | string \| null | UUID значения (null для скалярных) |
| `value_label` | string | Строковое представление значения |

### product.updated (1С → Сайт) — частичное обновление

**Routing key:** `product.updated`

Отправляет **только изменённые поля**. Обязательны: `event`, `message_id`, `uuid`.

```json
{
  "event": "product.updated",
  "message_id": "msg-prod-upd-a1b2c3d4-...",
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Вибро-яйцо XYZ Pro",
  "brand": {
    "uuid": "brand-002-b8c9d0e1-...",
    "name": "A-Toys",
    "label": "A-Toys"
  }
}
```

> `attributes` в `product.updated` содержит **только изменённые** атрибуты. Сайт мержит по `property_uuid`.
> Цена **не обновляется** через `product.updated` — используйте `price.updated`.

### Критерии приёмки

- [ ] Родительская категория создаётся **до** дочерней (BFS-обход по уровням).
- [ ] `product.updated` — частичное обновление, мерж по `property_uuid` для атрибутов.
- [ ] `brand` содержит поле `label` (дублирует `name`).
- [ ] Массовая выгрузка фильтрует товары по `ВыгружатьНаСайт` вида номенклатуры.
- [ ] `model` — nullable. Источник — доп. реквизит из настройки `СвойствоМодель`.

---

## Первоначальная выгрузка (Initial Data Load)

### Порядок выгрузки

| # | Процедура | Событие | Зависит от | US |
|---|---|---|---|---|
| 1 | `ВыгрузитьВсеКатегории()` | `category.created` | — | US-15 |
| 2 | `ВыгрузитьВсюНоменклатуру()` | `product.created` | Категории | US-15 |
| 3 | `ВыгрузитьВсеЦены()` | `price.updated` | Номенклатура | US-03 |
| 4 | `ВыгрузитьВсеОстатки()` | `stock.updated` | Номенклатура | US-06 |
| 5 | `ВыгрузитьВсеКурсыВалют()` | `exchange_rate.updated` | — | US-05 |
| 6 | `ВыгрузитьВсехПартнеров()` | `partner.created` | — | US-02 |
| 7 | `ВыгрузитьВсехКонтрагентов()` | `contractor.created` | Партнёры | US-07 |
| 8 | `ВыгрузитьВсеЗаказы()` | `order.created` | Партнёры, Номенклатура | US-08 |
| 9 | `ВыгрузитьВсеРеализации()` | `shipment.created` | Заказы | US-10 |
| 10 | `ВыгрузитьВсеБалансы()` | `balance.updated` | Контрагенты | US-11 |
| 11 | `ВыгрузитьИндивидуальныеЦены()` | `individual_prices.ready` | Партнёры, Номенклатура | **US-14 (v7)** |

### Особенности выгрузки

- **`ВыгрузитьВсюНоменклатуру()`** — фильтрует по `ВыгружатьНаСайт` вида номенклатуры (INNER JOIN).
- **`ВыгрузитьВсеЗаказы()`** — отбирает только `Проведен` (включая закрытые/выполненные заказы).
- **`ВыгрузитьВсеРеализации()`** — отбирает только `Проведен`.
- **`ВыгрузитьВсеКурсыВалют()`** — все валюты из справочника `Валюты` (не помеченные на удаление).
- **`ВыгрузитьИндивидуальныеЦены()`** — полный дамп (`upload_type: "full"`) всех индивидуальных цен через MinIO + RabbitMQ.

### Критерии приёмки (Initial Data Load)

- [ ] Все процедуры выгрузки реализованы и доступны из обработки `ОтладкаОбменаPecado`.
- [ ] Выгрузка выполняется в правильном порядке (зависимости).
- [ ] Партнёры без email пропускаются.
- [ ] Пароль партнёра = CRC32-хеш email (8 hex-символов).
- [ ] Номенклатура фильтруется по `ВыгружатьНаСайт` вида.
- [ ] Все валюты выгружаются (не хардкод).
- [ ] Повторная выгрузка безопасна (идемпотентность).

---

## Инфраструктура RabbitMQ

### Архитектура

```
1С → erp.events (exchange, topic, durable) → Очереди сайта (erp_in.*)
Сайт → site.events (exchange, topic, durable) → Очереди 1С (erp_out.*)
```

### Входящие очереди (1С → Сайт)

| Очередь | Routing keys | События |
|---|---|---|
| `erp_in.partners` | `partner.*`, `contractor.*` | `partner.created`, `partner.deleted`, `contractor.created` |
| `erp_in.prices` | `price.*`, `exchange_rate.*`, `individual_prices.*` | `price.updated`, `exchange_rate.updated`, `individual_prices.ready` |
| `erp_in.stock` | `stock.*` | `stock.updated` |
| `erp_in.orders` | `order.*` | `order.created`, `order.updated`, `order.deleted` |
| `erp_in.returns` | `return.*` | `return.updated`, `return.deleted` |
| `erp_in.documents` | `shipment.*` | `shipment.created`, `shipment.updated`, `shipment.deleted` |
| `erp_in.balance` | `balance.*` | `balance.updated` |
| `erp_in.catalog` | `category.*`, `product.*` | Каталог и номенклатура |

### Исходящие очереди (Сайт → 1С)

| Очередь | Routing key | Описание |
|---|---|---|
| `erp_out.orders` | `order.created` | Новые заказы с сайта |
| `erp_out.returns` | `return.created` | Новые возвраты с сайта |
| `erp_out.partners` | `partner.created` | Активированные пользователи |

### Формат конверта

Все сообщения — **raw JSON**. Обязательные мета-поля:

```json
{
  "event": "partner.created",
  "message_id": "msg-550e8400-e29b-41d4-..."
}
```

`message_id` генерируется автоматически (UUID) на стороне отправителя.

### Идемпотентность

Все обработчики должны быть **идемпотентными**: повторная обработка по `message_id` не приводит к дублированию.

---

## Сводная таблица событий

| Событие | Направление | Exchange | Очередь | US |
|---|---|---|---|---|
| `partner.created` | Сайт → 1С | `site.events` | `erp_out.partners` | US-02 |
| `partner.created` | 1С → Сайт | `erp.events` | `erp_in.partners` | US-02 |
| `partner.deleted` | 1С → Сайт | `erp.events` | `erp_in.partners` | US-02 |
| `contractor.created` | 1С → Сайт | `erp.events` | `erp_in.partners` | US-07 |
| `price.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` | US-03 |
| `individual_prices.ready` | 1С → Сайт | `erp.events` | `erp_in.prices` | **US-14** |
| `exchange_rate.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` | US-05 |
| `stock.updated` | 1С → Сайт | `erp.events` | `erp_in.stock` | US-06 |
| `order.created` | Сайт → 1С | `site.events` | `erp_out.orders` | US-08 |
| `order.created` | 1С → Сайт | `erp.events` | `erp_in.orders` | US-08 |
| `order.updated` | 1С → Сайт | `erp.events` | `erp_in.orders` | US-08 |
| `order.deleted` | 1С → Сайт | `erp.events` | `erp_in.orders` | US-08 |
| `return.created` | Сайт → 1С | `site.events` | `erp_out.returns` | US-09 |
| `return.updated` | 1С → Сайт | `erp.events` | `erp_in.returns` | US-09 |
| `return.deleted` | 1С → Сайт | `erp.events` | `erp_in.returns` | US-09 |
| `shipment.created` | 1С → Сайт | `erp.events` | `erp_in.documents` | US-10 |
| `shipment.updated` | 1С → Сайт | `erp.events` | `erp_in.documents` | US-10 |
| `shipment.deleted` | 1С → Сайт | `erp.events` | `erp_in.documents` | US-10 |
| `balance.updated` | 1С → Сайт | `erp.events` | `erp_in.balance` | US-11 |
| `category.created` | 1С → Сайт | `erp.events` | `erp_in.catalog` | US-15 |
| `category.updated` | 1С → Сайт | `erp.events` | `erp_in.catalog` | US-15 |
| `product.created` | 1С → Сайт | `erp.events` | `erp_in.catalog` | US-15 |
| `product.updated` | 1С → Сайт | `erp.events` | `erp_in.catalog` | US-15 |

---

## Открытые вопросы

| # | Вопрос | Статус |
|---|---|---|
| 1 | Группы атрибутов: нужны ли, как передавать? | Не уточнено |
| 2 | Оптимальное количество воркеров для `price.updated` и `stock.updated` | Требует проверки |
| ~~3~~ | ~~Формат банковских реквизитов в `contractor.created`~~ | ✅ Закрыт v5 |
