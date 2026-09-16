# capi-02: OpenAPI-документ `client` и экран токенов в кабинете

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01

## Описание

1. **`App\Services\Client\Api\ClientApiDocument::build()`** — OpenAPI 3.1 из реестра по образцу
   `CrmApiDocument`: `info` с описанием (юрлицо, идемпотентность, фича-гейты, коды ошибок), `servers`,
   `tags` из секций, `components.securitySchemes.bearer`, `components.parameters.IdempotencyKey`
   (header, на операциях `idempotent=true`), `components.schemas.{Envelope, Error, CursorMeta}`,
   общие query-параметры `cursor`, `per_page`, `company_id` у `companyScoped` списков; `paths` обходом
   `callable()` + `/api/client/v1/me`.
2. **`AppServiceProvider::registerClientApiDocs()`** — копия `registerCrmApiDocs()`:
   `Scramble::registerApi('client', ['api_path' => 'api/client/v1', ...])` ради GeneratorConfig; маршруты
   `docs/client-api` (view `scramble::docs`) и `docs/client-api.json` под `RestrictedDocsAccess`;
   конфиг берётся внутри замыкания (оговорка про `route:cache` сохраняется).
3. **Экран `/api-tokens`** (`ApiTokenController::index`, `resources/js/Pages/User/Cabinet/ApiTokens/Index.jsx`):
   - у токена — `v1_base_url` (`url('/api/client/v1')`) рядом с legacy `base_url`;
   - ссылка на `/docs/client-api`;
   - «быстрый старт» из трёх curl: `me`, `catalog/prices`, `orders` с `Idempotency-Key`;
   - блок `apiMethods` (рукописная дока legacy, ~строки 140–470) заворачивается в свёрнутый
     `Accordion` «Legacy API (поддерживается, не развивается)» из `@/components/ui/accordion`;
     внутри ничего не переписывается.
   - Ручной документации v1 в JSX не заводить.

## Критерии готовности

- [ ] `ClientApiDiscoveryTest::the_openapi_document_is_published`: `/docs/client-api.json` открывается, каждая callable-операция есть в `paths` с её `operationId`, и каждый path документа есть в реестре.
- [ ] У idempotent-операций в документе описан заголовок `Idempotency-Key`; у companyScoped — `company_id`.
- [ ] `/docs/client-api` отдаёт UI Scramble; `route:cache` + `route:clear` проходят.
- [ ] `/api-tokens` показывает URL v1, ссылку на документ, «быстрый старт»; legacy-блок свёрнут по умолчанию.
- [ ] `docker exec pecado-node npm run lint:js` и `make build` зелёные; UI на русском.
