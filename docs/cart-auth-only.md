# Функционал «Корзина» — только для авторизованных пользователей

Упрощённая версия корзины из референса без поддержки гостевых пользователей.

---

## 1. База данных

### Таблица `carts`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | — |
| `user_id` | FK NOT NULL, cascade | Владелец корзины |
| `name` | string | Имя корзины (по умолчанию «Корзина») |
| `description` | text nullable | Описание |
| `is_active` | boolean default true | Активная корзина |
| `timestamps` | — | created_at, updated_at |

**Индексы:** `[user_id, is_active]`

### Таблица `cart_items`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | — |
| `cart_id` | FK cascade | Ссылка на корзину |
| `product_id` | FK cascade | Ссылка на товар |
| `quantity` | integer | Количество |
| `price` | decimal(10,2) | Цена на момент добавления |
| `item_type` | enum: `instock`, `preorder` | Тип позиции |
| `warehouse_id` | FK nullable, set null | Склад (для предзаказов) |
| `timestamps` | — | created_at, updated_at |

**Уникальный ключ:** `[cart_id, product_id, item_type, warehouse_id]`
**Индекс:** `[cart_id, item_type]`

---

## 2. Модель `Cart`

```php
class Cart extends Model
{
    protected $fillable = ['user_id', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    // --- Связи ---
    public function user(): BelongsTo;
    public function items(): HasMany;
    public function instockItems(): HasMany;    // item_type = 'instock'
    public function preorderItems(): HasMany;   // item_type = 'preorder'

    // --- Аксессоры ---
    // Количества:
    public function getTotalQuantityAttribute(): int;     // sum всех items
    public function getInstockQuantityAttribute(): int;   // sum instock
    public function getPreorderQuantityAttribute(): int;  // sum preorder

    // Суммы:
    public function getTotalAmountAttribute(): float;            // qty * price (зафиксированная)
    public function getTotalAmountRegularAttribute(): float;     // qty * product.price (базовая)
    public function getTotalAmountDiscountedAttribute(): float;  // qty * bestPrice (через PricingFacade)

    // Недоступные:
    public function getUnavailableQuantityAttribute(): int; // товары, которых нет ни на одном складе

    // --- Методы ---
    public function addProduct(Product $product, int $qty = 1, string $type = 'instock', ?Warehouse $wh = null): CartItem;
    public function updateProductQuantity(Product $product, int $qty, string $type = 'instock', ?Warehouse $wh = null): ?CartItem;
    public function removeProduct(Product $product, string $type = 'instock', ?Warehouse $wh = null): bool;
    public function clear(): int;
    public function isEmpty(): bool;

    // --- Scopes ---
    public function scopeActive($query);
}
```

### Логика `addProduct()`

1. Вычисляет лучшую цену для пользователя: `$product->getBestPriceForUser($user, $warehouse)`
2. Ищет существующую позицию по `[product_id, item_type, warehouse_id]`
3. Если найдена — **суммирует** количество
4. Если нет — **создаёт** новую позицию

---

## 3. Модель `CartItem`

```php
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'quantity', 'price', 'item_type', 'warehouse_id'];

    // Связи
    public function cart(): BelongsTo;
    public function product(): BelongsTo;
    public function warehouse(): BelongsTo;

    // Аксессор
    public function getTotalAmountAttribute(): float; // quantity * price

    // Методы
    public function isInstock(): bool;
    public function isPreorder(): bool;
}
```

---

## 4. `CartService`

| Метод | Описание |
|-------|----------|
| `getOrCreateActiveCart(User)` | Получить активную корзину или создать новую |
| `ensureCartOwnership(User, Cart)` | Проверка: `cart.user_id === user.id` |
| `addProduct(User, Cart, Product, qty, type)` | Добавить товар с автоматическим spillover в предзаказ |
| `updateItemQuantity(User, CartItem, qty)` | Обновить кол-во с spillover |
| `removeItem(User, CartItem)` | Удалить позицию |
| `createCart(User, ?name)` | Создать новую именованную корзину |
| `switchActiveCart(User, Cart)` | Переключить активную корзину |
| `renameCart(User, Cart, name)` | Переименовать |
| `setProductQuantity(User, Cart, Product, qty)` | Установить точное количество (удалить + добавить) |

