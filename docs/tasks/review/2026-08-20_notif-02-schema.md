# notif-02 · Схема пульта и реестр событий

**Приоритет:** высокий
**Создано:** 2026-08-20
**Эпик:** [notif-00](2026-08-20_notif-00-epic.md)
**Волна:** 1 (ядро и заказы)
**Зависимости:** [notif-01](2026-08-20_notif-01-contacts.md) — правила ссылаются на контакты
**Оценка:** ~3 дня

## Описание

Фундамент домена: таблицы правил, реестр событий и движок условий. **Отправки в этой карточке
нет вообще** — ни одной строки, способной послать письмо. Это сознательно: схему и условия
можно полностью покрыть тестами, не рискуя разослать почту, а рецензент карточки видит
ограниченную диффу.

## Что делаем

### Миграции

**`notification_rules`**

```php
$table->comment('Правило маршрутизации уведомлений: событие + условия + получатели. Правила упорядочены приоритетом и обрабатываются как почтовые фильтры (Sieve): срабатывают все совпавшие, флаг stop_processing прерывает дальнейший разбор');

$table->id()->comment('Первичный ключ');
$table->string('name', 191)->comment('Название правила для списка в пульте');
$table->text('description')->nullable()->comment('Пояснение менеджера: зачем правило заведено');

$table->string('event_key', 64)->comment("Событие из реестра (config/notification_pulse.php), напр. 'orders.status_changed'. Допустима маска домена 'orders.*' и '*' — все события");
$table->string('scope_type', 20)->default('global')->comment("Область: 'global' — все партнёры, 'user' — конкретный партнёр, 'company' — конкретный контрагент, 'manager' — все клиенты персонального менеджера");
$table->foreignId('scope_user_id')->nullable()->comment('Партнёр области (users.id), если scope_type = user')->constrained('users')->cascadeOnDelete();
$table->foreignId('scope_company_id')->nullable()->comment('Контрагент области (companies.id), если scope_type = company')->constrained('companies')->cascadeOnDelete();
$table->foreignId('scope_manager_id')->nullable()->comment('Персональный менеджер области (personal_managers.id), если scope_type = manager')->constrained('personal_managers')->cascadeOnDelete();

$table->json('conditions')->nullable()->comment('Дерево условий: {"all":[{"field":"status","op":"in","value":["closed"]}]}. NULL — правило срабатывает на любое событие своего типа');

$table->unsignedSmallInteger('priority')->default(100)->comment('Порядок применения: меньше — раньше. Системные правила живут в 400–600, пользовательские по умолчанию 100');
$table->boolean('stop_processing')->default(false)->comment('Прервать разбор следующих правил после этого (аналог stop в Sieve). Получатели самого правила при этом добавляются');
$table->boolean('is_active')->default(true)->comment('Включено ли правило');
$table->boolean('is_system')->default(false)->comment('Системное правило: воспроизводит зашитое в код поведение, удалить нельзя — только выключить или переопределить');
$table->string('system_key', 64)->nullable()->unique()->comment("Ключ системного правила для идемпотентной синхронизации сидером, напр. 'sys.orders.status_changed.client'");
$table->string('preset_key', 64)->nullable()->comment('Пресет, из которого правило создано — по нему массовое применение не плодит дубли');

$table->string('channel', 20)->default('email')->comment("Канал доставки: 'email' сейчас; 'telegram', 'push' — задел");
$table->string('template_key', 64)->nullable()->comment('Шаблон письма (resources/views/mail/pulse/*); NULL — шаблон события по умолчанию');
$table->string('subject_override', 512)->nullable()->comment('Своя тема письма вместо темы события; поддерживает плейсхолдеры вида {{order_number}}');
$table->boolean('attach_documents')->default(false)->comment('Прикладывать связанные печатные формы файлом (счёт к сроку оплаты); при превышении лимита размера в письмо уходит ссылка');

$table->unsignedInteger('throttle_seconds')->nullable()->comment('Не слать одному адресату по этому правилу чаще, чем раз в N секунд; NULL — без ограничения');
$table->string('digest', 10)->default('none')->comment("Сведение писем: 'none' — сразу, 'hourly' — раз в час, 'daily' — раз в сутки (задел, реализуется в notif-12)");
$table->json('quiet_hours')->nullable()->comment('Тихие часы {"from":"22:00","to":"08:00"} — письмо откладывается до конца окна (задел, notif-12)');

$table->timestamp('last_matched_at')->nullable()->comment('Когда правило последний раз совпало — по нему видно мёртвые правила');
$table->unsignedBigInteger('matched_count')->default(0)->comment('Сколько раз правило совпадало — счётчик наблюдаемости');

$table->foreignId('created_by_user_id')->nullable()->comment('Кто создал правило (users.id)')->constrained('users')->nullOnDelete();
$table->foreignId('updated_by_user_id')->nullable()->comment('Кто последним изменил правило (users.id)')->constrained('users')->nullOnDelete();

$table->timestamps();
$table->softDeletes()->comment('Мягкое удаление: журнал доставок ссылается на правило и должен показывать его название после удаления');

$table->index(['event_key', 'is_active', 'priority'], 'notification_rules_match_idx');
$table->index(['scope_user_id', 'event_key'], 'notification_rules_user_idx');
$table->index(['scope_company_id', 'event_key'], 'notification_rules_company_idx');
$table->index(['scope_manager_id', 'event_key'], 'notification_rules_manager_idx');
```

