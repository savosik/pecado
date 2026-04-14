# Фаза 3: Сквозные сценарии

---

## 3.1 — Полный жизненный цикл заказа

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Пользователь оформляет заказ | `order.created` в `erp_out.orders`, статус `pending` |
| 2 | 🔵 1С | Подтверждает → `order.updated` `confirmed` | Статус → `confirmed`, запись в `order_status_histories` |
| 3 | 🔵 1С | В работу → `order.updated` `processing` | Статус → `processing` |
| 4 | 🔵 1С | Изменил позиции → `order.updated` с `items[]` | Позиции пересозданы, diff в `order_change_logs` |
| 5 | 🔵 1С | Реализация → `shipment.created` | Реализация в ЛК |
| 6 | 🔵 1С | Завершает → `order.updated` `completed` | Статус → `completed` |
| 7 | 🔵 1С | Баланс → `balance.updated` | Баланс обновлён |

**Итоговая проверка:**

- [ ] `order_status_histories`: `pending` → `confirmed` → `processing` → `completed`
- [ ] `order_change_logs`: изменения позиций зафиксированы
- [ ] Реализация привязана к заказу
- [ ] Баланс отражает задолженность

---

## 3.2 — Жизненный цикл партнёра

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🟢 Сайт | Регистрация | `partner.created` → `erp_out.partners` |
| 2 | 🔵 1С | `partner.created` с `client_status: "silver"` | Статус Silver |
| 3 | 🔵 1С | Повышение → `client_status: "gold"` | Статус → Gold |
| 4 | 🔵 1С | Инд. → `client_status: "individual"` | Статус → Индивидуальный |
| 5 | 🔵 1С | Убирает → `client_status: null` | Статус сброшен |
| 6 | 🔵 1С | Деактивация → `partner.deleted` | `is_active = false` |

---

## 3.3 — Цепочка: Реализация → Баланс

| Шаг | Кто | Действие | Проверка |
|-----|-----|----------|----------|
| 1 | 🔵 1С | `shipment.created` | Реализация появилась |
| 2 | 🔵 1С | `balance.updated` с `overdue_details` | Просрочка детализирована |
| 3 | 🔵 1С | `shipment.updated` (перепроведение) | Реализация обновлена |
| 4 | 🔵 1С | `balance.updated` с новой суммой | Баланс пересчитан |