### Ключевая бизнес-логика: Spillover instock → preorder

При добавлении/обновлении, если `запрошено > доступно на основном складе`:

```
requested = 10, available_default = 3

→ instock: 3 (на основной склад)
→ preorder: 7 (на лучший склад по цене через PriceService::getBestPreorderWarehouse)
```

Если предзаказ недоступен пользователю (`canPreorderProduct()` = false) — ошибка.

---

## 5. `CartController`

Все маршруты защищены `middleware('auth')`.

### Маршруты

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| GET | `/cart` | `cart.index` | Редирект на активную корзину |
| GET | `/cart/{cart}` | `cart.show` | Страница корзины (Inertia) |
| POST | `/cart` | `cart.store` | Создать новую корзину |
| POST | `/cart/{cart}/switch` | `cart.switch` | Переключить активную |
| PATCH | `/cart/{cart}` | `cart.update` | Переименовать |
| DELETE | `/cart/{cart}` | `cart.destroy` | Удалить (нельзя последнюю) |
| PATCH | `/api/cart/items/{item}` | `cart.items.update` | Обновить кол-во позиции |
| DELETE | `/api/cart/items/{item}` | `cart.items.destroy` | Удалить позицию |
| POST | `/api/cart/add-product` | `cart.add-product` | Добавить товар |
| POST | `/api/cart/add-by-barcode` | `cart.add-by-barcode` | Добавить по штрихкоду |
| POST | `/api/cart/set-product-quantity` | `cart.set-product-quantity` | Установить кол-во (основной API для фронта) |
| GET | `/api/cart/active-quantities` | `cart.active-quantities` | Карта `{productId: qty}` для синхронизации |
| POST | `/cart/import` | `cart.import` | Импорт по кодам/штрихкодам |

### `show()` — данные для Inertia-страницы

Для каждого `CartItem` формируются:

```php
[
    'id' => $item->id,
    'product' => [
        'id', 'slug', 'name', 'sku',
        'thumbnail_url', 'main_image_url',
        'brand' => ['id', 'name'],
        'barcodes' => [...],
    ],
    'quantity' => $item->quantity,
    'price' => convertPrice($item->price),                     // зафиксированная при добавлении
    'price_regular' => convertPrice($regularPrice),             // базовая цена продукта
    'price_discounted' => convertPrice($discountedPrice),       // лучшая цена (PricingFacade)
    'item_type' => 'instock' | 'preorder',
    'is_unavailable' => bool,                                   // товар неактивен/недоступен
    'available_quantity' => int | null,                          // доступно на основном складе
    'total_amount' => qty * discountedPrice,
    'total_amount_regular' => qty * regularPrice,
    'total_amount_discounted' => qty * discountedPrice,
]
```

Итоги корзины:
```php
[
    'total_quantity', 'instock_quantity', 'preorder_quantity', 'unavailable_quantity',
    'total_amount', 'total_amount_regular', 'total_amount_discounted',
    'has_preorder_items' => bool,
]
```

### `setProductQuantity()` — основной API для фронтенда

**Запрос:** `{ product_id, quantity: 0–999, cart_id? }`

**Логика:**
1. `quantity = 0` → удалить все позиции товара
2. Рассчитать `available_default` (основной склад) и `preorder_available` (остальные)
3. `clamped = min(requested, available_default + preorder_available)`
4. `instock = min(clamped, available_default)`
5. `preorder = clamped - instock`
6. Upsert instock и preorder позиций

