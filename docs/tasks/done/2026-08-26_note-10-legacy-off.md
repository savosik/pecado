# note-10 — снятие зашитых листенеров

**Эпик:** `note-00`
**Статус:** сделано 2026-08-28
**Зависит от:** `note-04`, `note-05`, `note-06`, `note-03`

## Что снято

| Было | Стало |
|---|---|
| `SendOrdersPlacedEmail` (`MAIL_FEATURE_ORDER_CREATED`) | удалён — уведомление `orders.created` собирает `NotifyManagersAboutNewOrder` |
| `SendOrderStatusChangedEmail` (`MAIL_FEATURE_ORDER_STATUS`) | `CaptureOrderStatusChanged` — только захват в матрицу |
| `SendReturnCreatedEmail` (`MAIL_FEATURE_RETURN_CREATED`) | `CaptureReturnCreated` — только захват |
| `SendReturnStatusChangedEmail` (`MAIL_FEATURE_RETURN_STATUS`) | `CaptureReturnStatusChanged` — только захват |
| `SendEntitySubscriptionNotifications` (`MAIL_FEATURE_ENTITY_SUBSCRIPTIONS`) | удалён вместе с `EntityChanged`, `EntityChangeNotice`, `EntityChangedNotification` |
| `NotifyManagersAboutNewOrder` (`MAIL_FEATURE_MANAGER_ORDER`) | **остался** без флага: письмо сотруднику, гейт — `staff.order_created` в «Моих уведомлениях» |
| `LegacySenders` + проверка конфликта в `NotificationDispatcher` | удалены |
| Notification-классы клиентских писем и их blade-шаблоны (`mail.orders.{created,placed,status-changed}`, `mail.returns.*`, `mail.subscriptions.*`) | удалены |
| `order_statuses_to_notify_client` в `config/notifications.php` | удалён — подтип «о каких статусах писать» живёт в `mail_occasions.orders.status_changed` |

## Подписки кабинета (`entity_subscriptions`)

Снесены целиком: таблица (миграция `2026_08_28_100000_drop_entity_subscriptions_table`,
`down()` восстанавливает структуру), модель, `SubscriptionRegistry`, `config/subscriptions.php`,
`SubscriptionController`, CRUD-маршруты, `SubscriptionPanel.jsx` на страницах «Изменения заказов»
и «Документы», `User::subscriptions()`, источник «Подписка из кабинета» в `ContactSeeder`,
пункт в `crm:rop-handover`.

**Перенос живых подписок** делает миграция: активная email-подписка раздела «Заказы» →
строки `notification_preferences` по типам `orders.items_updated` / `orders.attributes_updated` /
`orders.shortfall` (с учётом сужения `events`), адресат `{"type":"email"}`, `is_enabled=true`,
`changed_by_client=true`. Логин партнёра **не** добавляется — старый механизм писал только на
подписанный адрес. Уже существующая строка партнёра не затирается, адрес дописывается.
Проверено на локальной копии прода: 5 активных подписок → 13 строк, неактивная пропущена,
подписка только на `api_shortfall` дала одну строку.

Ссылка отписки из старых писем (`/subscriptions/unsubscribe/{token}`) не отдаёт 404:
показывает страницу «ссылка больше не действует» со ссылкой на `/cabinet/notifications`.

## Защита от дубля менеджеру

Раньше её давал `LegacySenders`. Теперь, если партнёру в матрице поставили
`orders.created → менеджер`, `NotificationDispatcher::withoutManager()` выкидывает адрес
персонального менеджера из письма (о новом заказе ему уже пишет служебный листенер).
Остались только менеджер → письмо не уходит с причиной «уходит служебное письмо».
Тест: `tests/Feature/Notifications/OrderCreatedManagerDedupTest.php`.

## Что осталось зашитым навсегда

`SendWelcomeEmail`, `SendPasswordChangedEmail` (флаги `MAIL_FEATURE_WELCOME`,
`MAIL_FEATURE_PASSWORD_CHANGED`) — если настройка сломается, человек обязан суметь войти.

## После выкатки на прод

- Из `.env` прода можно убрать `MAIL_FEATURE_ORDER_*`, `MAIL_FEATURE_MANAGER_ORDER`,
  `MAIL_FEATURE_RETURN_*`, `MAIL_FEATURE_ENTITY_SUBSCRIPTIONS` — код их больше не читает.
- Миграция роняет таблицу: pre-deploy бэкап сделает CI (дамп при миграциях).
- Посмотреть `/crm/partners/{id}/notifications` у перенесённых партнёров — адреса должны
  стоять чипами у трёх типов «изменения заказа» с бейджем «настроил клиент».
