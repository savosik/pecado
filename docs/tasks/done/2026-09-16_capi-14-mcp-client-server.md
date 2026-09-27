# capi-14: MCP-сервер `/mcp/client` и инструменты

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-06, capi-08 (для ярлыков заказа); чтение — после capi-05; финансы/документы — capi-10, capi-11; вопрос — capi-13

## Описание

Тонкая витрина над реестром по образцу `CrmServer` / `app/Mcp/Tools/Crm/*`. Отдельная граница:
свои токены (`api_tokens`), свой middleware, ничего из CRM и аналитики через этот сервер недоступно.

1. **Регистрация** в `routes/ai.php`:
   `Mcp::web('/mcp/client', ClientServer::class)->middleware([AuthenticateClientApi::class, 'throttle:client-api'])`
   — лимитер по владельцу токена, не по IP (несколько клиентов за одним NAT). Локального (stdio)
   транспорта нет — как у CRM.
2. **`App\Mcp\Servers\ClientServer`** (`$name` «Pecado — кабинет клиента», `$version`, `$instructions` —
   capi-15, `$tools`).
3. **Трейт `App\Mcp\Tools\Client\InteractsWithClientOperations`** — `actor()`, `payload()`
   (`JSON_UNESCAPED_UNICODE`, как у CRM), `execute(string $operationId, array $args, ?string $idempotencyKey)`;
   маппинг: `OperationDenied` → текст с кодом гейта; `CompanyRequired` → текст с перечнем компаний
   («уточните у человека, от какого юрлица»); `ValidationException` → перечень полей + отсылка к
   `client-describe`; `ModelNotFound` → «не найдено или недоступно этому клиенту»; `IdempotencyConflict`
   → текст с указанием повторить тем же ключом / сменить тело.
4. **Инструменты** (`#[IsReadOnly]` на чтении):

| Инструмент | Схема | Операция |
|---|---|---|
| `client-catalog` | `section?` | реестр `catalog(User, section)` |
| `client-describe` | `operation` | `catalogEntry + jsonSchema` |
| `client-call` | `operation`, `arguments`, `idempotency_key?` | любая |
| `client-prices` | `identifiers[]` | `catalog.prices` + `catalog.stocks` одним ответом |
| `client-stocks` | `identifiers[]` | `catalog.stocks` |
| `client-order-status` | `order` (id/номер/uuid) | `orders.get` |
| `client-create-order` | `products[]`, `company_id?`, `idempotency_key` **обязателен в схеме**, `reserve?`, `apply_promotions?`, `comment?` | `orders.create` |
| `client-balance` | `company_id?` | `finance.balance` + `finance.calendar` текущего месяца |
| `client-documents` | `type?`, `date_from?`, `date_to?`, `company_id?` | `documents.list` + `documents.link` на каждый |
| `client-ask-manager` | `subject`, `body`, `order?`, `idempotency_key?` | `questions.create` |

5. `/me.docs.mcp` → `/mcp/client`.

## Критерии готовности

- [ ] `tests/Feature/Client/Mcp/ClientMcpTest.php`: `ClientServer::actingAs($user)->tool(...)` — каталог с `allowed`, describe, call, каждый ярлык эквивалентен операции (сравнение payload); чужой заказ → `assertHasErrors`; выключенный раздел → текст с кодом; `CompanyRequired` → перечень компаний.
- [ ] HTTP `initialize` без токена / с отозванным → 401; с валидным — `last_used_at` обновлён.
- [ ] Изоляция границ: `ApiToken` на `/mcp/crm` → 401, `CrmAgentToken` на `/mcp/client` → 401 (тесты в обоих файлах).
- [ ] `client-create-order` без `idempotency_key` отклоняется схемой (не доходит до операции).
- [ ] Аудит mutating через MCP пишет в `client-agent` с именем токена; чтение не журналируется.
- [ ] `make lint` зелёный.