**Ответ:**
```json
{
    "status": "success",
    "message": "...",
    "instock": 3,
    "preorder": 7,
    "clamped": 10,
    "max_total": 15,
    "available_default": 3,
    "preorder_available": 12,
    "cart_totals": {
        "total_quantity": 42,
        "total_amount": 15000,
        "total_amount_regular": 18000,
        "total_amount_discounted": 15000
    }
}
```

### `importProducts()` — массовый импорт

**Запрос:** `{ products: ['SKU1', 'BARCODE2', ...], quantities?: [5, 3, ...], use_quantities?: true }`

Поиск товаров по: SKU → code → `barcodes_list` (JSON-поле). Для каждого найденного вызывается `setProductQuantity()`.

### `destroy()` — удаление корзины

- Нельзя удалить последнюю корзину
- Если удалённая была активной — следующая корзина становится активной
- Если предыдущая страница была `/cart/*` — редирект на `cart.index`

---

## 6. `CartPolicy`

```php
class CartPolicy
{
    public function viewAny(User $user): bool  { return true; }
    public function view(User $user, Cart $cart): bool   { return $cart->user_id === $user->id; }
    public function create(User $user): bool   { return true; }
    public function update(User $user, Cart $cart): bool { return $cart->user_id === $user->id; }
    public function delete(User $user, Cart $cart): bool { return $cart->user_id === $user->id; }
}
```

---

## 7. Фронтенд: Zustand стор — `useCartStore.js`

Централизованное состояние корзины на клиенте.

### Состояние

```js
{
    quantities: Map<productId, quantity>,  // { 42: 3, 17: 1, ... }
    loaded: boolean,
    syncing: Set<productId>,               // ID товаров в процессе синхронизации
}
```

### Механизмы

| Механизм | Описание |
|----------|----------|
| **localStorage** | Ключ `cart:qty:v1`. Хранит `{productId: qty}`. Загружается при init |
| **Debounce 300ms** | При `setQuantity()` ставит таймер, через 300мс POST к `/api/cart/set-product-quantity` |
| **Clamping** | Если сервер вернул `clamped` < запрошенного — стор обновляет локальное значение |
| **Cross-tab** | `window.storage` event → синхронизация Map между вкладками |
| **Server sync** | На `inertia:navigate`, `inertia:success`, `cart:server-synced` → GET `/api/cart/active-quantities` и обновление Map |
| **Custom events** | `cart:changed`, `cart:sync-start`, `cart:sync-result`, `cart:sync-end`, `cart:server-synced` |

### Основные методы

```js
init()                          // Загрузить из localStorage
getQuantity(productId) → number // Текущее кол-во товара
getTotalQuantity() → number     // Общее кол-во в корзине (для badge)
setQuantity(productId, qty)     // Установить + запланировать sync на сервер
clear()                         // Очистить всё
isSyncing(productId) → boolean  // В процессе sync? (для спиннера)
```

### Поток данных

```
Пользователь кликает +/- на карточке
    ↓
setQuantity(pid, newQty)
    ↓
1. localStorage обновляется мгновенно → UI реагирует
2. Через 300ms → POST /api/cart/set-product-quantity
    ↓
3. Сервер возвращает { clamped, instock, preorder, cart_totals }
    ↓
4. Если clamped ≠ requested → обновить localStorage
5. dispatch 'cart:server-synced'
    ↓
6. Listener делает GET /api/cart/active-quantities
    ↓
7. Map полностью обновляется с сервера
```

---

## 8. Фронтенд: страница корзины `Cart/Index.jsx`

### Структура компонентов

