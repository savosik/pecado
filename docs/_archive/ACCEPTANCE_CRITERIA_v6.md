# Критерии приёмки интеграции 1С (ERP) ↔ Сайт Pecado | v6.0

**Версия:** 6.0  
**Дата:** 2026-03-23  
**Платформа:** 1С:Предприятие 8.3.23 (УТ 11)  
**Транспорт:** RabbitMQ (AMQP)

---

## Changelog v6.0 (по сравнению с v5.0)

1. **partner.created (1С→Сайт):** добавлено поле `first_name` (имя партнёра)
2. **partner.created (1С→Сайт):** добавлено поле `last_name` (фамилия партнёра)
3. **partner.created (1С→Сайт):** добавлено поле `middle_name` (отчество партнёра)
4. **partner.created (1С→Сайт):** добавлено поле `city` (город из адреса)
5. **partner.created (1С→Сайт):** добавлено поле `country` (ISO alpha-2, фолбэк на настройку)
6. **partner.created (1С→Сайт):** добавлено поле `region` (регион из адреса)
7. **partner.created (1С→Сайт):** добавлено поле `currency` (всегда `"RUB"`)
8. **product.created / product.updated:** поле `brand` теперь содержит `label` (дублирует `name` для совместимости)
9. **contractor.created:** поле `country` никогда не `null` — фолбэк на `СтранаПоУмолчанию` из настроек
10. **contractor.created:** поле `is_primary` в `bank_accounts[]` — первый счёт автоматически `true`
11. **Настройки:** добавлен параметр `СтранаПоУмолчанию` (Строка, ISO alpha-2, по умолчанию `"RU"`)
12. **Настройки:** добавлен параметр `ОсновнойСклад` (СправочникСсылка.Склады)
13. **Настройки:** добавлен параметр `СкладПредзаказа` (СправочникСсылка.Склады)
14. **Фильтрация:** реквизит `ВыгружатьНаСайт` (Булево) на `Справочник.ВидыНоменклатуры`
15. **Фильтрация:** реквизит `ВыгружатьНаСайт` (Булево) на `Справочник.СегментыНоменклатуры`
16. **Фильтрация:** реквизит `ВыгружатьНаСайт` (Булево) на `Справочник.СегментыПартнеров`
17. **Новое событие:** `agreement.created` (1С → Сайт) — индивидуальное соглашение
18. **Новое событие:** `agreement.updated` (1С → Сайт) — обновление соглашения
19. **Новое событие:** `agreement.deleted` (1С → Сайт) — удаление/закрытие соглашения
20. **Первоначальная выгрузка:** добавлена процедура `ВыгрузитьВсеСоглашения()`
21. **ВыгрузитьВсеЗаказы:** отбор только `Проведен` (включая закрытые), непроведённые не выгружаются
22. **ВыгрузитьВсеРеализации:** отбор только `Проведен`
23. **ВыгрузитьВсеКурсыВалют:** все валюты из справочника `Валюты` (не хардкод KZT/BYN)
24. **Массовая выгрузка номенклатуры:** фильтрация по `ВыгружатьНаСайт` вида номенклатуры
25. **Обработка заказа (Сайт→1С):** фолбэк склада на `ОсновнойСклад` из настроек
26. **Пароль партнёра:** алгоритм CRC32 (не SHA-1), 8 символов hex
27. **order.created (Сайт→1С):** добавлено поле `comment` (комментарий покупателя)

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

## US-01: Фильтрация выгрузки (NEW v6)

Реквизит `ВыгружатьНаСайт` (Булево) добавлен к:

- **Справочник.ВидыНоменклатуры** — если `Ложь`, товары этого вида не выгружаются и не публикуются при записи категории
- **Справочник.СегментыНоменклатуры** — если `Ложь`, сегмент не публикуется при записи
- **Справочник.СегментыПартнеров** — если `Ложь`, сегмент не публикуется при записи

### Критерии приёмки

