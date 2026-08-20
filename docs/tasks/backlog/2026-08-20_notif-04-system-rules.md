# notif-04 · Системные правила и теневой режим

**Приоритет:** высокий
**Создано:** 2026-08-20
**Эпик:** [notif-00](2026-08-20_notif-00-epic.md)
**Волна:** 1 (ядро и заказы)
**Зависимости:** [notif-03](2026-08-20_notif-03-engine.md)
**Оценка:** ~2 дня

## Описание

Самая рискованная карточка эпика: здесь пульт впервые видит боевые события. Задача — сделать
так, чтобы **ни одно письмо не потерялось и ни одно не задублировалось**, и чтобы это можно было
доказать отчётом, а не надеждой.

Решение №3 эпика: текущая зашитая маршрутизация становится набором системных правил. Это лучше
фолбэка «если правил нет — работает старый код»: дефолтное поведение перестаёт быть невидимым,
менеджер видит в пульте полную картину, включая то, почему письмо ушло по умолчанию.

## Что делаем

### Точки диспатча сигналов

Одна строка `NotificationPulse::signal(...)` там, где событие уже фиксируется:

| Где | Сигнал |
|---|---|
| `app/Models/OrderChangeLog.php` (`static::created`) | `orders.items_updated`, `orders.attributes_updated`, `orders.shortfall` — по `type`. Единая точка для ERP/админки/API, всё идёт через `OrderChangeLogger` |
| `app/Listeners/…` на `OrdersPlaced` | `orders.created` |
| `app/Listeners/…` на `OrderUpdated` + `wasChanged('status')` | `orders.status_changed` |
| `app/Services/Substitution/ShortageEmailDraftService.php` | `orders.substitution_offered` |
| создание `Shipment` | `orders.shipped` |

Существующий `EntityChanged` пока **не трогаем** — он живёт параллельно до `notif-09`.

### Системные правила

Идемпотентный `NotificationSystemRulesSeeder` + команда `notifications:sync-system-rules`.
Синхронизация по `system_key`; **`is_active`, `priority` и получатели, изменённые руками,
не перетираются** — синхронизируются только название, описание и структура. Иначе очередной
деплой молча вернул бы включённым то, что РОП сознательно выключил.

| `system_key` | Событие | Условия | Получатели | Заменяет |
|---|---|---|---|---|
| `sys.orders.created.client` | `orders.created` | `order_type != promo_sample` | `client_user` | `SendOrdersPlacedEmail` |
| `sys.orders.created.manager` | `orders.created` | — | `personal_manager`; **fallback** `config_list: order_fallback_recipients` | `NotifyManagersAboutNewOrder` + `OrderManagerRouting` |
| `sys.orders.status_changed.client` | `orders.status_changed` | `from_erp = true` И `status in [ready_for_shipment, shipping, awaiting_payment, closed]` | `client_user` | `SendOrderStatusChangedEmail` + whitelist из конфига |
| `sys.orders.shortfall.manager` | `orders.shortfall` | — | `personal_manager` | `ShortageManagerNoticeNotification` |
| `sys.orders.substitution_offered.client` | `orders.substitution_offered` | — | `client_user` | часть `ShortageEmailDraftService` |
| `sys.returns.created.client` / `.manager` | `system.return_created` | — | `client_user` / `personal_manager` | `SendReturnCreatedEmail` |
| `sys.returns.status_changed.client` | `system.return_status_changed` | — | `client_user` | `SendReturnStatusChangedEmail` |
| `sys.questions.received.client` / `.staff` | `system.question_received` | — | `client_user` / `config_list: user_question_recipients` | `QuestionReceived` / `NewQuestionAdmin` |

**Приоритеты 400–600.** Пользовательские правила по умолчанию 100, то есть разбираются заведомо
раньше и могут перебить системное через `stop`.

Удаление запрещено политикой. В UI (`notif-05`) доступны тумблер и «Переопределить» — создаёт
копию с меньшим приоритетом и включённым `stop`.

**Whitelist статусов** уезжает в условие правила. Конфиг `order_statuses_to_notify_client`
остаётся источником для первичного сидирования, в него дописывается комментарий «значение
перенесено в системное правило `sys.orders.status_changed.client`, конфиг читает только сидер».
Движок конфиг в рантайме **не читает** — иначе правило врало бы менеджеру о своём поведении.

**Резервные адреса остаются в ENV**: получатель `kind=config_list` с ключом из белого списка.
Аварийные адреса не размазываются по БД, UI показывает их значение только для чтения.

`system.*` события (welcome, смена пароля, вопросы) в этой волне заводятся, но **сигналов из кода
для welcome и смены пароля не ставим** — они показываются в пульте read-only записями
«зашито в код». Полный перенос — `notif-14`. Экономит 2–3 дня без потери смысла.

### Двойной рубильник

`PulseMode` — единственный источник правды. В **каждом** старом листенере первой строкой:

```php
// Событие переведено на пульт уведомлений — здесь молчим,
// иначе клиент получил бы два письма об одном и том же.
if (PulseMode::handles('orders.status_changed')) {
    return;
}
```

Один флаг управляет обеими сторонами, поэтому двойная отправка невозможна **по конструкции**,
а не по внимательности. Это ключевое инженерное решение карточки.

10 feature-флагов на время перехода **не трогаем**: они гейтят старые листенеры, пульт гейтится
своими `mode`/`live_events`. Два независимых рубильника — это возможность откатиться одной
переменной окружения.

### Инструменты сверки

`php artisan notifications:compare --days=7` — сопоставляет теневые доставки с `sent_emails`
за тот же период по ключу (клиент, событие, адрес) и печатает три списка:

1. «пульт бы отправил, а не отправлено»;
2. «отправлено, а пульт бы не отправил»;
3. «совпало».

Переключения в live нет, пока первые два списка не пусты или расхождение не объяснено
и не принято осознанно.

## Порядок работ

1. Точки диспатча + системные правила, `NOTIFICATION_PULSE_MODE=shadow`.
2. Гейты `PulseMode::handles()` во всех старых листенерах.
3. Деплой на dev, неделя теневого прогона.
4. `notifications:compare --days=7` — разбор расхождений до пустого отчёта.
5. Перевод в live — карточка `notif-07`.

## Критерии готовности

- [ ] Сигналы диспатчатся из всех перечисленных точек; `EntityChanged` работает параллельно
- [ ] `NotificationSystemRulesSeeder` + `notifications:sync-system-rules` идемпотентны
- [ ] Тест: выключенное вручную системное правило переживает повторный запуск синхронизации
- [ ] Тест: удалить системное правило нельзя (политика)
- [ ] `PulseMode` + гейты во **всех** старых листенерах (проверить grep'ом по `app/Listeners/`)
- [ ] Сидер читает текущее значение `MAIL_FEATURE_*`: флаг выключен на проде → правило создаётся выключенным
- [ ] `notifications:compare` печатает три списка и различает расхождения
- [ ] `LegacyParityTest` — сравнение получателей старого листенера и пульта по 7 сценариям: новый заказ, смена статуса из 1С, ручная правка админа, изменение состава, недобор, возврат, вопрос с сайта
- [ ] `ShadowModeTest` — в shadow пульт не отправляет ничего, но пишет доставки; старый листенер отправляет
- [ ] На dev неделя shadow, отчёт сверки пустой (зафиксировать в комментарии к карточке)
- [ ] `make lint` и `make test` зелёные