```
Cart/Index.jsx
├── Breadcrumbs (Главная / Корзина)
├── CartHeader
│   ├── PageTitle — «Корзина "Название"»
│   ├── Badge instock / preorder counts
│   └── Button «Управление корзинами» → MobileCartManager
│       ├── Список корзин с radio-переключением
│       ├── Inline-переименование
│       ├── Удаление корзины
│       └── Создание новой корзины
├── CartToolbar (если есть товары)
│   ├── Поиск (по имени, бренду, SKU, штрихкоду)
│   ├── Button «Импорт» → ImportProductsDialog
│   ├── Button «Сканер» → BarcodeScannerDialog
│   ├── DropdownMenu «Действия»
│   │   ├── Задать кол-во выбранным
│   │   ├── Удалить выбранные
│   │   ├── Экспорт в Excel
│   │   └── Очистить корзину
│   └── Button «Обновить»
├── CartCombinedSection (единая таблица товаров)
│   ├── Checkbox для bulk-выбора (все / по одному)
│   ├── Сортируемые колонки: Фото, Название, Цена, Кол-во, Сумма
│   ├── Badge типа (instock / preorder)
│   ├── Badge «Недоступен» для is_unavailable
│   ├── Inline +/- и ручной ввод количества
│   ├── Зачёркнутая цена + актуальная цена (если есть скидка)
│   ├── Кнопка удаления позиции
│   └── Quick-view продукта
├── CartSummary (итоговая карточка)
│   ├── Регулярная сумма (зачёркнутая, если есть скидка)
│   ├── Итоговая сумма (жирная)
│   ├── Экономия (зелёным)
│   ├── Button «Продолжить покупки» → /products
│   └── Button «Оформить заказ» → /checkout
└── NotFound (если корзина пуста)
    ├── Иконка пустой корзины
    ├── Текст «Корзина пуста»
    └── Button «Перейти к товарам» → /products
```

### Ключевое поведение страницы

- **Live-количество** — подписка на `useCartStore`, UI обновляется без перезагрузки
- **Auto-refresh** — при `cart:server-synced` → `router.reload({ only: ['currentCart'] })`
- **Toast-уведомления** при spillover (например: «3 с основного склада, 7 под заказ»)
- **Клиентский поиск** по всем товарам в корзине
- **Bulk-операции** через checkbox + toolbar

---

## 9. Фронтенд: шапка сайта — `CartDropdownShadcn.jsx`

Выпадающее меню корзины в header:

- Иконка корзины с badge (общее кол-во из `useCartStore.getTotalQuantity()`)
- Список корзин пользователя с кнопками:
  - Переключить активную
  - Переименовать (inline)
  - Удалить (с подтверждением)
- Создать новую корзину
- Ссылка на полную страницу корзины

---

## 10. Ценообразование

### При добавлении в корзину

```
price = product.getBestPriceForUser(user, warehouse)
→ Учитывает: базовую цену, sale_price, промо-цену, групповую цену
→ Сохраняется в cart_items.price
```

### При отображении

```
price_regular    = PricingFacade.getPriceBreakdown(product, user).original
price_discounted = PricingFacade.getPriceBreakdown(product, user).best
```

### Конвертация валют

Все цены конвертируются через `CurrencyService::convertFromBase()` на уровне контроллера.

---

## 11. Резюме файлов

| Слой | Файлы |
|------|-------|
| **Миграции** | `create_carts_table`, `create_cart_items_table` |
| **Модели** | `Cart.php`, `CartItem.php` |
| **Сервис** | `CartService.php` |
| **Контроллер** | `CartController.php` |
| **Политика** | `CartPolicy.php` |
| **Ресурсы** | `CartResource.php`, `CartItemResource.php` |
| **Запросы** | `AddToCartRequest.php` |
| **Zustand стор** | `useCartStore.js` |
| **Страница** | `Cart/Index.jsx` |
| **Компоненты** | `CartHeader`, `CartToolbar`, `CartCombinedSection`, `CartSummary`, `MobileCartManager` |
| **Shared** | `CartDropdownShadcn`, `CartItemRow`, `CartSwitcher` |
| **Диалоги** | `CreateCartDialog`, `ImportProductsDialog`, `BarcodeScannerDialog` |
