# notif-14 · Удаление старых листенеров и feature-флагов

**Приоритет:** низкий
**Создано:** 2026-08-20
**Эпик:** [notif-00](2026-08-20_notif-00-epic.md)
**Волна:** 4 (рассылки и уборка)
**Зависимости:** [notif-07](2026-08-20_notif-07-orders-live.md), [notif-09](2026-08-20_notif-09-import-subscriptions.md)
**Оценка:** ~1 день

## Описание

Уборка после переезда. К этому моменту старые листенеры молчат по гейту `PulseMode::handles()`
уже несколько недель, и их существование только путает: читающий код видит два пути отправки
и не понимает, какой рабочий.

Карточку нельзя брать раньше, чем закончится обкатка: пока живы старые листенеры, откат
делается одной переменной окружения. После этой карточки откат — только релизом.

## Предусловия

- [ ] Все события `orders.*` в live не меньше двух недель, расхождений в сверке нет
- [ ] `notif-09` отработал: подписки перенесены, `SubscriptionCrudTest` зелёный
- [ ] Журнал доставок за период не содержит необъяснённых `skipped`

## Что делаем

### Удаление листенеров

| Файл | Заменён на |
|---|---|
| `app/Listeners/SendOrdersPlacedEmail.php` | `sys.orders.created.client` |
| `app/Listeners/NotifyManagersAboutNewOrder.php` | `sys.orders.created.manager` |
| `app/Listeners/SendOrderStatusChangedEmail.php` | `sys.orders.status_changed.client` |
| `app/Listeners/SendReturnCreatedEmail.php` | `sys.returns.created.*` |
| `app/Listeners/SendReturnStatusChangedEmail.php` | `sys.returns.status_changed.client` |
| `app/Listeners/SendWelcomeEmail.php` | `sys.auth.welcome` |
| `app/Listeners/SendPasswordChangedEmail.php` | `sys.auth.password_changed` |
| `app/Listeners/SendEntitySubscriptionNotifications.php` | движок пульта целиком |

Вместе с регистрациями в `app/Providers/AppServiceProvider.php` (строки ~221–261).
Проверить `tests/Feature/Listeners/NoDuplicateListenersTest.php` — он следит за дублями
регистраций и должен остаться зелёным.

`app/Events/EntityChanged.php`, `app/Subscriptions/EntityChangeNotice.php`,
`app/Notifications/EntityChangedNotification.php` — удаляются вместе с последним потребителем.
Шаблон `mail/subscriptions/entity-changed.blade.php` — после проверки, что `mail.pulse.default`
покрывает его вёрстку `rows[]`.

### Перенос `system.*` событий

В волне 1 сознательно отложено: welcome, смена пароля и вопросы с сайта показывались в пульте
read-only записями «зашито в код». Здесь они получают реальные сигналы и системные правила
по образцу заказов.

`ResetPasswordNotification` **остаётся вне пульта**: это письмо безопасности, оно не должно
зависеть ни от правил, ни от режима движка. Единственное клиентское письмо без feature-флага —
и это правильно.

`HealthCheckFailedNotification` тоже остаётся: техническое письмо мониторинга,
адресаты в `HEALTH_NOTIFY_EMAIL`. Заодно исправить личный email в дефолте `config/health.php` —
при пустом ENV письма уходят на личный ящик.

### Feature-флаги

8 из 10 удаляются, значение каждого переносится в `is_active` соответствующего системного правила:

| Флаг | Куда |
|---|---|
| `MAIL_FEATURE_WELCOME` | `sys.auth.welcome.is_active` |
| `MAIL_FEATURE_PASSWORD_CHANGED` | `sys.auth.password_changed.is_active` |
| `MAIL_FEATURE_ORDER_CREATED` | `sys.orders.created.client.is_active` |
| `MAIL_FEATURE_ORDER_STATUS` | `sys.orders.status_changed.client.is_active` |
| `MAIL_FEATURE_MANAGER_ORDER` | `sys.orders.created.manager.is_active` |
| `MAIL_FEATURE_RETURN_CREATED` | `sys.returns.created.*.is_active` |
| `MAIL_FEATURE_RETURN_STATUS` | `sys.returns.status_changed.client.is_active` |
| `MAIL_FEATURE_ENTITY_SUBSCRIPTIONS` | исчезает вместе с листенером |

**Остаются два:**

- `notification_pulse.enabled` — полный стоп-кран всего исходящего потока;
- `crm_outbound` — письма менеджера из CRM, другая труба (`CrmManagerMail`), пульт её не трогает.

`MAIL_FEATURE_CRM_TASKS` гейтит внутренние письма сотрудникам о задачах — в пульт не переезжает
(адресат — сотрудник, а не клиент; правил на это заводить незачем). Остаётся как есть.

Убрать переменные из `.env.example` и с серверов — на прод через
`.github/workflows/deploy-prod.yml`, руками на сервере правки затрёт rsync.

### Режим

`NOTIFICATION_PULSE_MODE=live`, `NOTIFICATION_PULSE_LIVE_EVENTS` удаляется как понятие —
`PulseMode::handles()` начинает возвращать `true` для всех включённых доменов.

`config/notifications.php` чистится: `order_statuses_to_notify_client` удаляется (значение
живёт в условии системного правила), списки резервных адресов остаются — на них ссылается
`kind=config_list`.

## Критерии готовности

- [ ] Восемь листенеров и их регистрации удалены; `NoDuplicateListenersTest` зелёный
- [ ] `EntityChanged`, `EntityChangeNotice`, `EntityChangedNotification` удалены
- [ ] `system.*` события получили сигналы и системные правила
- [ ] `ResetPasswordNotification` и `HealthCheckFailedNotification` не тронуты; личный email в `config/health.php` заменён
- [ ] 8 флагов удалены, значения перенесены в `is_active` правил миграцией
- [ ] Переменные убраны из `.env.example` и из `deploy-prod.yml`
- [ ] `mode=live`, `live_events` больше не используется
- [ ] `order_statuses_to_notify_client` удалён из конфига
- [ ] Полный прогон: письма по всем доменам уходят, дублей нет
- [ ] `make verify` зелёный

---

## Состояние на 21.08.2026 — сделана только безопасная половина

**Сделано:** события `system.return_created`, `system.return_status_changed`,
`system.question_received` заведены в реестре, сигналы диспатчатся, системные
правила созданы (всего их девять).

**Не сделано и брать нельзя:** удаление старых листенеров и feature-флагов.

Причина: пульт работает в режиме `shadow`, `NOTIFICATION_PULSE_LIVE_EVENTS`
пуст — то есть письма по-прежнему шлют прежние листенеры. Удалить их сейчас
значит остаться совсем без почты.

Порядок такой: `notif-07` (перевод заказов в live) → неделя наблюдения →
перевод остальных событий → и только потом эта карточка.
