# capi-08: чекаут, нормализация, повтор заказа — кабинет и API одним слоем

**Приоритет:** высокий
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-03, capi-06, capi-07

## Описание

Карточка, которая закрывает критерий эпика «кабинет и API оформляют заказ через один слой».
Сегодня `User\CheckoutController` держит в себе сборку превью (~100 строк), нормализацию по остаткам
(~67) и пред/пост-шаги оформления (~90); `OrderController::repeat` — ~60 строк.

| Операция | Параметры | Сервис |
|---|---|---|
| `checkout.preview` GET `checkout` | `cart_id?`, `company_id?`, `instock_only?` — итоги по группам (наличие/предзаказ/уценка), конфликты остатков, промо, долг (dry-run `DebtGate`), срок предзаказа | **новый** `App\Services\Order\CheckoutPreview`; `CheckoutController::index/groupTotals` на него |
| `checkout.normalize` POST `checkout/normalize` (M) | `cart_id?` | **новый** `App\Services\Cart\CartStockNormalizer`; `CheckoutController::normalizeStock` на него |
| `checkout.submit` POST `checkout` (M, I!, C) | `cart_id?`, `company_id`, `delivery_method`, `delivery_address?`, `comment?`, `instock_only?`, `reserve?`, `save_address?` | **новый** `App\Services\Order\CabinetCheckout::submit(User, CheckoutRequestDto): Collection<Order>` над `CheckoutService::checkout` + пред/пост-шаги (`removePreorderItems`, `default_delivery_method`, сохранение адреса через сервис адресов, очистка корзины); `CheckoutController::store` — тонкая обёртка с редиректами; гейт RESERVE при `reserve` |
| `orders.repeat` POST `orders/{order}/repeat` (M) | `mode merge\|replace`, `cart_id?` | **новый** `App\Services\Order\OrderRepeater`; `OrderController::repeat` на него |

Коды ошибок: `InsufficientStockException` → 409 `stock_changed` + `meta.conflicts`;
`DebtRestrictionException` → 422 `debt_restricted` + payload лестницы долга; выключенный режим резервов
при `reserve=true` → 403 `reserve_unavailable`.

## Критерии готовности

- [ ] `CabinetVsApiCheckoutParityTest`: одна и та же корзина оформляется через кабинет (`POST /checkout`) и через `checkout.submit`; созданные заказы совпадают по составу, суммам, типам, `company_id`, комментариям склада.
- [ ] Повтор `checkout.submit` с тем же ключом → тот же ответ, один набор заказов, одна публикация в шину; без ключа → 422.
- [ ] `stock_changed`, `debt_restricted`, `reserve_unavailable` — с кодами и полезной `meta`.
- [ ] `checkout.preview` не пишет ничего (нет строк в `orders`, корзина не меняется).
- [ ] `orders.repeat` в обоих режимах; чужой заказ → 404.
- [ ] `tests/Feature/CheckoutControllerTest.php` и кабинетные тесты заказов зелёные; `CheckoutController` не содержит бизнес-логики (только валидация, вызов, редирект).
- [ ] `make lint` зелёный.
