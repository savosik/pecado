# Техническое задание: Корзина

## 1. Общее описание

Корзина — ключевой модуль для авторизованных пользователей, позволяющий собирать товары перед оформлением заказа. Функционал поддерживает **множественные именованные корзины**, разделение товаров на **в наличии** и **предзаказ**, bulk-операции и real-time синхронизацию UI.

> [!IMPORTANT]
> Корзина доступна **только авторизованным пользователям**. Гостям показывается кнопка «В корзину», при нажатии — редирект на страницу логина.

---

## 2. Текущее состояние проекта

### Что уже реализовано

| Компонент | Файл | Описание |
|-----------|------|----------|
| Модель `Cart` | `app/Models/Cart.php` | Базовая модель: `user_id`, `name`, связи `user()`, `items()`, `products()`, `order()` |
| Модель `CartItem` | `app/Models/CartItem.php` | Базовая модель: `cart_id`, `product_id`, `quantity` |
| Контроллер | `app/Http/Controllers/User/CartController.php` | API: `summary`, `addItem`, `updateItem`, `removeItem`, `clear` |
| Сервис | `app/Services/Cart/CartService.php` | `getCartSummary()`, `getCartsSummary()`, `moveItems()` |
| Контракт | `app/Contracts/Cart/CartServiceInterface.php` | Интерфейс сервиса |
| Zustand-стор | `resources/js/stores/useCartStore.js` | Состояние корзины с debounced API-sync |
| UI-компонент | `resources/js/components/product/CartQuantityControl.jsx` | Кнопка «В корзину» + pill-контрол количества |
| Миграция `carts` | Таблица: `id`, `user_id`, `name`, `timestamps` |
| Миграция `cart_items` | Таблица: `id`, `cart_id`, `product_id`, `quantity`, `timestamps`. Unique: `[cart_id, product_id]` |

### Смежные сервисы (уже работают)

| Сервис | Описание |
|--------|----------|
| `PriceService` | `getUserPrice()`, `getDiscountedPrice()`, `convertPrice()` — цены с учётом скидок и валюты |
| `StockService` | `getStock()`, `getAvailableStock()`, `getPreorderStock()` — остатки по складам региона пользователя |
| `CurrencyService` | Конвертация валют |

---

## 3. Что нужно доработать

### 3.1. Схема БД — расширение

#### Таблица `carts` — добавить поля:

| Поле | Тип | Описание |
|------|-----|----------|
| `is_active` | boolean, default `true` | Признак активной корзины (для мультикорзин) |
| `description` | text, nullable | Описание/заметка к корзине |

**Индексы:** добавить `[user_id, is_active]`

#### Таблица `cart_items` — добавить поля:

| Поле | Тип | Описание |
|------|-----|----------|
| `price` | decimal(10,2), nullable | Цена на момент добавления (в базовой валюте) |
| `item_type` | enum `instock`/`preorder`, default `instock` | Тип позиции |
| `warehouse_id` | FK nullable, on delete set null | Склад |

**Уникальный ключ:** изменить `[cart_id, product_id]` → `[cart_id, product_id, item_type, warehouse_id]`
**Индексы:** добавить `[cart_id, item_type]`

---

### 3.2. Модели — расширение

#### `Cart` — добавить:

- **Fillable:** `description`, `is_active`
- **Casts:** `is_active` → `boolean`
- **Связи:** `instockItems()`, `preorderItems()` (фильтрация по `item_type`)
- **Scope:** `scopeActive($query)` — `where('is_active', true)`
- **Аксессоры (через `PriceService` и `StockService`):**
  - `total_quantity`, `instock_quantity`, `preorder_quantity`
  - `total_amount_regular` — сумма по `base_price`
  - `total_amount_discounted` — сумма по лучшим ценам
- **Методы:**
  - `addProduct(Product, qty, type, ?warehouse): CartItem` — добавить (или суммировать) товар
  - `removeProduct(Product, type, ?warehouse): bool`
  - `clear(): int`
  - `isEmpty(): bool`