**Маска в `event_key`** — ключевое решение. Матчер выбирает
`whereIn('event_key', [$exact, $domain.'.*', '*'])`. Даёт две вещи бесплатно: подписки кабинета
с `events = null` («все типы, включая будущие») импортируются в `notif-09` одним правилом
`orders.*`, и живой кейс «всё по этому контрагенту — бухгалтеру» настраивается одним правилом.
При маске конструктор условий показывает только общие поля сигнала.

**`notification_rule_recipients`**

```php
$table->comment('Получатели правила маршрутизации: ссылка на контакт адресной книги, роль, произвольный адрес или вычисляемый адресат (клиент, персональный менеджер, резервный список)');

$table->id()->comment('Первичный ключ');
$table->foreignId('notification_rule_id')->comment('Правило (notification_rules.id)')
    ->constrained('notification_rules')->cascadeOnDelete();

$table->string('kind', 24)->comment("Вид адресата: 'contact' — конкретный контакт адресной книги (contact_id); 'contact_role' — все активные контакты роли у контрагента события (value = роль); 'email' — произвольный адрес (value); 'client_user' — email аккаунта партнёра, которому принадлежит событие; 'company_email' — email контрагента из карточки (companies.email); 'personal_manager' — персональный менеджер партнёра с учётом замещения на время отсутствия; 'config_list' — список адресов из настроек (value = ключ конфига из белого списка); 'suppress' — исключить адресата, найденного правилами с большим приоритетом (value = email или роль)");

$table->foreignId('contact_id')->nullable()->comment('Контакт адресной книги (client_contacts.id), если kind = contact')
    ->constrained('client_contacts')->cascadeOnDelete();
$table->string('value', 255)->nullable()->comment('Значение адресата: email, ключ роли или ключ конфига — смысл зависит от kind');

$table->string('copy_type', 10)->default('to')->comment("Тип копии: 'to' — основной получатель (одно письмо на адрес), 'cc' — копия, 'bcc' — скрытая копия");
$table->boolean('is_fallback')->default(false)->comment('Резервный адресат: подставляется, только если основные получатели правила не найдены');

$table->char('unsubscribe_token', 64)->nullable()->unique()->comment('Токен персональной отписки этого адресата от этого правила; создаётся при первой отправке');

$table->timestamps();
$table->index('notification_rule_id', 'notification_rule_recipients_rule_idx');
```

Разные виды адресатов покрыты одной таблицей и одним резолвером. `contact` — ссылка на карточку
(решение №2 эпика), `contact_role` — ещё сильнее: «все бухгалтеры этого контрагента», новый
бухгалтер подхватывается сам.

**`notification_signals`** — что пришло на вход: `uuid` (unique), `event_key`, `client_user_id`,
`company_id`, `nullableMorphs('subject')`, `data` (json — поля для условий), `view` (json — блоки
для вёрстки письма), `matched_rules_count`, `deliveries_count`, `dry_run`, `mode`, `created_at`,
`updated_at`. Индексы `(event_key, created_at)`, `(client_user_id, created_at)`.

