# capi-11: финансы, акт сверки, платёжное поручение через API

**Приоритет:** средний
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-03, capi-10 (`FileLinks`)

## Описание

Секции `finance` (гейт FINANCE — `CabinetFinance::enabledFor`, сегодня пилот
`CABINET_FINANCE_PILOT_USERS`) и `payment-orders` (гейт PAYMENT_ORDERS —
`CabinetFinance::enabledFor || DebtControl::live(ACTION_CABINET)`, вынесенный в capi-01 в
`PaymentOrdersGate`). Деньги считает только регистр взаиморасчётов — свои SUM запрещены.

| Операция | Параметры | Сервис |
|---|---|---|
| `finance.balance` GET `finance/balance` | `company_id?` | `CabinetSettlementFinance::summary` + `balanceByOrganization` |
| `finance.payments` GET `finance/payments` | фильтры кабинета, `cursor` | **вынести** `PaymentController::buildIndexQuery/row` → `App\Services\Payments\ClientPaymentQuery`; контроллер на него |
| `finance.payment` GET `finance/payments/{payment}` | — | presenter из `PaymentController::show` (внутренние организации → 404) |
| `finance.calendar` GET `finance/calendar` | `month` YYYY-MM, `company_id?` | `CabinetSettlementFinance::calendar` — юридический график 1С, не прогноз |
| `finance.reconciliation` GET `finance/reconciliation` | `from`, `to`, `organization_id?`, `company_id?`, `agreement_id?`, `currency?` | `ReconciliationService::act`; `organizationsOf/companiesOf/defaultPeriod` для подсказок |
| `payment-orders.options` GET `payment-orders/options` | — | `PaymentOrderService::options` |
| `payment-orders.preview` GET `payment-orders/preview` | `company_id`, `organization_id`, `scenario` (`SCENARIOS`), `entry_id?`, `amount?` | `PaymentOrderService::build` + `qrDataUri` |
| `payment-orders.download` GET `payment-orders/file` | `format pdf\|txt` + параметры preview | `pdf()` / `clientBankExchange()` |
| `payment-orders.link` GET `payment-orders/link` | те же | `FileLinks` |
| `payment-orders.send` POST `payment-orders/send` (M, I) | те же + `email`, `save_contact` | `PaymentOrderService::send` (письмо от имени менеджера, контакт с ролью accountant) |

## Критерии готовности

- [ ] Пилотный пользователь видит баланс/платежи/календарь/сверку; остальные → 403 `finance_unavailable` и `allowed=false`.
- [ ] Платёжка доступна и там, где действует лестница долга без пилота финансов (предикат `PaymentOrdersGate`).
- [ ] Акт сверки за период совпадает с кабинетным `PaymentController::reconciliation` на общем фикстурном наборе.
- [ ] `payment-orders.send` идемпотентен: повтор ключа не создаёт второе письмо (`crm_emails`).
- [ ] Чужая компания/организация в аргументах → 404/422 как в кабинете; внутренние организации скрыты.
- [ ] Кабинетные тесты `tests/Feature/Cabinet/CabinetFinancePilotTest.php` и платёжек зелёные.
- [ ] `make lint` зелёный.
