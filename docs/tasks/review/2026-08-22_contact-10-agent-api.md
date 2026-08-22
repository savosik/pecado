# contact-10 · Контакты в агентском API и MCP

**Приоритет:** средний
**Создано:** 2026-08-22
**Эпик:** [contact-00](../backlog/2026-08-22_contact-00-epic.md)
**Зависимости:** [contact-03](../review/2026-08-22_contact-03-panel.md)
**Оценка:** ~1 день

## Описание

ИИ-агент менеджера должен уметь то же, что менеджер: найти человека по адресу, завести карточку
по итогам разговора, привязать роль.

Добавляется секция `contacts` в `app/Services/Crm/Api/OperationRegistry.php` и хендлер
`app/Services/Crm/Api/Operations/ContactOperations.php`. Маршруты, OpenAPI и каталог MCP
появляются сами — регистр единственный.

## Операции

| Операция | Метод | Право | Зачем |
|---|---|---|---|
| `contact.list` | GET `contacts` | `crm-contacts.view` | Поиск по ФИО, телефону, почте; фильтр по партнёру и роли |
| `contact.show` | GET `contacts/{contact}` | `crm-contacts.view` | Карточка с привязками, письмами и звонками |
| `contact.create` | POST `contacts` | `crm-contacts.create` | Завести человека |
| `contact.update` | PATCH `contacts/{contact}` | `crm-contacts.edit` | Дополнить по итогам разговора |
| `contact.link` | POST `contacts/{contact}/links` | `crm-contacts.edit` | Привязать к сущности с ролью |
| `contact.unlink` | DELETE `contacts/{contact}/links/{link}` | `crm-contacts.edit` | Отвязать |
| `contact.by_email` | GET `contacts/by-email` | `crm-contacts.view` | **Чей это адрес** — контакт и партнёр |
| `client.contacts` | GET `clients/{client}/contacts` | `crm-contacts.view` | Адресная книга партнёра одним запросом |

Плюс MCP-ярлык `app/Mcp/Tools/Crm/CrmContactLookup.php` по образцу `CrmClientCard` — «найди
человека по имени, телефону или почте».

## Как это чинит прицепление письма

Агент, разбирая письмо, зовёт `contact.by_email`. Пусто — зовёт `contact.create` и `contact.link`
к партнёру. Со следующего письма подшивка идёт сама: `PartnerAddressBook` смотрит в справочник
первым, а `crm_emails.contact_id` кладёт письмо ещё и в карточку человека.

## Критерии готовности

- [ ] Операции видны в каталоге `/api/crm` и в OpenAPI без ручных правок
- [ ] MCP-инструмент отвечает на «чей это адрес»
- [ ] Чужой контакт агенту не отдаётся (404)
- [ ] `tests/Feature/Crm/Api/ContactOperationsTest.php`

---

## Сделано 23.08.2026

Секция `contacts` в `OperationRegistry` + `ContactOperations` + MCP-ярлык `CrmContactLookup`.
Маршруты, OpenAPI и каталог MCP появились сами — реестр единственный.

Одна тонкость стоила отладки: чужая карточка должна отвечать 404, и для этого нужен
именно `ModelNotFoundException`, а не `abort(404)`. Агентский контроллер разбирает исключения
по типу, а `HttpException` унаследован от `RuntimeException` и уезжал в 422 с пустым сообщением.

Сквозной сценарий под тестом: агент спрашивает «чей это адрес» → пусто → заводит карточку
и привязывает → тот же вопрос уже находит человека.

Тесты: `tests/Feature/Crm/Api/ContactOperationsTest.php` — 7 штук.
