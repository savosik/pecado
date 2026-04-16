# ERP: Retry при deadlock в обработчике `product.created`

**Приоритет:** низкий
**Исполнитель:** -
**Создано:** 2026-04-16

## Описание

При параллельной обработке нескольких сообщений `product.created` возникает race condition на таблицах `attribute_values` и `product_models`. В результате MySQL выбрасывает deadlock и часть сообщений уходит в failed:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock;
try restarting transaction
(SQL: update `attribute_values` set ...)
```

**Масштаб:** 14 failed-сообщений. Ошибка редкая, но воспроизводится при массовой выгрузке каталога.

## Требования

- [x] Добавить retry-логику для обработки `SQLSTATE[40001]` в обработчике `product.created`.
- [x] Повторять обработку не менее 3 раз с backoff между попытками.
- [x] Допускается один из вариантов реализации:
  - через `DB::transaction()` с перехватом `\Illuminate\Database\QueryException` и проверкой кода `40001`;
  - через настройку очереди/джобы с `tries` и `backoff`, если это покрывает сценарий deadlock без побочных эффектов.
- [x] Убедиться, что повторная попытка не создаёт дубли данных и остаётся идемпотентной.

## Зависимости

Задача зависит от `2026-04-16_erp-product-upsert-on-duplicate.md`: сначала нужен upsert, затем retry.

## Критерии готовности

- Deadlock при обработке `product.created` не приводит к failed-сообщению, а обработка автоматически повторяется.
- После retry не появляются дубли в `product_models`, `attribute_values` и связанных данных.
