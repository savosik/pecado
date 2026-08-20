# notif-03 · Движок доставки

**Приоритет:** высокий
**Создано:** 2026-08-20
**Эпик:** [notif-00](2026-08-20_notif-00-epic.md)
**Волна:** 1 (ядро и заказы)
**Зависимости:** [notif-02](2026-08-20_notif-02-schema.md)
**Оценка:** ~3 дня

## Описание

Сердце эпика: Sieve-разбор правил, раскрытие получателей, гарды и отправка. Отправка появляется
здесь впервые, но боевых сигналов ещё нет — точки диспатча в доменном коде подключает `notif-04`.
Тестируется движок синтетическими сигналами.

## Что делаем

### Классы `app/Services/Notifications/Pulse/`

| Класс | Ответственность |
|---|---|
| `NotificationPulse` | Фасад, единственная публичная точка входа доменного кода: `signal(NotificationSignal $s)`. Проверяет `enabled`, `domains.*.enabled`, возраст сигнала, пишет `notification_signals`, диспатчит событие |
| `NotificationSignal` (DTO) | `uuid, eventKey, occurredAt, clientUserId, companyId, subject, data[], view[], attachments[], mode`. По смыслу совместим с существующим `EntityChangeNotice` — `view` принимает те же `rows[]` (`diff`/`action`/`note`) |
| `ProcessNotificationSignal` | Listener, `ShouldQueue`, `$afterCommit = true`, очередь `notifications`. Оркестрация: матчинг → Sieve → гарды → диспетчеризация |
| `RuleMatcher` | `whereIn('event_key', registry->matchKeys(...))`, `is_active`, область (`global` ∪ `scope_user_id` ∪ `scope_company_id` ∪ `scope_manager_id` менеджера клиента), сортировка `priority ASC, id ASC`. Кэш 60 с по `(event_key, user_id, company_id)`, инвалидация в `NotificationRuleObserver` |
| `RecipientResolver` | Раскрытие всех `kind` → `ResolvedRecipient[]` |
| `RecipientBag` | Дедуп по нормализованному адресу, «первое правило главнее», `suppress`, `is_fallback` |
| `SieveRunner` | Собственно Sieve-семантика |
| `DeliveryGuard` | Стоп-лист, отписки, троттлинг, валидность адреса, лимиты. Каждый отказ = строка журнала |
| `PulseDispatcher` | `insertOrIgnore` в `notification_deliveries` → `Notification::route('mail', $email)->notify(...)` |
| `PulseNotification` | `ShouldQueue`, `$tries = 3`, `$backoff = [30,120,300]`, очередь `notifications`, `via()` по `channel` |
| `NotificationRenderer` | Выбор шаблона, плейсхолдеры темы, вложения |
| `PulsePreviewService` | Тот же путь до `DeliveryGuard`, но `mode='dry_run'` — «кто получит» без отправки |
| `PulseMode` | Единственный источник правды о режиме: `PulseMode::handles(string $eventKey): bool`. Читает и движок, и (в `notif-04`) старые листенеры |

### Sieve-семантика буквально

```php
$bag = new RecipientBag();

foreach ($this->matcher->rulesFor($signal) as $rule) {   // priority ASC, id ASC
    if (! $this->evaluator->matches($rule->conditions, $signal->data)) {
        continue;
    }

    $rule->registerMatch();                               // last_matched_at, matched_count

    // Получатели правила применяются ДО остановки — как stop в Sieve:
    // «сделай своё действие и не смотри дальше», а не «ничего не делай».
    $bag->apply($rule, $this->resolver->resolve($rule, $signal));

    if ($rule->stop_processing) {
        break;
    }
}
```

Три следствия, которые надо объяснить менеджеру в UI (`notif-05`):

- срабатывают **все** совпавшие правила — заказчик просил именно это;
- дубль получает письмо один раз, по правилу с наименьшим приоритетом — оно «главнее»
  и определяет шаблон, тему и запись в журнале;
- правило-исключение возможно: правило с малым приоритетом и получателем `kind=suppress`
  вычёркивает адрес, который добавили бы правила ниже (Sieve `discard` для одного адресата).

**Кейс Пупкина** в этих терминах — приёмочный тест карточки:

| Приоритет | Событие | Условие | Получатели | stop |
|---|---|---|---|---|
| 50 | `orders.status_changed` | `status = closed` | контакт «Залупкин, директор» | **да** |
| 100 | `orders.status_changed` | — | `client_user` | нет |
| 100 | `orders.shortfall` | — | контакты «Жопкин», «Петров» | нет |

Закрытие заказа уходит директору и **не** уходит пользователю (правило 50 остановило разбор);
любой другой статус падает на правило 100.

### Раскрытие получателей

`RecipientResolver` с под-резолверами на каждый `kind`:

- `contact` — карточка из адресной книги. **Обязательно сверяет принадлежность** контакта
  `client_user_id`/`company_id` сигнала: иначе письмо о финансах уйдёт контакту чужого
  контрагента. Тест на это обязателен.
