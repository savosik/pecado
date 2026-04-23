# Чистка «пустых» inline-записей product_attribute_values

## Контекст

`/api/catalog/products/facets` на dev падал с 500 (`Undefined array key "raw_value"` в `CatalogFacetService::computeAttributeFacets`). Причина: у `type=select` атрибута в `product_attribute_values` есть записи одновременно двух видов — с `attribute_value_id` (нормальные) и без него с `text_value = ''` (мусор). Последние попадают в «inline-ветку» facets-сервиса, но у них нет `raw_value` — usort падает.

Hotfix: fallback в компараторе (`raw_value ?? value ?? 0`) — коммит `f682003`. 500 уходит, но данные остаются грязными.

## Откуда берутся мусорные записи

`app/Services/Erp/Handlers/HandleProductCreated.php:161-295` и `HandleProductUpdated.php` (аналогично):

1. Если в payload-атрибуте нет `value_uuid` (строка 225) — `$attributeValueId` остаётся `null`.
2. Если `value_label` тоже пуст/null — в ветке `else` (строка 286) пишется `'text_value' => (string) ('')` = `''`.
3. `updateOrCreate` создаёт запись `(product_id, attribute_id, attribute_value_id=NULL, text_value='', ...)`.

Для `type='select'` атрибута такая запись — мусор: она не попадает ни в select-цикл (нет `attribute_value_id`), ни в inline-цикл с осмысленным значением (text_value пустое).

## Смешанные атрибуты на dev (snapshot 2026-04-23)

| id  | name                            | type   | filterable | select rows | inline rows |
|-----|---------------------------------|--------|------------|-------------|-------------|
| 307 | Условия хранения и обработки    | select | 0          | 2288        | 2           |
| 308 | Изготовитель                    | select | 0          | 7651        | 2           |
| 313 | Страна происхождения            | select | **1**      | 9209        | **32**      |
| 324 | Коллекция                       | select | 0          | 52          | 70          |
| 333 | Питание основного устройства    | select | **1**      | 1466        | **5**       |
| 353 | Эффект                          | select | **1**      | 551         | **1**       |
| 396 | Пол                             | select | **1**      | 401         | **2**       |
| 478 | Принципал                       | select | 0          | 64          | 1           |

Всего inline-строк-мусора: ~115. Все `text_value = ''`, `number_value/boolean_value = NULL`, `updated_at` около 2026-04-17 (массовый импорт).

## Что нужно сделать

### 1. Фикс в handlers (ERP)

В `HandleProductCreated::` и `HandleProductUpdated::` при обработке атрибута:

- Если `value_uuid` пуст **и** `value_label` пуст/null — **не вызывать** `ProductAttributeValue::updateOrCreate`, а наоборот — если запись уже есть, удалить.
- Опционально: для `type='select'` атрибутов (`$siteType === 'select'`) без `value_uuid` — просто пропускать/удалять, даже если `value_label` непуст (инлайн-значение у select-атрибута бессмысленно).

### 2. Одноразовая чистка БД

Миграция (новая, не править старые — см. `.claude/rules/migration-rule.md`):

```sql
DELETE pav FROM product_attribute_values pav
JOIN attributes a ON a.id = pav.attribute_id
WHERE a.type = 'select'
  AND pav.attribute_value_id IS NULL
  AND (pav.text_value IS NULL OR pav.text_value = '')
  AND pav.number_value IS NULL
  AND pav.boolean_value IS NULL
  AND pav.datetime_value IS NULL;
```

Перед выполнением — сделать dry-run `SELECT COUNT(*)` по тем же условиям.

### 3. Опционально — убрать hotfix

После того как данные вычищены и handler не пишет мусор, fallback в `CatalogFacetService::305-323` можно не трогать — он безвреден и защищает от будущих регрессий.

### 4. Интеграционные тесты

По `.claude/rules/integration-tests.md`, поскольку это ERP-handler:

- `HandleProductCreatedTest`: payload с атрибутом без `value_uuid` и без `value_label` → запись в `product_attribute_values` **не создаётся**.
- `HandleProductUpdatedTest`: существующая запись с `text_value=''` **удаляется** при обновлении.
- Тест на `CatalogFacetService`: атрибут со смесью select+inline записей корректно возвращает facets без ошибки.

## Связанные задачи

- `docs/tasks/todo/2026-04-22_1c-attributes-full-replace-migration.md` — там уже идёт работа по семантике `attributes[]` в payload (full-replace). Логически фикс «не писать пустые атрибуты» хорошо ложится в тот же PR.

## Критерии готовности

- [ ] Handlers не создают pivot-записей с пустыми значениями.
- [ ] Миграция-чистка применена на dev (и потом на prod).
- [ ] Интеграционные тесты зелёные.
- [ ] После деплоя `SELECT COUNT(*) FROM product_attribute_values WHERE attribute_value_id IS NULL AND type в select` = 0.