- [ ] При записи `ВидыНоменклатуры` с `ВыгружатьНаСайт = Ложь` событие `category.created/updated` **не отправляется**.
- [ ] При массовой выгрузке номенклатуры (`ВыгрузитьВсюНоменклатуру`) товары, чей вид имеет `ВыгружатьНаСайт = Ложь`, **пропускаются**.
- [ ] При записи `СегментыНоменклатуры` с `ВыгружатьНаСайт = Ложь` событие `product_segment.*` **не отправляется**.
- [ ] При записи `СегментыПартнеров` с `ВыгружатьНаСайт = Ложь` событие `partner_segment.*` **не отправляется**.

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

### partner.created (1С → Сайт) — обновлён в v6

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
  "first_name": "Иван",
  "last_name": "Иванов",
  "middle_name": "Иванович",
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
| `name` | string | ✅ | Полное имя (НаименованиеПолное) |
| `phone` | string \| null | — | Телефон |
| `email` | string | ✅ | Email |
| `password` | string | ✅ | CRC32-хеш email (8 hex-символов) |
| `first_name` | string \| null | — | **v6** Имя (разбор из НаименованиеПолное) |
| `last_name` | string \| null | — | **v6** Фамилия |
| `middle_name` | string \| null | — | **v6** Отчество |
| `city` | string \| null | — | **v6** Город из фактического адреса |
| `region` | string \| null | — | **v6** Регион из фактического адреса |
| `country` | string | ✅ | **v6** ISO alpha-2 код страны (из адреса или фолбэк `СтранаПоУмолчанию`) |
| `currency` | string | ✅ | **v6** Валюта, всегда `"RUB"` |

> **v6:** Поле `password` = CRC32-хеш от email (lowercase, trimmed), 8-символьная hex-строка.
> Если у партнёра нет email — он **не выгружается** (пропускается).
> Поле `country` никогда не `null` — при отсутствии адреса используется `СтранаПоУмолчанию` из настроек (по умолчанию `"RU"`).

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
- [ ] **v6:** Сообщение содержит `first_name`, `last_name`, `middle_name` (разбор ФИО из полного имени).
- [ ] **v6:** Сообщение содержит `city`, `region`, `country` из фактического адреса партнёра.
- [ ] **v6:** `country` никогда не `null` — фолбэк на `СтранаПоУмолчанию` из настроек.
- [ ] **v6:** Сообщение содержит `currency` = `"RUB"`.
- [ ] Сайт при получении `partner.created` создаёт пользователя с указанным паролем.
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

## US-04: Синхронизация скидок

**Направление:** 1С → Сайт

### discount.created / discount.updated

**Routing key:** `discount.created` / `discount.updated`  
**Очередь (сайт):** `erp_in.prices`

