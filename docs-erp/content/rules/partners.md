# Партнёры

> **JSON Schema:** [`partner.created.json`](/docs/erp/schemas/partner.created.json) | [`partner.created.to_erp.json`](/docs/erp/schemas/partner.created.to_erp.json) | [`partner.deleted.json`](/docs/erp/schemas/partner.deleted.json)  
> **AsyncAPI:** [Полная спецификация](/docs/erp/spec.yaml)

## Направления обмена

| Событие | Направление | Очередь |
|---|---|---|
| `partner.created` | 1С → Сайт | `erp_in.partners` |
| `partner.created` | Сайт → 1С | `erp_out.partners` |
| `partner.deleted` | 1С → Сайт | `erp_in.partners` |

---

## partner.created (1С → Сайт)

### Бизнес-правила

- Партнёры без email **не выгружаются** (пропускаются)
- `country` никогда не `null` — фолбэк на `СтранаПоУмолчанию` из настроек (по умолчанию `"RU"`)
- `password` = CRC32-хеш от email (lowercase, trimmed), 8-символьная hex-строка
- `currency` всегда `"RUB"`
- При первом входе пользователь **обязан сменить пароль**

### Версионные изменения

- **(v8)** Поля `first_name`, `last_name`, `middle_name` удалены. Единое поле `name` (НаименованиеПолное)
- **(v10)** Поле `is_active` (boolean) — активность партнёра. `false` → пользователь не может оформлять заказы
- **(v11)** Поле `client_status` — код статуса клиента: `silver`, `gold`, `diamond`, `individual` или `null`. Резолвится через `ClientStatus.external_id`

### Критерии приёмки

- [ ] 1С публикует `partner.created` через `erp.events` → `erp_in.partners`
- [ ] Сайт создаёт/обновляет пользователя с `name` как единое поле
- [ ] При первом входе — обязательная смена пароля (`must_change_password`)
- [ ] Партнёры без email пропускаются при выгрузке
- [ ] При смене типового соглашения — повторный `partner.created` с обновлённым `client_status`
- [ ] При `client_status = null` — сброс `client_status_id` пользователя
- [ ] При неизвестном `client_status` — логирование предупреждения, текущий статус не меняется

---

## partner.created (Сайт → 1С)

### Бизнес-правила

- 1С ищет партнёра по email (`EmailПартнера`); если не найден — создаёт нового
- 1С **не использует** UUID из payload как свой идентификатор — генерирует свой UUID платформой

### Критерии приёмки

- [ ] Сайт публикует `partner.created` с данными партнёра через `erp_out.partners`
- [ ] Payload содержит: `event`, `message_id`, `uuid`, `login`, `name`, `email`
- [ ] `login = email`

---

## partner.deleted (1С → Сайт)

### Бизнес-правила

- Пользователь переводится в статус «Не активен» (`is_active = false`)
- Пользователь не может авторизоваться и оформлять заказы

### Критерии приёмки

- [ ] Сайт принимает `partner.deleted` и деактивирует пользователя
