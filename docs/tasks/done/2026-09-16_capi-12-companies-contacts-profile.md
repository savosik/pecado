# capi-12: реквизиты, адреса, контакты, профиль через API

**Приоритет:** низкий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01

## Описание

Секции `companies`, `bank-accounts`, `delivery-addresses`, `contacts`, `profile`. Сервисов у этих
контроллеров нет, логика компактна, но живёт в приватных методах контроллеров и частично дублируется
(`CheckoutController::saveDeliveryAddress`). **Удалений через API нет** — только деактивация и смена
умолчания.

| Операция | Сервис / вынос |
|---|---|
| `companies.list`, `companies.get` | `User::companies()` |
| `companies.create` POST (I), `companies.update` PATCH | **вынести** `CompanyController::validateCompany/claimOrCreateCompany` → `App\Services\Company\CompanyClaimService` (правило `TaxId` по стране, «забрать осиротевшую / отказать чужой», `withoutGlobalScope(CompanyScope)` + `lockForUpdate`); `store/apiStore/update` кабинета на него |
| `companies.set-default` POST `companies/{company}/default` | вынос `toggleDefault` в тот же сервис |
| `bank-accounts.list/create/update` | FormRequest из `BankAccountController::validateBankAccount`; операции над `Company::bankAccounts()` |
| `delivery-addresses.list/create/update/set-default` | **новый** `App\Services\Delivery\DeliveryAddressBook` — убирает дубль между `DeliveryAddressController` и `CheckoutController::saveDeliveryAddress` (capi-08 использует его же) |
| `contacts.list/create/update/deactivate` | **вынести** `ContactController::validated/syncCompanyLinks/payload` (~150 строк) → `App\Services\Contacts\PartnerContactService`; контроллер на него |
| `profile.get` | имя, email, телефон, регион/валюта, `preorders_enabled` + срок из `config/preorder.php`, `reserve_allowed`, персональный менеджер (имя, телефон, email, замещение через `ManagerAbsenceResolver`) — «кому звонить» |
| `profile.update` PATCH | те же поля, что даёт править `CabinetController::updateProfile` (уточнить состав при реализации); смена флага предзаказов — **не** через API (решение менеджера, `ClientLifecycleService::changePreorders` с актором-сотрудником) |

## Критерии готовности

- [ ] ИНН, привязанный к другому аккаунту → 422 с текстом кабинета; осиротевшая компания «забирается»; чужая компания/счёт/адрес/контакт → 404.
- [ ] Маршрутов `DELETE` в секциях нет (тест по реестру: ни одной операции `method=DELETE` кроме `carts.delete`).
- [ ] Кабинетные контроллеры компаний, счетов, адресов, контактов и чекаут используют новые сервисы; их тесты зелёные.
- [ ] `profile.get` показывает менеджера с учётом замещения.
- [ ] `make lint` зелёный.