- `contact_role` — все активные контакты роли у контрагента события.
- `email` — произвольный адрес.
- `client_user` — email аккаунта партнёра.
- `company_email` — `companies.email`.
- `personal_manager` — переиспользует существующую цепочку целиком:
  `$order->user?->personalManager` → `ManagerAbsenceResolver->effectiveManager($card)->email ?: $card->email`.
  Замещение на время отсутствия менеджера сохраняется один в один, включая фолбэк на самого
  менеджера при пустом email замещающего.
- `config_list` — только ключи из белого списка `config_recipient_lists`.
- `suppress` — вычёркивание адреса или роли.

`OrderManagerRouting::recipients()` **остаётся жить** как тонкая обёртка над
`ManagerRecipientResolver`, чтобы не переписывать всех вызывающих в этой карточке.

### Гарды

`DeliveryGuard` последовательно проверяет и на каждом отказе пишет строку
`notification_deliveries` со `status='skipped'` и конкретным `skip_reason`:

| Проверка | `skip_reason` |
|---|---|
| адрес в `notification_suppressions` | `suppressed` / `unsubscribed` |
| нет `marketing_consent` для домена `campaigns.*` | `no_consent` |
| `throttle_seconds` правила по этому адресу | `throttled` |
| адрес не проходит валидацию | `invalid_email` |
| режим `shadow` / `dry_run` | `shadow` / `dry_run` |
| глобальный лимит писем в минуту | `rate_limited` |
| сигнал старше `max_signal_age_minutes` | `too_old` |
| домен выключен | `feature_off` |

**Возрастной ценз — главный предохранитель эпика**: бэкфилл истории (первичная выгрузка
документов, пересчёт балансов) физически не может разослать письма.

### Отправка

`to` всегда **один адрес** — несколько получателей значит несколько писем. Принцип действующего
`NotifyManagersAboutNewOrder` («список в одном `to` показал бы получателям адреса друг друга»)
сохраняется. `cc`/`bcc` — только явно заданные в правиле.

Шаблон `resources/views/mail/pulse/default.blade.php` — универсальный, умеет `rows[]`
из `view` по образцу существующего `mail/subscriptions/entity-changed.blade.php`
(палитра `added #2f9e6b`, `removed #d24d57`, `modified #e08a1e`, `shortfall/partial #8e5bd0`,
фолбэк на построчный вывод). Ссылка отписки в подвале.

### Связь с журналом писем

Механика — копия работающего приёма `MailClientTag`:

1. `PulseNotification::toMail()` ставит заголовок `X-Pecado-Delivery` со значением
   `notification_deliveries.id` (и по-прежнему `X-Pecado-Client` через `MailClientTag`).
2. `app/Listeners/LogSentEmail.php` читает заголовок, проставляет
   `sent_emails.notification_delivery_id` и обновляет доставку: `status='sent'`, `sent_at`,
   `message_id`.

`message_id` дублируется в `notification_deliveries` копией — журнал доставок живёт 365 дней,
`sent_emails` 180, и после прунинга ссылка на письмо в почтовом логе не должна теряться.

### Очередь

Новая очередь `notifications` и супервизор в `config/horizon.php` (`maxProcesses` 3/2/1 по
окружениям, как у существующих) плюс запись в `docker/supervisor/conf.d/worker.conf`.

Сейчас **все** уведомления валятся в `default` вместе с ERP-джобами и экспортами: всплеск
рассылки задержал бы обработку шины. Это пункт DoD, а не опция.

### Наблюдаемость

- `php artisan notifications:explain --signal=<uuid>` — какие правила рассматривались, какие
  совпали и почему, где сработал `stop`, кто отсеян и по какой причине.
- `php artisan notifications:simulate --event=orders.status_changed --order=123` — dry-run.

## Критерии готовности

- [ ] Все классы движка; `SieveRunner` реализует семантику «действие до остановки»
- [ ] `SieveOrderTest` — кейс Пупкина целиком: закрытие → директору, клиенту ничего; прочий статус → клиенту
- [ ] `RecipientResolutionTest` — все `kind`; замещение менеджера, включая пустой email замещающего → фолбэк; `config_list` только из белого списка
- [ ] Тест: контакт чужого контрагента **не** попадает в получатели
- [ ] `DeduplicationTest` — адрес в двух правилах = ровно одно письмо; повторный запуск job не даёт второе (проверка уникального индекса)
- [ ] `DeliveryJournalTest` — параметризован по всем `skip_reason`: на каждый отказ есть строка журнала
- [ ] `SentEmailLinkTest` — `mail.default=array`, реальная отправка, заголовок доезжает, `sent_emails.notification_delivery_id` проставлен, доставка в статусе `sent`
- [ ] `RateLimitTest` — глобальный лимит и возрастной ценз сигнала
- [ ] Очередь `notifications` в Horizon и Supervisor; `PulseNotification` уходит именно в неё
- [ ] Шаблон `mail.pulse.default` включён в `MailLayoutSmokeTest` (бренд `#9e1b32`, логотип, футер)
- [ ] `notifications:explain` и `notifications:simulate` работают
- [ ] `make lint` и `make test` зелёные