Комментарии к `subject_type`/`subject_id` от `nullableMorphs()` не проставляются — объявить
столбцы руками либо дописать `DB::statement` в той же миграции.

**`notification_deliveries`** — решение движка на каждого адресата: `signal_uuid`, `event_key`,
`notification_rule_id` (nullOnDelete), `rule_name` (копия названия — журнал читается и после
удаления правила), `client_user_id`, `company_id`, `contact_id`, `channel`, `recipient`,
`recipient_kind`, `status` (`queued|sent|skipped|failed`), `skip_reason`, `message_id`, `error`,
`queued_at`, `sent_at`, таймстемпы.

```php
// Дедупликация адресата в рамках одного сигнала — гарантия на уровне БД, а не только
// в памяти: повторный запуск job после сбоя не даст второе письмо.
$table->unique(['signal_uuid', 'channel', 'recipient'], 'notification_deliveries_dedup_uniq');
$table->index(['client_user_id', 'created_at'], 'notification_deliveries_client_idx');
$table->index(['recipient', 'created_at'], 'notification_deliveries_recipient_idx');
$table->index(['notification_rule_id', 'recipient', 'created_at'], 'notification_deliveries_throttle_idx');
$table->index(['event_key', 'status', 'created_at'], 'notification_deliveries_event_idx');
```

`skip_reason` перечисляет в комментарии все значения: `duplicate`, `throttled`, `unsubscribed`,
`no_consent`, `suppressed`, `invalid_email`, `shadow`, `dry_run`, `rate_limited`, `too_old`,
`feature_off`.

**`notification_suppressions`** — стоп-лист: `email`, `scope` (`all`/`marketing`/ключ события),
`reason` (`unsubscribed`/`bounce`/`complaint`/`manual`), `contact_id`, `user_id`, `note`,
`expires_at`. Unique `(email, scope)`.

**`add_notification_delivery_id_to_sent_emails`** — колонка
`sent_emails.notification_delivery_id` (nullable FK, nullOnDelete) с комментарием и индексом.
Заполняться начнёт в `notif-03`.

### Реестр событий

PHP-классы плюс конфиг. Чистый конфиг (как `config/subscriptions.php`) не тянет: конструктору
условий нужен список полей с типами и вариантами значений, а варианты берутся из
`OrderStatus::label()` и `PrintedDocumentType::options()` — дублировать их в конфиге значит
разъехаться на первом же новом статусе. Чистый PHP не тянет тоже: пофазное включение домена
через ENV должно жить в конфиге.

**Контракт события — единственное место, где событие описывается.** Реализовал интерфейс,
зарегистрировал класс в конфиге — событие само появилось в выпадающем списке конструктора,
его поля стали условиями, метки заработали, журнал и трасса подхватили. Ни движок, ни UI,
ни журнал при добавлении события не трогаются — это и есть проверка, что абстракция взята верно.

```php
// app/Notifications/Pulse/Contracts/NotificationEventContract.php
interface NotificationEventContract
{
    /** Технический ключ: 'orders.status_changed'. Домен до точки. */
    public function key(): string;

    /** Домен: 'orders'. Гейтится через config('notification_pulse.domains'). */
    public function domain(): string;

    /** Название для менеджера: 'Смена статуса заказа'. Русское, без жаргона. */
    public function label(): string;

    /** Группа в выпадающем списке конструктора: 'Заказы', 'Оплаты', 'Документы'. */
    public function group(): string;

    /** Подсказка под названием: когда именно это срабатывает. */
    public function description(): string;

    /**
     * Поля, доступные условиям правила.
     *
     * @return array<string, FieldSpec>
     */
    public function fields(): array;

    /**
     * Метки, вычисляемые из данных сигнала. Дают условия «содержит / не содержит»
     * и работают для событий, которых на момент создания правила ещё не было.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>  ['событие:просрочка', 'просрочка:60+']
     */
    public function tags(array $data): array;

    /** Blade-шаблон письма; вернуть 'mail.pulse.default' если своего нет. */
    public function defaultTemplate(): string;

    /** Тема письма по умолчанию, поддерживает плейсхолдеры. */
    public function defaultSubject(): string;

    /** Пример данных для предпросмотра письма в конструкторе (когда реальных сигналов ещё нет). */
    public function sampleView(): array;
}
```

