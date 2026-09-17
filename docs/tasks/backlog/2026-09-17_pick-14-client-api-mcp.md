# pick-14 · Клиентский API и MCP: стадия заказа и пропуска

**Приоритет:** средний
**Создано:** 2026-09-17
**Эпик:** [pick-00](2026-09-17_pick-00-epic.md)
**Зависимости:** pick-03, pick-09
**Волна:** 3

## Описание

Крупные интернет-магазины работают через API и ИИ-агента. Реестр операций
(`app/Services/Client/Api/Operations/*`) — единый источник для REST v1, OpenAPI и MCP `/mcp/client`.

- `FeatureGate::PICKUP` от `config('pickup.enabled')`.
- Блок `fulfilment {stage, label, is_pickup, promised_ready_at, ready_since, packages_total,
  packages_handed, goods_issues[]}` в `OrderOperations` (`orders.list`, `orders.get`) и в ярлыке MCP
  `client-order-status`.
- `app/Services/Client/Api/Operations/PickupOperations.php` по образцу `ReserveOperations.php`,
  регистрация в `OperationRegistry::PROVIDERS`:
  `pickup.ready` (что можно забирать), `pickup.schedule` (часы, отсечка, обещание на сейчас),
  `pickup.passes.list`, `pickup.passes.create` (`scope`, `goods_issue_ids`, данные курьера),
  `pickup.passes.revoke`. Создание и отзыв — `mutating: true` (ключ идемпотентности обязателен).
- Общая логика — `PickupPassService`, транспорт тонкий (как `ClientOrderActions` у резервов).
- Legacy `/api/client-api/{token}`: только добавить `fulfilment` в выдачу заказов, аддитивно;
  существующие тесты — контракт неизменности.
- Описания операций на русском, для ИИ-агента: когда звать `pickup.ready`, что пропуск нужен курьеру.

## Критерии готовности

- [ ] `ClientApiDiscoveryTest` и `ClientApiOpenApiTest` зелёные.
- [ ] Тесты операций: гейт выключен → операции недоступны; чужой РО; идемпотентный повтор создания.
- [ ] Через MCP агент отвечает на «какие заказы можно забирать» и выпускает пропуск.
