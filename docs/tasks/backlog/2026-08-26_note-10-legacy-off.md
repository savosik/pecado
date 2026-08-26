# note-10 — снятие зашитых листенеров

**Эпик:** `note-00`
**Зависит от:** `note-04`, `note-05`, `note-06`, `note-03`

## Что снимаем

| Листенер | Флаг |
|---|---|
| `SendOrderStatusChangedEmail` | `MAIL_FEATURE_ORDER_STATUS` |
| `SendOrdersPlacedEmail` | `MAIL_FEATURE_ORDER_CREATED` |
| `NotifyManagersAboutNewOrder` | `MAIL_FEATURE_MANAGER_ORDER` |
| `SendReturnCreatedEmail` | `MAIL_FEATURE_RETURN_CREATED` |
| `SendReturnStatusChangedEmail` | `MAIL_FEATURE_RETURN_STATUS` |
| `SendEntitySubscriptionNotifications` | `MAIL_FEATURE_ENTITY_SUBSCRIPTIONS` |

Плюс таблица `entity_subscriptions` после переноса (`note-03`).

## Порядок для каждого

1. убедиться, что матрица воспроизводит сегодняшнее поведение;
2. **выключить флаг** — роутер начинает слать в тот же миг, `LegacySenders`
   до этого момента держал его молча;
3. следующим релизом удалить листенер и флаг.

Порядок можно перепутать без вреда: `LegacySenders` (`bus-03`) делает дубль
невозможным. Обратная ошибка даёт тишину, а не дубль.

## Что остаётся зашитым навсегда

`SendWelcomeEmail`, `SendPasswordChangedEmail` — если настройка сломается,
человек обязан суметь войти.

## Проверка

- после снятия флага письмо уходит ровно одно;
- до снятия — тоже ровно одно (шлёт старый, роутер молчит);
- в момент переключения дубля не возникает.
