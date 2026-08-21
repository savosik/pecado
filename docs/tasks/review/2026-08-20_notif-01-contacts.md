# notif-01 · Адресная книга контрагента

> ## ⛔ ОТМЕНЕНО 21.08.2026
>
> Подход заменён на [mail-00](../in-progress/2026-08-21_mail-00-epic.md) — поток писем
> с фильтрами. Причина: заказчик не смог разобраться в готовом интерфейсе.
> Модель строилась вокруг событий, тогда как думают о письмах.
>
> Карточка сохранена как история решений: часть наработок переиспользуется
> в новом эпике, см. раздел «Что переиспользуется».

**Приоритет:** высокий
**Создано:** 2026-08-20
**Эпик:** [notif-00](../backlog/2026-08-20_notif-00-epic.md)
**Волна:** 1 (ядро и заказы)
**Зависимости:** нет — стартовая карточка эпика
**Оценка:** ~3 дня

## Описание

Кейс заказчика «изменения заказов по контрагенту Пупкину приходят на емейлы Жопкина и Петрова»
сегодня нереализуем: машиночитаемых адресов контактных лиц в системе нет вовсе.

Что есть:
- `users.email` — ровно один адрес на аккаунт партнёра;
- `companies.email` — почта юрлица;
- `crm_client_profiles.{decision_maker,accountant,owner}_contact` — **свободный текст**
  («Иванов Пётр, +7 912 …, buh@ромашка.рф»), из которого адрес без парсинга не достать;
- `entity_subscriptions.destination` — единственное место с валидированными доп. адресами,
  но заводит их только сам клиент из кабинета, и почти никто этого не сделал.

Карточка заводит адресную книгу: контакт с ролью — самостоятельная запись, на которую потом
ссылаются правила пульта. Смена бухгалтера у контрагента правится в одной карточке, а не
в десяти правилах — это решение №2 эпика.

## Что делаем

### Миграция `client_contacts`

Имя `client_contacts`, а не `crm_contacts`: контакт — данные партнёра, а не CRM-артефакт.
Их ведёт менеджер, но в волне 4 те же записи будет править клиент в кабинете (как `companies`).

```php
$table->comment('Адресная книга партнёра: контактные лица контрагентов (ЛПР, бухгалтер, закупщик, логист) для адресной рассылки уведомлений');

$table->id()->comment('Первичный ключ');
$table->foreignId('user_id')->comment('Партнёр — владелец адресной книги (users.id)')
    ->constrained('users')->cascadeOnDelete();
$table->foreignId('company_id')->nullable()
    ->comment('Контрагент — юрлицо партнёра (companies.id); NULL — контакт партнёра в целом, годится для любого его юрлица')
    ->constrained('companies')->nullOnDelete();

$table->string('full_name', 191)->comment('ФИО контактного лица');
$table->string('role', 30)->comment("Роль: 'director' — директор, 'accountant' — бухгалтер, 'buyer' — закупщик, 'logist' — логист, 'manager' — контактное лицо, 'owner' — собственник, 'other' — прочее");
$table->string('position', 191)->nullable()->comment('Должность свободным текстом, как в подписи писем контрагента');

$table->string('email', 191)->nullable()->comment('Email — основной адрес доставки уведомлений; NULL — контакт только для звонков');
$table->string('phone', 50)->nullable()->comment('Телефон контактного лица');

$table->boolean('is_primary')->default(false)->comment('Основной контакт своей роли у контрагента: подставляется первым в пресетах правил');
$table->boolean('is_active')->default(true)->comment('Активен ли контакт: неактивный не получает писем и не подставляется в правила');

$table->boolean('marketing_consent')->default(false)->comment('Согласие на рекламные рассылки и кампании; транзакционные уведомления его не требуют');
$table->timestamp('marketing_consent_at')->nullable()->comment('Когда получено согласие на рассылки');
$table->timestamp('unsubscribed_at')->nullable()->comment('Когда контакт отписался по ссылке из письма — глобальный отказ от всех уведомлений');
$table->char('unsubscribe_token', 64)->unique()->comment('Токен для публичной ссылки отписки в письме');

$table->string('source', 20)->default('manual')->comment("Откуда контакт: 'manual' — завёл менеджер, 'profile_import' — распознан из текстовых полей профиля CRM, 'self' — указал клиент в кабинете, 'erp' — приехал из 1С");
$table->text('notes')->nullable()->comment('Заметка менеджера о контакте');
$table->string('erp_uuid', 36)->nullable()->comment('UUID контактного лица в 1С — задел на случай выгрузки контактов по шине; сейчас всегда NULL');

$table->foreignId('created_by_user_id')->nullable()
    ->comment('Сотрудник, создавший контакт (users.id)')->constrained('users')->nullOnDelete();

$table->timestamps();
$table->softDeletes()->comment('Мягкое удаление: правила, ссылающиеся на контакт, не должны осиротеть молча');

$table->index(['user_id', 'is_active'], 'client_contacts_user_active_idx');
$table->index(['company_id', 'role'], 'client_contacts_company_role_idx');
$table->index('email', 'client_contacts_email_idx');
```

Уникальность `(user_id, company_id, email)` в БД **не выносим**: `softDeletes` плюс допустимость
NULL-email сделали бы индекс бесполезным. Проверка живёт в `StoreClientContactRequest`
с русским сообщением «У этого контрагента уже заведён контакт с таким адресом».

