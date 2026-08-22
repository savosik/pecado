# contact-15 · Уборка полей анкеты

**Приоритет:** низкий
**Создано:** 2026-08-22
**Эпик:** [contact-00](2026-08-22_contact-00-epic.md)
**Зависимости:** [contact-07](../review/2026-08-22_contact-07-profile-migration.md) + минимум один релиз между переносом и дропом
**Оценка:** ~1 день

## Описание

Второй шаг переноса. Данные уже в справочнике, поля анкеты дублируют их — убираем, чтобы
не осталось двух представлений одного факта.

Брать раньше нельзя: между переносом и дропом должен пройти релиз, чтобы перенос проверили
на живых данных. Неделю данные полежат в двух местах — это допустимо, месяц — нет.

## Что удаляется

Восемь колонок `crm_client_profiles`: `accountant_name`, `accountant_contact`, `owner_name`,
`owner_contact`, `decision_maker_name`, `decision_maker_role`, `decision_maker_contact`,
`decision_maker_birthday`.

За ними тянутся: `app/Support/Crm/ClientPassport.php` (секция `contacts` пустеет — убрать саму
секцию и пересчитать `passport_completeness`, иначе полнота анкеты просядет у всех разом),
`UpdateClientProfileRequest`, `ClientProfileForm.jsx`, `ProfileOperations.php`, параметры
в `OperationRegistry`, `CrmClientProfileFactory`.

## Что НЕ удаляется

**`preferred_channel` в анкете остаётся.** Это канал общения с компанией как таковой («в эту
контору звоним, а не пишем»), у контакта — своя одноимённая колонка про конкретного человека.
Разные факты, совпало только имя.

## Заодно

- Удалить парсинг анкет из `PartnerAddressBook`, если он не удалён в
  [contact-06](../review/2026-08-22_contact-06-mail-addressbook.md).
- `php artisan bi:sync-grants` — перегенерация грантов; убедиться, что появилась вьюха по контактам
  и не осталось протухшей `v_client_contacts` от снесённой адресной книги.

## Критерии готовности

- [ ] Восемь колонок удалены миграцией с рабочим `down()`
- [ ] `ClientPassport`, форма, реквест, API и фабрика вычищены
- [ ] Полнота анкеты пересчитана и не просела искусственно
- [ ] `preferred_channel` в анкете остался
- [ ] Парсинг анкет в `PartnerAddressBook` удалён
- [ ] `bi:sync-grants` прогнан, битых вьюх нет
- [ ] `make verify` зелёный