`FieldSpec` — описание одного поля: `key`, `label` (по-русски), `type`
(`enum|string|number|money|bool|date|array`), `options` (для enum — из самого enum'а,
не дублировать), `operators` (допустимые для типа), `hint`.

Базовый класс `AbstractNotificationEvent` закрывает рутину: `domain()` вычисляется из `key()`,
`group()` по умолчанию берёт метку домена, `tags()` отдаёт общие метки сигнала,
`defaultTemplate()` возвращает `mail.pulse.default`. Событию остаётся объявить `key`, `label`
и `fields()`.

### Чек-лист «добавить новое событие»

1. Класс в `app/Notifications/Pulse/Events/<Domain>/` — реализует контракт.
2. Строка в `config/notification_pulse.php` → `events` (и домен в `domains`, если новый).
3. Один вызов `NotificationPulse::signal(...)` там, где факт уже фиксируется.
4. Blade-шаблон — **только если** универсальный `mail.pulse.default` не подходит.
5. Тест: сигнал порождается, поля попадают в условия, метки считаются.

Ничего в `SieveRunner`, `RecipientResolver`, `DeliveryGuard`, конструкторе и журнале
не меняется. Если при добавлении события пришлось править что-то из этого списка —
контракт неполон, и это повод его расширить, а не обойти.

### Метки

Метка — строка вида `префикс:значение`, вычисляемая из данных сигнала. Общие для всех событий
собирает базовый класс: `партнёр:{id}`, `инн:{tax_id}`, `контрагент:{id}`, `менеджер:{id}`,
`раздел:{domain}`, `событие:{key}`. Событие добавляет свои — статус заказа, тип документа,
ступеньку просрочки.

Сравнение **всегда целиком**, никогда подстрокой: ИНН `7701234567` не должен находиться внутри
`77012345678` или внутри номера заказа.

Зачем метки нужны при наличии полей — одна причина: правило «всё по этому контрагенту → бухгалтеру»
пишется одним условием и **автоматически подхватывает события, которых на момент его создания
не существовало**. Через поля это потребовало бы правки правила при каждом расширении.

**Метки — внутренняя механика, а не интерфейс.** Менеджер выбирает «Просрочка оплаты» из
сгруппированного списка и слова «метка» не встречает никогда (см. `notif-05`). Режим, где
условия видны как есть, доступен только под `crm-notifications-all.view` — для разбора
«почему сработало».

`config/notification_pulse.php`: `enabled` (общий стоп-кран, по умолчанию `false`), `mode`
(`off|shadow|live`, по умолчанию `shadow`), `live_events` (CSV для пособытийного перевода),
`domains` (метка + `enabled` на каждый), `events` (список классов),
`config_recipient_lists` (**белый список** ключей конфига для `kind = config_list` — иначе
правило смогло бы прочитать любой ключ конфигурации), `limits`
(`max_deliveries_per_minute`, `max_signal_age_minutes`, `max_attachment_bytes`,
`max_recipients_per_signal`, `max_self_rules_per_domain`).

`NotificationEventRegistry` — прямой аналог `SubscriptionRegistry`: `all()`, `get()`, `exists()`,
`byDomain()`, `isEnabled()`, `fieldsFor()`, `commonFields()`,
`matchKeys(string $key): array` (даёт `['orders.status_changed','orders.*','*']` для матчера).

### События домена «Заказы»

`app/Notifications/Pulse/Events/Orders/`:

| Класс | Ключ | Поля для условий |
|---|---|---|
| `OrderCreatedEvent` | `orders.created` | `order_type`, `orders_count`, `total`, `items_count`, `channel`, `has_preorder`, `is_first_order` |
| `OrderStatusChangedEvent` | `orders.status_changed` | `status`, `previous_status` (enum `OrderStatus`), `from_erp`, `order_type`, `total` |
| `OrderItemsUpdatedEvent` | `orders.items_updated` | `added_count`, `removed_count`, `modified_count`, `old_total`, `new_total`, `total_delta`, `total_delta_percent`, `source` |
| `OrderAttributesUpdatedEvent` | `orders.attributes_updated` | `changed_fields` (array), `source` |
| `OrderShortfallEvent` | `orders.shortfall` | `shortfall_items_count`, `shortfall_amount`, `is_full_cancel`, `source` |
| `SubstitutionOfferedEvent` | `orders.substitution_offered` | `offer_items_count`, `manager_user_id` |
| `OrderShippedEvent` | `orders.shipped` | `shipment_number`, `amount`, `organization_id` |

Признак недобора считается из готовой структуры `OrderChangeLog.changes` — ветки `removed`,
`not_accepted`, `partial` уже формируются в `buildNoticeRows()`
(`app/Models/OrderChangeLog.php:95-160`), новых полей в журнале заказа заводить не нужно.

Общие поля любого сигнала (доступны при маске `*`): `client_user_id`, `client_erp_name`,
`company_id`, `company_tax_id`, `manager_id`, `client_status`, `event_domain`, `occurred_at`,
`weekday`, `hour`.

### Условия

```json
{"all": [
  {"field": "status", "op": "in", "value": ["closed"]},
  {"any": [
    {"field": "total", "op": ">=", "value": 100000},
    {"field": "order_type", "op": "=", "value": "preorder"}
  ]}
]}
```

`ConditionEvaluator::matches(?array $conditions, array $data): bool` — чистый PHP, без `eval`.
Операторы: `=`, `!=`, `in`, `not_in`, `>`, `>=`, `<`, `<=`, `between`, `contains`,
`not_contains`, `is_empty`, `not_empty`.

`ConditionValidator` — глубина ≤ 3, узлов ≤ 50, поле обязано быть в `fields()` события,
оператор — в списке допустимых для типа поля. Используется и FormRequest'ом (`notif-05`),
и матчером при загрузке (правило с невалидным условием не совпадает и попадает в отчёт
«сломанные правила»).

`symfony/expression-language` отвергнут сознательно: строка-выражение, набранная менеджером
и лежащая в БД, — это код, который нельзя ни провалидировать, ни отрисовать конструктором.

### Модели и политики

`NotificationRule` (SoftDeletes, касты `conditions`/`quiet_hours` в array, скоуп
`visibleInCrm($actor)`), `NotificationRuleRecipient`, `NotificationSignal`,
`NotificationDelivery`, `NotificationSuppression`.

`NotificationRulePolicy`: удаление системного правила запрещено всем; глобальное правило
(`scope_type = global`) создаёт и правит только держатель `crm-notifications-all.edit`.

## Критерии готовности

- [ ] Пять миграций + колонка в `sent_emails` применяются; `db:comments:audit --strict` зелёный
- [ ] `bi:sync-grants` выполнен
- [ ] `NotificationEventContract` + `AbstractNotificationEvent`; семь событий домена `orders`
- [ ] `NotificationEventRegistry`: `matchKeys()`, `groupedForConstructor()` (список для выпадающего меню, сгруппированный по `group()`)
- [ ] Метки: общие считает базовый класс, событие добавляет свои; сравнение целиком, не подстрокой
- [ ] Операторы `has_tag` / `not_has_tag` в `ConditionEvaluator`
- [ ] Тест расширяемости: добавление восьмого события **не требует** правок в движке, конструкторе и журнале
- [ ] `ConditionEvaluator` — unit-тесты таблицей на каждый оператор × тип, включая `null` и пустые массивы
- [ ] Тест: метка `инн:7701234567` не совпадает с `инн:77012345678`
- [ ] `ConditionValidator` — тесты на неизвестное поле, недопустимый для типа оператор, превышение глубины и числа узлов
- [ ] Модели, касты, скоупы видимости, политики
- [ ] `config/notification_pulse.php` с белым списком ключей для `config_list`
- [ ] **В коде нет ни одной точки отправки** — grep по `notify(`/`Mail::` в новых файлах пуст
- [ ] `make lint` и `make test` зелёные