#### `CartItem` — добавить:

- **Fillable:** `price`, `item_type`, `warehouse_id`
- **Casts:** `price` → `decimal:2`
- **Связь:** `warehouse(): BelongsTo`
- **Scopes:** `scopeInstock()`, `scopePreorder()`
- **Методы:** `isInstock(): bool`, `isPreorder(): bool`
- **Аксессор:** `total_amount` = `quantity * price`

---

### 3.3. `CartService` — расширение

Добавить методы в `CartServiceInterface` и реализацию:

| Метод | Описание |
|-------|----------|
| `getOrCreateActiveCart(User): Cart` | Получить активную корзину или создать новую |
| `addProduct(User, Cart, Product, qty, type): array` | Добавить товар. **Spillover-логика:** если `instock qty > available` → автоматически создать `preorder`-позицию на остаток |
| `updateItemQuantity(User, CartItem, qty): array` | Обновить количество с spillover |
| `removeItem(User, CartItem): void` | Удалить позицию |
| `setProductQuantity(User, Cart, Product, qty): array` | Установить точное количество (основной API для фронтенда). Удаляет старые позиции, создаёт новые с учётом наличия/предзаказа |
| `createCart(User, ?name): Cart` | Создать новую корзину (деактивировать остальные) |
| `switchActiveCart(User, Cart): void` | Переключить активную корзину |
| `renameCart(User, Cart, name): void` | Переименовать |
| `deleteCart(User, Cart): void` | Удалить (нельзя последнюю; если активная — следующая становится активной) |

#### Spillover-логика `setProductQuantity()`

```
Вход: product_id, quantity = 10
Логика:
  stock = StockService.getStock(product, user)
  // stock = { available: 3, preorder: 12 }

  clamped = min(10, 3 + 12) = 10
  instock = min(10, 3) = 3
  preorder = 10 - 3 = 7

  → Создать/обновить CartItem(product, 'instock', qty=3, warehouse=primary)
  → Создать/обновить CartItem(product, 'preorder', qty=7, warehouse=preorder)

Выход: { instock: 3, preorder: 7, clamped: 10, max_total: 15, cart_totals: {...} }
```

---

### 3.4. `CartController` — переписать

Заменить текущий минимальный контроллер на полноценный. Все маршруты за `middleware('auth')`.

#### Web-маршруты (Inertia-страницы):

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| GET | `/cart` | `cart.index` | Редирект на активную корзину |
| GET | `/cart/{cart}` | `cart.show` | Страница корзины |
| POST | `/cart` | `cart.store` | Создать новую корзину |
| POST | `/cart/{cart}/switch` | `cart.switch` | Переключить активную |
| PATCH | `/cart/{cart}` | `cart.update` | Переименовать |
| DELETE | `/cart/{cart}` | `cart.destroy` | Удалить |

#### API-маршруты:

| Метод | URL | Имя | Действие |
|-------|-----|-----|----------|
| GET | `/api/cart/summary` | — | Сводка: `{ items: [{product_id, quantity}], total_quantity }` |
| GET | `/api/cart/active-quantities` | — | Карта `{ productId: totalQty }` для синхронизации стора |
| POST | `/api/cart/set-product-quantity` | — | Установить количество (основной API) |
| POST | `/api/cart/add-product` | — | Добавить товар по `product_id` |
| POST | `/api/cart/add-by-barcode` | — | Найти товар по штрихкоду и добавить |
| PATCH | `/api/cart/items/{item}` | — | Обновить количество позиции |
| DELETE | `/api/cart/items/{item}` | — | Удалить позицию |
| DELETE | `/api/cart/clear` | — | Очистить корзину |

#### `show()` — данные для Inertia

Для каждого `CartItem`:

```php
[
    'id', 'quantity',
    'product' => [
        'id', 'slug', 'name', 'sku', 'code',
        'thumbnail_url', 'main_image_url',
        'brand' => ['id', 'name', 'slug'],
        'barcodes' => [...],
    ],
    'price' => /* зафиксированная при добавлении, в валюте юзера */,
    'price_regular' => /* base_price в валюте юзера */,
    'price_discounted' => /* getUserPrice(), лучшая цена */,
    'item_type' => 'instock' | 'preorder',
    'is_unavailable' => /* товар недоступен */,
    'available_quantity' => /* доступно на primary складе */,
    'total_amount', 'total_amount_regular', 'total_amount_discounted',
]
```

#### `setProductQuantity()` — JSON-ответ

```json
{
    "status": "success",
    "message": "...",
    "instock": 3,
    "preorder": 7,
    "clamped": 10,
    "max_total": 15,
    "cart_totals": {
        "total_quantity": 42,
        "total_amount_regular": 18000,
        "total_amount_discounted": 15000
    }
}
```

---

### 3.5. `CartPolicy` — создать

```php
class CartPolicy
{
    public function view(User $user, Cart $cart): bool    { return $cart->user_id === $user->id; }
    public function create(User $user): bool              { return true; }
    public function update(User $user, Cart $cart): bool  { return $cart->user_id === $user->id; }
    public function delete(User $user, Cart $cart): bool  { return $cart->user_id === $user->id; }
}
```

Зарегистрировать в `AuthServiceProvider`.

---

### 3.6. Фронтенд — `useCartStore.js` — расширить

Текущий стор сохраняется как основа. Добавить:

| Механизм | Описание |
|----------|----------|
| **`localStorage`-кеш** | Ключ `cart:qty:v1`, хранит `{productId: qty}`. Мгновенная загрузка UI до ответа сервера |
| **Cross-tab sync** | `window.addEventListener('storage', ...)` — синхронизация UI между вкладками |
| **Clamping** | При ответе сервера `clamped < requested` → обновить локальное значение |
| **Server re-sync** | На `inertia:navigate` и `cart:server-synced` → GET `/api/cart/active-quantities` |
| **Custom events** | `cart:changed` (для badge в шапке), `cart:server-synced` (для перезагрузки страницы корзины) |
| **`syncing` Set** | Отслеживание product_id в процессе API-вызова (для UI-спиннера) |

---

### 3.7. Фронтенд — страница корзины

Создать `resources/js/Pages/User/Cart/Index.jsx` (Inertia-страница):

```
Cart/Index.jsx
├── Хлебные крошки: Главная / Корзина
├── CartHeader
│   ├── Заголовок «Корзина "Имя"»
│   ├── Badge: кол-во в наличии / предзаказ
│   └── Кнопка «Управление корзинами» → CartManagerDialog
├── CartToolbar
│   ├── Поиск (клиентская фильтрация по имени/бренду/SKU)
│   ├── Dropdown «Действия» → задать кол-во / удалить выбранные / экспорт XLSX
│   ├── Кнопка «Очистить корзину»
│   └── Кнопка «Обновить»
├── CartTable (таблица товаров)
│   ├── Checkbox для bulk-выбора
│   ├── Сортировка по столбцам
│   ├── Фото / Название / Бренд / SKU / Кол-во / Цена / Сумма
│   ├── Badge «В наличии» / «Предзаказ»
│   ├── Badge «Недоступен» для unavailable
│   ├── Зачёркнутая/скидочная цена
│   ├── Inline +/− управление количеством
│   └── Кнопка удаления
├── CartSummary (итоговая карточка)
│   ├── Итого (зачёркнутая/скидочная)
│   ├── Экономия
│   ├── Кнопка «Продолжить покупки» → /products
│   └── Кнопка «Оформить заказ» → /checkout
└── EmptyState (если корзина пуста)
    └── Ссылка на каталог
```

---

### 3.8. Фронтенд — Header Dropdown

Компонент корзины в шапке сайта (мини-корзина):