```json
{
  "event": "discount.created",
  "message_id": "msg-discount-d1e2f3a4-...",
  "uuid": "d1e2f3a4-b5c6-7890-abcd-ef1234567890",
  "name": "Скидка 10% на лубриканты",
  "type": "promotion",
  "value": 10.00,
  "starts_at": "2026-01-01T00:00:00",
  "ends_at": "2026-12-31T23:59:59",
  "product_uuids": [],
  "partner_uuids": [],
  "product_segment_uuids": ["seg-prod-001-..."],
  "partner_segment_uuids": []
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"discount.created"` / `"discount.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID скидки |
| `name` | string | ✅ | Наименование скидки |
| `type` | string | ✅ | `"promotion"` (акция) |
| `value` | number | ✅ | Процент скидки |
| `starts_at` | string \| null | — | Дата начала (ISO 8601) |
| `ends_at` | string \| null | — | Дата окончания (ISO 8601) |
| `product_uuids` | array | ✅ | UUID конкретных товаров |
| `partner_uuids` | array | ✅ | UUID конкретных партнёров |
| `product_segment_uuids` | array | ✅ | UUID сегментов товаров |
| `partner_segment_uuids` | array | ✅ | UUID сегментов партнёров |

> `type` = `"promotion"` для акций/скидок из справочника СкидкиНаценки. Индивидуальные соглашения публикуются через `agreement.*` (см. US-14).

### discount.deleted

**Routing key:** `discount.deleted`

```json
{
  "event": "discount.deleted",
  "message_id": "msg-discount-del-d1e2f3a4-...",
  "uuid": "d1e2f3a4-b5c6-7890-abcd-ef1234567890"
}
```

### Критерии приёмки

- [ ] Сайт принимает `discount.created`, `discount.updated`, `discount.deleted`.
- [ ] Сайт создаёт или обновляет скидку вместе с привязками.
- [ ] При удалении — сайт деактивирует скидку.
- [ ] Если по акции скидка больше, чем по соглашению — применяется акция.

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
| `currency_code` | string | ✅ | ISO 4217 буквенный код (из поля `Наименование` справочника `Валюты`) |
| `official_rate` | number | ✅ | Курс нацбанка (с учётом кратности) |
| `rate_coefficient` | number | ✅ | Поправочный коэффициент из настроек |
| `rate` | number | ✅ | Итоговый курс = `official_rate × rate_coefficient` |
| `base_currency_code` | string | ✅ | Базовая валюта, всегда `"RUB"` |
| `date` | string | ✅ | Дата курса (yyyy-MM-dd) |

> **v6:** `ВыгрузитьВсеКурсыВалют` выгружает **все валюты** из справочника `Валюты` (не помеченные на удаление), а не только хардкод KZT/BYN.

### Критерии приёмки

- [ ] Все цены хранятся в базовой валюте (`RUB`).
- [ ] Сайт принимает `exchange_rate.updated` и сохраняет все три значения курса.
- [ ] Сайт **не** обновляет курсы из сторонних источников — только из 1С.
- [ ] **v6:** При массовой выгрузке отправляются курсы **всех валют** из справочника.

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

### contractor.created — обновлён в v6

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
| `country` | string | ✅ | **v6** ISO alpha-2 код (фолбэк на `СтранаПоУмолчанию`, **никогда не null**) |
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
| `is_primary` | boolean | ✅ | **v6** Основной счёт (первый = `true`, остальные = `false`) |

> **v6:** Поле `country` **никогда не `null`**. Если у контрагента не заполнена `СтранаРегистрации` в 1С — используется `СтранаПоУмолчанию` из настроек (по умолчанию `"RU"`).
> Поле `is_primary` автоматически проставляется: первый счёт в результатах = `true`, остальные = `false`.

### Критерии приёмки

- [ ] Пользователь создаёт контрагентов в личном кабинете сайта.
- [ ] При действиях с контрагентом на сайте в 1С **ничего не отправляется**.
- [ ] 1С публикует `contractor.created` через `erp.events`.
- [ ] Сайт принимает `contractor.created` и создаёт контрагента с привязкой к партнёру.
- [ ] **v6:** Поле `country` гарантированно не `null`.
- [ ] **v6:** Первый банковский счёт имеет `is_primary = true`.
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
      "price": 3000.00
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
| `comment` | string | — | **v6** Комментарий покупателя |
| `items` | array | ✅ | Позиции заказа |

**Структура `items[]`:**

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `product_uuid` | string (UUID) | ✅ | UUID товара |
| `quantity` | number | ✅ | Количество |
| `price` | number | ✅ | Цена за единицу |

> **v6:** Если ни один склад из `warehouse_uuids` не найден в 1С — используется `ОсновнойСклад` из настроек обмена.
> **v6:** Поле `comment` записывается в состав автоматического комментария к заказу в 1С.

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
      "price": 2800.00
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
      "price": 1200.00
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
- [ ] **v6:** Если склад не найден — используется `ОсновнойСклад` из настроек.
- [ ] **v6:** `comment` из JSON включается в комментарий к заказу в 1С.
- [ ] Комментарий в 1С автоматически формируется из: номер заказа, тип, партнёр, контрагент (ИНН+наименование), валюта, адрес доставки, комментарий покупателя.
- [ ] Сайт принимает `order.created` (от менеджера), `order.updated`, `order.deleted`.

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

## US-12: Синхронизация сегментов номенклатуры

**Направление:** 1С → Сайт

### product_segment.created / product_segment.updated

**Routing key:** `product_segment.created` / `product_segment.updated`  
**Очередь (сайт):** `erp_in.segments`

> Публикация `created`/`updated` **отложена в фоновое задание** (после завершения транзакции записи). `deleted` — синхронно.

```json
{
  "event": "product_segment.created",
  "message_id": "msg-pseg-seg-prod-001-...",
  "uuid": "seg-prod-001-c3d4e5f6-a7b8-9012-cdef-123456789012",
  "name": "Лубриканты",
  "product_uuids": ["a1b2c3d4-...", "b2c3d4e5-...", "c3d4e5f6-..."]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | Тип события |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID сегмента |
| `name` | string | ✅ | Наименование сегмента |
| `product_uuids` | array | ✅ | UUID товаров в сегменте |

### product_segment.deleted

```json
{
  "event": "product_segment.deleted",
  "message_id": "msg-pseg-del-seg-prod-001-...",
  "uuid": "seg-prod-001-c3d4e5f6-a7b8-9012-cdef-123456789012"
}
```

### Критерии приёмки

- [ ] Один товар может входить в несколько сегментов.
- [ ] **v6:** При `ВыгружатьНаСайт = Ложь` событие не отправляется.

---

## US-13: Синхронизация сегментов партнёров

**Направление:** 1С → Сайт

### partner_segment.created / partner_segment.updated

**Routing key:** `partner_segment.created` / `partner_segment.updated`  
**Очередь (сайт):** `erp_in.segments`

> Публикация `created`/`updated` **отложена в фоновое задание**. `deleted` — синхронно.

```json
{
  "event": "partner_segment.created",
  "message_id": "msg-partseg-seg-part-001-...",
  "uuid": "seg-part-001-d4e5f6a7-b8c9-0123-defa-234567890123",
  "name": "Уровень Голд",
  "partner_uuids": ["550e8400-...", "660f9511-..."]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | Тип события |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID сегмента |
| `name` | string | ✅ | Наименование сегмента |
| `partner_uuids` | array | ✅ | UUID партнёров в сегменте |

### partner_segment.deleted

```json
{
  "event": "partner_segment.deleted",
  "message_id": "msg-partseg-del-seg-part-001-...",
  "uuid": "seg-part-001-d4e5f6a7-b8c9-0123-defa-234567890123"
}
```

### Критерии приёмки

- [ ] Один партнёр может входить в несколько сегментов.
- [ ] **v6:** При `ВыгружатьНаСайт = Ложь` событие не отправляется.

---

## US-14: Синхронизация индивидуальных соглашений (NEW v6)

**Направление:** 1С → Сайт

Индивидуальные соглашения с клиентами — привязанные к конкретному партнёру. Типовые соглашения не публикуются.

### agreement.created / agreement.updated

**Routing key:** `agreement.created` / `agreement.updated`  
**Exchange:** `erp.events`  
**Очередь (сайт):** `erp_in.prices`

Публикуется при переводе соглашения в статус «Действует» (created) или при изменении действующего (updated).

```json
{
  "event": "agreement.created",
  "message_id": "msg-agreement-a1b2c3d4-...",
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Индивидуальное соглашение Иванов",
  "type": "agreement",
  "partner_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "starts_at": "2026-01-01T00:00:00",
  "ends_at": "2026-12-31T23:59:59",
  "discounts": [
    {
      "discount_uuid": "d1e2f3a4-b5c6-7890-abcd-ef1234567890",
      "discount_name": "Скидка 15% на лубриканты",
      "value": 15.00,
      "product_segment_uuid": "seg-prod-001-c3d4e5f6-..."
    },
    {
      "discount_uuid": "d2e3f4a5-c6d7-8901-bcde-f12345678901",
      "discount_name": "Скидка 5% на бельё",
      "value": 5.00,
      "product_segment_uuid": "seg-prod-002-d4e5f6a7-..."
    }
  ]
}
```

| Поле | Тип | Обязательность | Описание |
|---|---|---|---|
| `event` | string | ✅ | `"agreement.created"` / `"agreement.updated"` |
| `message_id` | string | ✅ | Уникальный ID |
| `uuid` | string (UUID) | ✅ | UUID соглашения |
| `name` | string | ✅ | Наименование соглашения |
| `type` | string | ✅ | Всегда `"agreement"` |
| `partner_uuid` | string (UUID) | ✅ | UUID партнёра |
| `starts_at` | string \| null | — | Дата начала действия |
| `ends_at` | string \| null | — | Дата окончания действия |
| `discounts` | array | ✅ | Массив скидок по соглашению |

**Структура `discounts[]`:**

| Поле | Тип | Описание |
|---|---|---|
| `discount_uuid` | string (UUID) | UUID скидки |
| `discount_name` | string | Наименование скидки |
| `value` | number | Процент скидки |
| `product_segment_uuid` | string (UUID) \| `""` | UUID сегмента номенклатуры |

### agreement.deleted

**Routing key:** `agreement.deleted`

Публикуется при: пометке удаления, закрытии (статус → Закрыто).

```json
{
  "event": "agreement.deleted",
  "message_id": "msg-agreement-del-a1b2c3d4-...",
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```

### Логика событий

| Условие | Событие |
|---|---|
| Новое соглашение → статус «Действует» | `agreement.created` |
| Статус изменился с НеСогласовано → Действует | `agreement.created` |
| Обновление действующего соглашения | `agreement.updated` |
| Пометка удаления | `agreement.deleted` |
| Статус → Закрыто | `agreement.deleted` |
| Статус «Не согласовано» (без перехода) | не публикуется |

### Критерии приёмки

- [ ] Публикуются только **индивидуальные** соглашения (не типовые).
- [ ] Соглашение со статусом «Не согласовано» **не публикуется**.
- [ ] Закрытие соглашения публикуется как `agreement.deleted`.
- [ ] Массив `discounts` формируется из регистра `ДействиеСкидокНаценок` (действующие скидки).
- [ ] Каждая скидка содержит привязку к сегменту номенклатуры (`product_segment_uuid`).
- [ ] Сайт при получении `agreement.created` создаёт персональную скидку для партнёра.

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

> **v6:** При записи категории с `ВыгружатьНаСайт = Ложь` событие **не отправляется**.

### product.created (1С → Сайт) — обновлён в v6

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
    },
    {
      "property_uuid": "prop-002-d0e1f2a3-...",
      "property_label": "Материал",
      "value_type": "reference",
      "value_uuid": "val-mat-001-e1f2a3b4-...",
      "value_label": "Силикон"
    },
    {
      "property_uuid": "prop-003-f2a3b4c5-...",
      "property_label": "Вес",
      "value_type": "string",
      "value_uuid": null,
      "value_label": "150г"
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
| `label` | string | **v6** Отображаемое имя (= `name`, для совместимости) |

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
  },
  "attributes": [
    {
      "property_uuid": "prop-001-b8c9d0e1-...",
      "property_label": "Цвет",
      "value_type": "reference",
      "value_uuid": "val-col-002-d0e1f2a3-...",
      "value_label": "Фиолетовый"
    }
  ]
}
```

> `attributes` в `product.updated` содержит **только изменённые** атрибуты. Сайт мержит по `property_uuid`.
> Цена **не обновляется** через `product.updated` — используйте `price.updated`.

### Критерии приёмки

- [ ] Родительская категория создаётся **до** дочерней (BFS-обход по уровням).
- [ ] `product.updated` — частичное обновление, мерж по `property_uuid` для атрибутов.
- [ ] **v6:** `brand` содержит поле `label` (дублирует `name`).
- [ ] **v6:** Массовая выгрузка фильтрует товары по `ВыгружатьНаСайт` вида номенклатуры.
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
| 6 | `ВыгрузитьСегментыНоменклатуры()` | `product_segment.created` | Номенклатура | US-12 |
| 7 | `ВыгрузитьСегментыПартнеров()` | `partner_segment.created` | Партнёры | US-13 |
| 8 | `ВыгрузитьВсеСкидки()` | `discount.created` | Сегменты, Номенклатура | US-04 |
| 9 | `ВыгрузитьВсеСоглашения()` | `agreement.created` | Скидки, Партнёры | **US-14 (v6)** |
| 10 | `ВыгрузитьВсехПартнеров()` | `partner.created` | — | US-02 |
| 11 | `ВыгрузитьВсехКонтрагентов()` | `contractor.created` | Партнёры | US-07 |
| 12 | `ВыгрузитьВсеЗаказы()` | `order.created` | Партнёры, Номенклатура | US-08 |
| 13 | `ВыгрузитьВсеРеализации()` | `shipment.created` | Заказы | US-10 |
| 14 | `ВыгрузитьВсеБалансы()` | `balance.updated` | Контрагенты | US-11 |

### Особенности выгрузки (v6)

- **`ВыгрузитьВсюНоменклатуру()`** — фильтрует по `ВыгружатьНаСайт` вида номенклатуры (INNER JOIN).
- **`ВыгрузитьВсеЗаказы()`** — отбирает только `Проведен` (включая закрытые/выполненные заказы).
- **`ВыгрузитьВсеРеализации()`** — отбирает только `Проведен`.
- **`ВыгрузитьВсеКурсыВалют()`** — все валюты из справочника `Валюты` (не помеченные на удаление), а не хардкод.
- **`ВыгрузитьВсеСоглашения()`** — только индивидуальные, действующие, не помеченные на удаление.

### Критерии приёмки (Initial Data Load)

- [ ] Все процедуры выгрузки реализованы и доступны из обработки `ОтладкаОбменаPecado`.
- [ ] Выгрузка выполняется в правильном порядке (зависимости).
- [ ] Партнёры без email пропускаются.
- [ ] **v6:** Пароль партнёра = CRC32-хеш email (8 hex-символов).
- [ ] **v6:** Номенклатура фильтруется по `ВыгружатьНаСайт` вида.
- [ ] **v6:** Все валюты выгружаются (не хардкод).
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
| `erp_in.prices` | `price.*`, `discount.*`, `exchange_rate.*`, `agreement.*` | `price.updated`, `discount.*`, `exchange_rate.updated`, `agreement.*` |
| `erp_in.stock` | `stock.*` | `stock.updated` |
| `erp_in.orders` | `order.*` | `order.created`, `order.updated`, `order.deleted` |
| `erp_in.returns` | `return.*` | `return.updated`, `return.deleted` |
| `erp_in.documents` | `shipment.*` | `shipment.created`, `shipment.updated`, `shipment.deleted` |
| `erp_in.balance` | `balance.*` | `balance.updated` |
| `erp_in.segments` | `product_segment.*`, `partner_segment.*` | Сегменты номенклатуры и партнёров |
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
| `discount.created` | 1С → Сайт | `erp.events` | `erp_in.prices` | US-04 |
| `discount.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` | US-04 |
| `discount.deleted` | 1С → Сайт | `erp.events` | `erp_in.prices` | US-04 |
| `agreement.created` | 1С → Сайт | `erp.events` | `erp_in.prices` | **US-14** |
| `agreement.updated` | 1С → Сайт | `erp.events` | `erp_in.prices` | **US-14** |
| `agreement.deleted` | 1С → Сайт | `erp.events` | `erp_in.prices` | **US-14** |
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
| `product_segment.created` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-12 |
| `product_segment.updated` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-12 |
| `product_segment.deleted` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-12 |
| `partner_segment.created` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-13 |
| `partner_segment.updated` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-13 |
| `partner_segment.deleted` | 1С → Сайт | `erp.events` | `erp_in.segments` | US-13 |
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