`softDeletes()` комментарий не принимает так же, как `timestamps()` — колонку `deleted_at`
объявить явно либо дописать комментарий отдельным `DB::statement` в той же миграции,
иначе `db:comments:audit --strict` покраснеет.

### Enum роли

`app/Enums/ClientContactRole.php` — по образцу `App\Enums\PrintedDocumentType`:
`label()`, `color()` (палитра Chakra), `options()` для селектов и фильтров, `values()`
для валидации. Русские метки: Директор, Бухгалтер, Закупщик, Логист, Контактное лицо,
Собственник, Прочее.

### Модель `App\Models\ClientContact`

`SoftDeletes`, каст `role` в enum, генерация `unsubscribe_token` в `creating`
(`Str::random(64)` — как в `EntitySubscription::boot()`), связи `user()`, `company()`,
`createdBy()`. Скоупы `active()`, `withEmail()`, `role(ClientContactRole $role)`.

Скоуп видимости `visibleInCrm($actor)` — через `user_id` в `User::scopeVisibleInCrm($actor)`,
чтобы менеджер не видел контакты чужих клиентов. Обратные связи: `User::contacts()`,
`Company::contacts()`.

### CRUD в CRM

Контроллер `app/Http/Controllers/Crm/ClientContactController.php` (наследует `CrmController`),
роуты в `routes/crm.php` под `permission:crm-notification-contacts.*`:

```
GET    /crm/contacts                    сводная адресная книга с фильтрами
POST   /crm/contacts                    создать
PATCH  /crm/contacts/{contact}          изменить
DELETE /crm/contacts/{contact}          удалить (soft)
POST   /crm/contacts/import-from-profile  импорт черновиков из профиля клиента
```

Чужой контакт → **404, не 403** (соглашение проекта).

### Права — три реестра

Ресурс `crm-notification-contacts` с действиями `view, create, edit, delete`, метка
«CRM: Контакты контрагентов». Обязательны все три места, иначе валится
`tests/Feature/Crm/PermissionNamingTest.php`:

1. `database/seeders/RolesAndPermissionsSeeder.php` — `$resources` (CRM-блок), `$resourceLabels`,
   раздача ролям `sales-manager`, `sales-manager-crm`, `sales-head`.
2. `app/Http/Controllers/Admin/RoleController.php` — `$permissionGroups['CRM']`, `$resourceLabels`.
3. Пункт меню не нужен — адресная книга живёт вкладкой на карточках; сводный список открывается
   из пульта в `notif-05`.

Плюс миграция выдачи прав на прод по образцу
`database/migrations/2026_08_10_110000_grant_crm_contractor_permissions.php`.

### Фронтенд

`resources/js/Crm/Pages/Contacts/` + переиспользуемый компонент
`resources/js/Crm/Components/ContactsPanel.jsx` — дроп-ин по образцу
`resources/js/components/cabinet/SubscriptionPanel.jsx`, вставляется на обе карточки:

- вкладка «Контакты» на `/crm/partners/{user}` (`Pages/Clients/Show.jsx`) — все контакты партнёра;
- вкладка «Контакты» на `/crm/contractors/{company}` (`Pages/Contractors/Show.jsx`) — контакты
  этого юрлица плюс общие контакты партнёра (с `company_id = NULL`) отдельным блоком.

Таблица: ФИО, роль (бейдж цветом из enum), должность, email, телефон, согласие на рассылки,
активность, действия. Chakra только из `@/components/ui/*` (кроме layout-примитивов),
весь текст русский.

### Импорт черновиков из профиля

Команда `contacts:import-from-profiles` + кнопка «Импорт из профиля» на карточке.
Парсит свободный текст `crm_client_profiles`:

| Поле профиля | Роль контакта |
|---|---|
| `owner_name` / `owner_contact` | `owner` |
| `accountant_name` / `accountant_contact` | `accountant` |
| `decision_maker_name` / `decision_maker_role` / `decision_maker_contact` | `manager` |

Регулярка достаёт email и телефон, остаток строки идёт в `full_name`.

**Создаются с `is_active = false` и `source = 'profile_import'`** — черновики, которые не
участвуют в рассылке, пока менеджер их не подтвердит. Автоматически активировать распознанное
регуляркой нельзя: цена ошибки — письмо о финансах чужому человеку.

Идемпотентность: повторный запуск не плодит дубли (ключ — `user_id` + email).

## Критерии готовности

- [ ] Миграция `client_contacts` применяется; `docker exec pecado-app php artisan db:comments:audit --strict` зелёный
- [ ] `docker exec pecado-app php artisan bi:sync-grants` выполнен — таблица видна ИИ-агенту
- [ ] Enum `ClientContactRole` с русскими метками и `options()`
- [ ] Модель с генерацией токена отписки, скоупами и связями
- [ ] CRUD под правом `crm-notification-contacts.*`; чужой контакт → 404
- [ ] Право заведено во всех трёх реестрах; `PermissionNamingTest` зелёный
- [ ] Миграция выдачи прав ролям отдела продаж
- [ ] Вкладка «Контакты» на карточке партнёра и на карточке контрагента; весь UI на русском
- [ ] `contacts:import-from-profiles` создаёт неактивные черновики, повторный запуск не дублирует
- [ ] Feature-тесты: CRUD, скоуп видимости (чужой клиент → 404), валидация дубля адреса, импорт
- [ ] `make lint` и `make test` зелёные