- Иконка с badge (общее кол-во из `useCartStore.getTotalQuantity()`)
- Dropdown со списком корзин пользователя
- Переключение / переименование / удаление корзин
- Создание новой корзины
- Ссылка «Перейти в корзину»

---

### 3.9. Фронтенд — CartManagerDialog

Диалог управления корзинами (для мультикорзин):

- Список всех корзин (с radio выбора активной)
- Inline-переименование
- Удаление (нельзя последнюю)
- Создание новой

---

## 4. Бизнес-логика

### 4.1. Ценообразование

| Момент | Логика |
|--------|--------|
| **Добавление** | `price = PriceService.getUserPrice(product, user)` → сохраняется в `cart_items.price` (фиксирует цену на момент добавления) |
| **Отображение** | `price_regular = PriceService.getBasePrice()`, `price_discounted = PriceService.getUserPrice()` → пересчитывается при каждом показе для актуальности |
| **Валюта** | Все пересчитывается через `PriceService.convertPrice()` в валюту пользователя |

### 4.2. Наличие и предзаказ

- **В наличии (`instock`)**: товар на primary-складах региона пользователя
- **Предзаказ (`preorder`)**: товар на preorder-складах региона
- При `setProductQuantity(qty)`:
  - `instock = min(qty, available)`
  - `preorder = max(0, qty - available)`
  - `clamped = min(qty, available + preorder_stock)`
- Если `clamped < qty` → возвращаем `clamped`, фронтенд корректирует

### 4.3. Мультикорзины

- У пользователя может быть несколько именованных корзин
- Одна помечена `is_active = true`
- Все API-операции по умолчанию работают с активной корзиной
- При удалении активной → следующая по `id` становится активной
- Нельзя удалить последнюю корзину

---

## 5. Спринты реализации

### Спринт 1: Backend — модели и миграции
- [x] Обновить миграцию `carts`: добавить `is_active`, `description`
- [x] Обновить миграцию `cart_items`: добавить `price`, `item_type`, `warehouse_id`, изменить unique key
- [x] Обновить модель `Cart`: fillable, casts, связи, аксессоры, методы
- [x] Обновить модель `CartItem`: fillable, casts, связь `warehouse()`, scopes, аксессоры
- [x] Создать `CartPolicy`

### Спринт 2: Backend — сервис и контроллер
- [x] Расширить `CartServiceInterface` новыми методами
- [x] Реализовать расширенный `CartService` (spillover, мультикорзины, bulk)
- [x] Переписать `CartController` (web-маршруты + API)
- [x] Зарегистрировать роуты
- [x] Написать тесты

### Спринт 3: Frontend — стор и API-интеграция
- [x] Расширить `useCartStore.js` (localStorage, cross-tab, clamping, server re-sync)
- [x] Обновить `CartQuantityControl.jsx` (поддержка spillover toast)

### Спринт 4: Frontend — страница корзины
- [x] Создать `Cart/Index.jsx` (Inertia-страница)
- [x] Компоненты: `CartHeader`, `CartToolbar`, `CartTable`, `CartSummary`
- [x] Поиск и сортировка
- [x] Bulk-операции (выбор, задание кол-ва, удаление)
- [x] Вёрстка: русский язык, Chakra UI

### Спринт 5: Frontend — мультикорзины и dropdown
- [x] `CartManagerDialog` — управление корзинами
- [x] Mini-cart dropdown в шапке
- [x] Переключение / создание / удаление корзин

### Спринт 6: Полировка
- [x] Toast-уведомления (spillover, ошибки)
- [x] Мобильная адаптация всех компонентов
- [x] Интеграция со страницей оформления заказа (`/checkout`)
- [x] E2E тестирование

---

## 6. Технологический стек

| Слой | Технологии |
|------|------------|
| Backend | Laravel 12, PHP 8.3, MySQL |
| Frontend | React 19, Inertia.js 2, Zustand, Chakra UI |
| Маршрутизация | Inertia (страницы) + Axios (API) |
| Состояние | Zustand + localStorage |
