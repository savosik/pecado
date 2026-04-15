# Исправление сохранения адреса доставки при синхронизации заказа

**Приоритет:** критический
**Исполнитель:** -
**Создано:** 2026-04-15
**Завершено:** 2026-04-15

## Описание

Адрес доставки в заказе хранился через FK `delivery_address_id → delivery_addresses`.
Это было архитектурно неверно — адрес заказа должен быть зафиксирован на момент оформления.

## Что сделано

- [x] **Миграция:** Добавлено текстовое поле `delivery_address`, данные скопированы из `delivery_addresses.address`, FK `delivery_address_id` удалён
- [x] **Модель Order:** `delivery_address` в `fillable`, удалён relationship `deliveryAddress()`
- [x] **CheckoutServiceInterface + CheckoutService:** `DeliveryAddress $address` → `string $deliveryAddress`
- [x] **StoreCheckoutRequest:** Упрощён до одного поля `delivery_address` (required|string|max:1000)
- [x] **CheckoutController:** Убрана логика поиска/создания DeliveryAddress, передаётся строка
- [x] **HandleOrderCreated:** Уже сохранял `delivery_address` как текст — без изменений
- [x] **HandleOrderUpdated:** Уже обновлял `delivery_address` как текст — без изменений
- [x] **PublishOrderToErp:** Уже отправлял `$order->delivery_address` как строку — без изменений
- [x] **Frontend (3 файла):** Все Show-страницы обновлены — `order.delivery_address` теперь строка, не объект
- [x] **Frontend Checkout:** Единое текстовое поле `delivery_address` вместо `delivery_address_id` + `new_address`
- [x] **Тесты:** HandleOrderCreatedTest, PublishOrderToErpTest, CheckoutControllerTest обновлены
- [x] **Документация:** AsyncAPI spec, changelog, rules обновлены
- [x] **Все 642 теста проходят**
