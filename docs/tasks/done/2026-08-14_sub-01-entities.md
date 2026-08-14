# sub-01: сущности и миграции домена замен

**Приоритет:** высокий
**Эпик:** [sub-00](2026-08-14_sub-00-epic.md)
**Создано:** 2026-08-14

## Описание

Фундамент домена: четыре новые таблицы + два поля связи на существующих.
Только схема, модели и фабрики — без бизнес-логики.

### `substitution_offers` — «подборка замен по заказу», одна на заказ

| Поле | Тип | Комментарий |
|---|---|---|
| `uuid` | char(36), unique | Идентификатор для signed URL |
| `order_id` | FK orders | Исходный заказ с недобором |
| `user_id`, `company_id` | FK, nullable | Снимок адресата на момент создания |
| `manager_user_id` | FK users, nullable | Персональный менеджер, ответственный за оффер |
| `status` | string | `pending` → `viewed` → `confirmed` / `expired` / `dismissed` |
| `dismiss_reason` | string, nullable | Причина закрытия без замены |
| `expires_at` | timestamp | По умолчанию +7 дней |
| `viewed_at`, `confirmed_at` | timestamp, nullable | Вехи воронки |
| `result_order_ids` | json, nullable | Созданные заказы-замены (их может быть два: обычный + defect) |

### `substitution_offer_items` — строки подборки (кандидаты)

| Поле | Тип | Комментарий |
|---|---|---|
| `offer_id` | FK | |
| `source_order_item_id` | FK order_items | Отменённая строка, которую закрываем |
| `product_id` | FK products, nullable | Кандидат-товар |
| `product_defect_id` | FK product_defects, nullable | Кандидат-партия уценки (слой 0.5); ровно одно из двух полей заполнено |
| `kind` | string | Слой подбора: `same_product_wait`, `defect_same`, `linked`, `variant`, `line`, `functional`, `category_price`, `semantic`, `manual` |
| `reason` | string | Человекочитаемое объяснение — показывается клиенту |
| `price_snapshot` | decimal(15,2) | Индивидуальная цена клиента на момент формирования |
| `suggested_quantity` | int | = отменённому количеству (или доступному остатку, если меньше) |
| `removed_by_manager_at` | timestamp, nullable | Менеджер снял галочку — негативный сигнал для пары |
| `chosen` | bool | Выбор клиента |
| `chosen_quantity` | int, nullable | ≤ suggested_quantity (кап — см. эпик) |

Уникальный индекс `(offer_id, source_order_item_id, product_id, product_defect_id)` —
идемпотентность при повторной генерации.

### `product_substitutions` — справочник связей замены

| Поле | Тип | Комментарий |
|---|---|---|
| `from_product_id`, `to_product_id` | FK products | Связь **направленная**: дешёвое вместо дорогого — можно, наоборот — часто нет |
| `kind` | string | `variant`, `line`, `equivalent`, `downgrade`, `upgrade`, `analog_volume` |
| `source` | string | `manual` (менеджер), `learned` (клиент согласовал), `ai` (предразметка, требует подтверждения) |
| `score` | tinyint | Уверенность 0–100 |
| `note` | string, nullable | Заготовка причины для клиента |
| `confirmed_at` | timestamp, nullable | Подтверждение человеком (обязательна для `ai` до использования) |
| `rejected_at` | timestamp, nullable | Отклонена — не предлагать и не создавать заново |
| `created_by` | FK users, nullable | |

Уникальный индекс `(from_product_id, to_product_id)`.

### `substitution_events` — негативные/позитивные сигналы (обучение)

`offer_item_id`, `event` (`manager_removed`, `client_chosen`, `client_skipped`),
`created_at`. Плоский лог для тюнинга слоёв — можно позже, но дешевле заложить сразу.

### Поля на существующих таблицах (новые миграции!)

- `orders.replacement_for_order_id` — nullable FK orders: «этот заказ — замена недоборов такого-то».
- `order_items.replaces_order_item_id` — nullable FK order_items: какая новая строка закрывает какую отменённую.

## Критерии готовности

- [ ] Миграции с комментариями таблиц и всех столбцов на русском (правило db-comments; enum-значения перечислены в comment)
- [ ] Только новые миграции, старые не тронуты
- [ ] Модели: `SubstitutionOffer`, `SubstitutionOfferItem`, `ProductSubstitution` + связи в `Order`, `OrderItem`, `Product`
- [ ] Фабрики для всех новых моделей
- [ ] После наката на dev — `php artisan bi:sync-grants` (иначе ИИ-агент менеджеров не увидит таблицы)
- [ ] `db:comments:audit --strict` зелёный
