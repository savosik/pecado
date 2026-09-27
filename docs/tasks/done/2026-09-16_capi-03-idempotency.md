# capi-03: идемпотентность записи через `Idempotency-Key`

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01

## Описание

Ключ идемпотентности хранится в таблице, не в кэше: потерянный после `orders.create` ключ — это
ровно тот дубль в 1С, ради которого он заведён. Таблица заодно служит аудитом.

1. **Миграция** `client_api_idempotency_keys` (комментарии на русском по правилу `db-comments`):
   `id`, `user_id` (FK users, cascade), `key` string(128), `operation` string(64), `request_hash`
   char(64) — sha256 нормализованных аргументов, `status` (`in_progress` | `done`), `status_code`
   smallint, `response` json nullable, `created_at`, `expires_at`; unique `(user_id, operation, key)`;
   index `expires_at`. TTL 24 ч.
2. **Модель** `App\Models\ClientApiIdempotencyKey` с `Prunable` по `expires_at`; проверить, что
   `model:prune` есть в `routes/console.php`, иначе добавить.
3. **`App\Services\Client\Api\Idempotency\IdempotencyStore`**: `begin()` — вставка `in_progress`
   (уникальный индекс отбивает гонку), `complete()`, `fail()` (строка удаляется — повтор разрешён),
   `replay()` — сохранённый ответ с `meta.idempotent_replay=true`; `IdempotencyConflict` с кодами
   `idempotency_in_progress` (409), `idempotency_key_reused` (422 — тот же ключ, другой хэш),
   `idempotency_key_required` (422).
4. **Встраивание в `OperationRunner`** для `idempotent=true`: ключ обязателен у `orders.create` и
   `checkout.submit`, необязателен у `returns.create`, `payment-orders.send`, `questions.create`,
   `carts.create`. В контроллере ключ читается из заголовка `Idempotency-Key`; в MCP (capi-14) —
   аргумент `idempotency_key`.
5. `ClientApiDocument`: описание протокола в `info.description`.

## Критерии готовности

- [ ] `ClientApiIdempotencyTest`: повтор с тем же ключом и телом → тот же ответ и статус, handler не вызывался (spy).
- [ ] Тот же ключ, другое тело → 422 `idempotency_key_reused`.
- [ ] Параллельный повтор (строка `in_progress`) → 409 `idempotency_in_progress`.
- [ ] Исключение в handler освобождает ключ; следующий вызов выполняется.
- [ ] Обязательная операция без ключа → 422 `idempotency_key_required`; необязательная без ключа выполняется.
- [ ] Просроченные строки удаляются `model:prune`.
- [ ] `docker exec pecado-app php artisan db:comments:audit --strict` зелёный; после миграции — `bi:sync-grants` не требуется (таблица служебная — проверить исключение по префиксу либо добавить).
