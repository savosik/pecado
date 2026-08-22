# contact-01 · Таблицы, модели, права

**Приоритет:** высокий
**Создано:** 2026-08-22
**Эпик:** [contact-00](2026-08-22_contact-00-epic.md)
**Зависимости:** нет — стартовая карточка
**Оценка:** ~2 дня

## Описание

Фундамент справочника: две таблицы, два перечисления, модели и права. Экранов пока нет.

Схему берём из удалённой `client_contacts` — файл миграции жив на диске
(`database/migrations/2026_08_20_100000_create_client_contacts_table.php`), там продуманные
русские комментарии и обоснования. Воскрешать саму таблицу нельзя: правило проекта — только
новые миграции.

## Таблица `contacts` — карточка человека

Поля сверх старой схемы появились под прямой запрос заказчика: «как обращаться», аватар,
соцсети, день рождения, предпочитаемый способ связи.

| Группа | Колонки |
|---|---|
| Кто | `full_name`, `greeting_name`, `position` |
| Связь | `email`, `phone`, `phone_digits`, `phone_extra`, `telegram`, `whatsapp`, `instagram`, `website`, `preferred_channel` |
| Личное | `birthday`, `birthday_has_year` |
| Принадлежность | `client_user_id` (nullable), `is_active`, `source`, `partner_touched_at` |
| Рассылки | `marketing_consent`, `marketing_consent_at`, `unsubscribed_at`, `unsubscribe_token` |
| Служебное | `merged_into_id`, `notes`, `erp_uuid`, `created_by_user_id`, `updated_by_user_id`, timestamps, softDeletes |

Индексы: `(client_user_id, is_active)`, `email`, `phone_digits`, `full_name`, `birthday`.

Две колонки требуют пояснения, иначе их снесут как лишние.

**`phone_digits`** — тот же телефон, только цифры. Сегодняшний поиск по телефону
(`app/Services/Crm/ClientListService.php:149`) делает пятиэтажный `REPLACE(REPLACE(...))` в `LIKE`,
который индекс взять не может. Отдельная колонка, заполняемая мутатором, снимает эту проблему
на новом разделе сразу, а не когда контактов станет пять тысяч.

**`merged_into_id`** — карточка-победитель при слиянии дублей. Само слияние появится в `contact-09`,
но колонку заводим сразу: мигрировать таблицу задним числом дороже, чем завести поле заранее.

**`birthday_has_year`** — половина людей называет день и месяц, но не год. Без флага пришлось бы
писать 1900-й и потом гадать, поздравлять ли с юбилеем.

Уникальности email в БД **не выносим** — тот же довод, что в старой схеме: softDeletes плюс
допустимость NULL делают индекс бесполезным. Проверка живёт в `StoreContactRequest` с русским
сообщением «У этого партнёра уже есть контакт с таким адресом».

## Таблица `contact_links` — привязка с ролью

`contact_id`, `subject_type` + `subject_id`, `role`, `role_note`, `is_primary`, `client_user_id`
(денормализация ради ленты, как `crm_comments.client_user_id`), `source`, `created_by_user_id`.

UNIQUE `(contact_id, subject_type, subject_id, role)` — один человек в одной роли у одной сущности
заводится один раз.

**У привязок нет softDeletes.** Отвязать — значит удалить строку. Иначе уникальный индекс
превращается в тыкву: мягко удалённая привязка навсегда блокирует повторную. Мягко удаляется
только человек — за ним тянутся письма, звонки и задачи.

`subject_type` хранит **FQCN**, как `crm_comments.commentable_type`: морф-карта в проекте
не включена. Строковый ключ (`'contractor'`) живёт только на границе HTTP и переводится через
`CrmEntityMap`. Смешивать нельзя.

## Перечисления

- `app/Enums/ContactRole.php` — восстановить из `git show 0fb19a22^:app/Enums/ClientContactRole.php`,
  переименовать, добавить `driver` («Водитель») и `courier` («Курьер»). Кладём в `App\Enums`,
  а не `App\Enums\Crm`: перечисление читает и кабинет партнёра.
- `app/Enums/ContactSource.php` — `manual`, `self`, `profile_import`, `directory_import`, `vcf`, `erp`
  с русскими метками и цветами: источник рисуется бейджем в списке.
- `App\Enums\Crm\PreferredChannel` **переиспользуется как есть**, новый enum не заводим.

## Модели

`app/Models/Contact.php`: `SoftDeletes`, `implements HasMedia`, коллекция `avatar` с `->singleFile()`
и конверсией `vcard` (200×200 JPEG) — образец `app/Models/PersonalManager.php`. Мутатор `phone`
заполняет `phone_digits`. Токен отписки генерируется в `creating` (`Str::random(64)`, как
в `EntitySubscription::boot()`). Скоупы `active()`, `withEmail()`, `visibleInCrm($actor)`
через `User::scopeVisibleInCrm`.

`app/Models/ContactLink.php` с `subject()` morphTo. Обратные связи `User::contacts()`
и `Company::contacts()` через `contact_links`.

`app/Policies/ContactPolicy.php` — регистрация не нужна, работает автодискавери по имени.

## Права — три реестра синхронно

Ресурс `crm-contacts` с действиями `view/create/edit/delete`. Имя свободно: старые
`crm-notification-contacts.*` снесены вместе с пультом.

1. `database/seeders/RolesAndPermissionsSeeder.php` — `$resources`, `$resourceLabels`
   («CRM: Контакты»), раздача ролям `sales-manager`, `sales-manager-crm`, `sales-head`.
2. `app/Http/Controllers/Admin/RoleController.php` — `$permissionGroups['CRM']`, `$resourceLabels`.
3. `resources/js/Crm/config/menuConfig.ts` — пункт появится в `contact-02`, но право заводится здесь.

Плюс миграция выдачи прав на прод по образцу
`database/migrations/2026_08_10_110000_grant_crm_contractor_permissions.php`: сидер на деплое
не перезапускается, и без миграции права на бою не появятся.

## Критерии готовности

- [ ] Две таблицы созданы, все колонки и обе таблицы прокомментированы по-русски
- [ ] `php artisan db:comments:audit --strict` зелёный (у `deleted_at` комментарий отдельным `DB::statement`)
- [ ] Миграция накатывается и откатывается на чистой базе
- [ ] `tests/Feature/Crm/PermissionNamingTest.php` зелёный
- [ ] Миграция выдачи прав идемпотентна, `down()` снимает
- [ ] Unit-тесты: генерация токена, заполнение `phone_digits`, уникальность привязки, скоуп видимости
- [ ] Фабрики `ContactFactory`, `ContactLinkFactory`
- [ ] `php artisan bi:sync-grants` прогнан — новые таблицы видны BI-агенту, `unsubscribe_token` скрыт
