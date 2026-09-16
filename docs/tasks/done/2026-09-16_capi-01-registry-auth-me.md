# capi-01: реестр операций, middleware, `/me`, фича-гейты

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00 (`docs/tasks/todo/2026-09-15_capi-00-epic.md`)
**Зависимости:** нет — первая карточка эпика

## Описание

Каркас клиентского API v1 по образцу CRM (`app/Services/Crm/Api/*`, `CrmApiController`,
`AuthenticateCrmAgent`). Реестр пока с одной пробной операцией `profile.get`, чтобы проверить весь
путь Bearer → операция → ответ → аудит.

1. **Общие классы.** `Param`, `OperationInput`, `OperationDenied` переезжают из `App\Services\Crm\Api`
   в `App\Support\OperationApi\*`; в CRM меняются только `use`, поведение и тесты CRM не трогаются.
2. **Слой `App\Services\Client\Api\*`:**
   - `Operation` — как в CRM, плюс `gate: FeatureGate`, `idempotent: bool`, `companyScoped: bool`;
     `path()` → `/api/client/v1/...`; `routeConstraints()` (integer → `whereNumber`, identifier →
     `[A-Za-z0-9._-]+`).
   - `FeatureGate` (enum `NONE|DOCUMENTS|FINANCE|CONTRACTS|RESERVE|ORDER_CANCEL|PAYMENT_ORDERS`),
     `allows(User)`, `code()`, `reason()`. Предикаты — те же, что у кабинета: `config('documents.enabled')`,
     `CabinetFinance::enabledFor`, `config('contracts.cabinet_enabled')`, `ReservePolicy::availableFor`,
     `config('order_reserve.enabled')`, предикат `PaymentOrderController::availableFor` — вынести в
     `App\Support\Cabinet\PaymentOrdersGate` и звать из контроллера и из гейта. Middleware
     `EnsureDocumentsEnabled`, `EnsureCabinetFinanceEnabled`, `EnsureContractsCabinetEnabled` переводятся
     на `FeatureGate`, чтобы предикат был один физически.
   - `OperationRegistry` (`all/find/callable/sections/catalog`), `OperationRunner::run(Operation, User,
     array $args, ?string $idempotencyKey)`: agentAllowed → gate → Validator → `CompanyContext` →
     (идемпотентность — capi-03) → handler(`$actor`, `OperationInput`) → аудит mutating.
   - `CompanyContext::resolve(User, ?int $companyId, ?string $inn)`: явный id → `companies()->findOrFail`
     (чужой = 404); `inn` — только для `orders.create`; иначе `is_default` → единственная →
     `CompanyRequired` (422 `company_required`, `meta.companies[]`).
   - `Envelope` — `{data, meta, errors[{code,message,field?}]}`, `paginated()` над `cursorPaginate`.
   - `ClientApiSource` (аналог `CrmSource`): `token_id`, `token_name` на время запроса.
   - `Operations/ProfileOperations::get`, трейт `ResolvesClientEntities` (заготовка).
3. **Транспорт.** `App\Http\Middleware\AuthenticateClientApi` (Bearer → `ApiToken` active → одинаковый
   401 на три случая → `touchLastUsed` раз в минуту → `Auth::setUser` → `ClientApiSource`);
   `App\Http\Controllers\Api\Client\ClientApiController` (`me`, `run`, `ROUTE_PREFIX = 'api.client.v1.'`),
   маппинг исключений: `OperationDenied` 403, `CompanyRequired` 422, `ValidationException` 422,
   `ModelNotFound` 404, `ReserveActionException` → его status/code, `InsufficientStockException` 409
   `stock_changed`, `DebtRestrictionException` 422 `debt_restricted`, прочие Runtime/InvalidArgument 422
   `business_rule`. Маршруты в `routes/api.php`: группа `client/v1`, обход `registry->callable()`
   (образец — CRM, строки 117–143).
4. **Лимит и аудит.** `RateLimiter::for('client-api', perMinute(60) by user id)` рядом с `crm-api`;
   канал `client-agent` в `config/logging.php` (daily, `LOG_CLIENT_AGENT_DAYS=180`).
5. **`GET /me`:** actor, `companies[]` (id, name, legal_name, inn, is_default), `features` (по гейтам +
   `preorder_lead_days`), `sections`, `operations[]` (`catalogEntry` + `schema`, `allowed`,
   `denied_reason`, `idempotent`, `company_scoped`), `docs` (ui/openapi/mcp), `limits`.

## Критерии готовности

- [ ] `tests/Feature/Api/Crm/CrmApiTest.php` и `tests/Feature/Crm/Mcp/CrmMcpTest.php` зелёные без правок логики.
- [ ] `GET /api/client/v1/me` с Bearer отдаёт актора, компании, features, каталог с `allowed`.
- [ ] 401 без токена, с отозванным, с несуществующим — одна формулировка; `last_used_at` обновляется не чаще раза в минуту.
- [ ] `ClientApiDiscoveryTest::every_callable_operation_has_a_route` и обратная проверка.
- [ ] Выключенный раздел → 403 с кодом; `EnsureDocumentsEnabled` и остальные middleware кабинета работают через `FeatureGate` (кабинетные тесты зелёные).
- [ ] `tests/Unit/Services/Client/Api/{CompanyContextTest, FeatureGateTest}` — таблицы решений.
- [ ] Аудит-строка в `client-agent` при mutating-операции, отсутствие строки при чтении.
- [ ] `make lint` зелёный.
