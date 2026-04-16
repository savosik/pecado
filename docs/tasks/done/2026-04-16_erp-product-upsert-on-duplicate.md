# ERP: Upsert при повторной доставке product.created

**Приоритет:** высокий (P0)
**Исполнитель:** -
**Создано:** 2026-04-16

## Описание

При повторной доставке сообщения `product.created` обработчик падает с ошибкой дублирующегося ключа в нескольких таблицах:

```
Duplicate entry '...' for key 'product_models.product_models_external_id_unique'
Duplicate entry '...' for key 'brands.brands_slug_unique'
Duplicate entry '...' for key 'attribute_values.attribute_values_attribute_id_value_unique'
Duplicate entry '...' for key 'attribute_category.attribute_category_attribute_id_category_id_unique'
```

**Масштаб:** 1 315 failed-сообщений в очереди.

## Требования

- [x] `product_models` — заменить INSERT на `updateOrCreate(['external_id' => ...], [...])`
- [x] `brands` — заменить INSERT на `updateOrCreate(['slug' => ...], [...])`
- [x] `attribute_values` — заменить INSERT на `updateOrCreate(['attribute_id' => ..., 'value' => ...], [...])`
- [x] `attribute_category` — уже использовался `syncWithoutDetaching`, изменений не требовалось
- [x] Покрыть интеграционным тестом: отправить `product.created` дважды → продукт один, без ошибки

## Критерии готовности

- Повторная доставка `product.created` не приводит к ошибке ни по одной из таблиц
- Интеграционный тест проходит
