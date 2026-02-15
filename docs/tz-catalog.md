# Техническое задание: Каталог товаров

**Проект**: Pecado  
**Стек**: Laravel 12 + Inertia 2 + React 19 + Tailwind 4 + MySQL  
**Дата**: 2026-02-14  
**Основание**: [docs/spec.md](file:///home/savosik/projects/pecado/docs/spec.md)

---

## 1. Цель

Разработать пользовательскую часть каталога товаров с фильтрацией, сортировкой, пагинацией, сохранением фильтров и адаптивным интерфейсом. Каталог — основная точка взаимодействия пользователя с ассортиментом.

---

## 2. Текущее состояние проекта

### 2.1 Что уже есть

| Компонент | Статус | Детали |
|-----------|--------|--------|
| Модель `Product` | ✅ Есть | `category_id`, `brand_id`, `base_price`, `slug`, `sku`, `barcode`, медиа (main/additional/video) |
| Модель `Category` | ✅ Есть | Nested set (Kalnoy), иконки через Spatie Media |
| Модель `Brand` | ✅ Есть | `slug`, `logo` через Spatie Media |
| Модель `ProductSelection` | ✅ Есть | Подборки (коллекции), pivot `product_product_selection` |
| Таблица `product_warehouse` | ✅ Есть | `product_id`, `warehouse_id`, `quantity` |
| Таблица `product_attribute_values` | ✅ Есть | `product_id`, `attribute_id`, `attribute_value_id` |
| Таблица `discount_product` | ✅ Есть | Связь скидок с товарами |
| Избранное | ✅ Есть | `favorites` таблица, `FavoriteController`, API toggle |
| Корзина | ✅ Есть | `CartController` с API (add/update/remove/clear/summary) |
| API брендов | ✅ Есть | `GET /api/catalog/brands` |
| API категорий | ✅ Есть | `GET /api/catalog/categories` (дерево) |
| Роут `products.index` | ✅ Есть | `User\ProductController@index` — базовый, без фильтрации |
| Роут `products.show` | ✅ Есть | `User\ProductController@show` — карточка товара |
| Валюта | ✅ Есть | `CurrencyController@switch`, конвертация цен |

### 2.2 Что нужно создать

| Компонент | Статус |
|-----------|--------|
| Роуты бренда, категории, подборки, избранного каталога | 🔴 Нет |
| API товаров с фильтрацией | 🔴 Нет |
| API фасетов | 🔴 Нет |
| API ценовых интервалов | 🔴 Нет |
| `ProductQueryScopes` (трейт) | 🔴 Нет |
| `CatalogSort` (PHP Enum) | 🔴 Нет |
| `ProductFilterRequest` (Form Request) | 🔴 Нет |
| Компонент каталога (React) | 🔴 Нет |
| Фильтры, сортировка, пагинация (React) | 🔴 Нет |

---

## 3. Маршрутизация

### 3.1 Web-маршруты (Inertia)

Все маршруты рендерят **одну** Inertia-страницу `User/Products/Index` с разными props.

```
GET  /products                           → ProductController@index
GET  /products/favorites                 → ProductController@favorites
GET  /brands/{brand:slug}                → ProductController@byBrand
GET  /categories/{category:slug}         → ProductController@byCategory
GET  /collections/{selection:slug}       → ProductController@bySelection
```

> [!IMPORTANT]
> «Коллекция» в нашем проекте = `ProductSelection`. Маршрут `/collections/{slug}` использует модель `ProductSelection`.

### 3.2 API-маршруты

```
GET  /api/catalog/products               → CatalogApiController@products
GET  /api/catalog/products/facets        → CatalogApiController@facets
GET  /api/catalog/products/price-intervals → CatalogApiController@priceIntervals
GET  /api/catalog/brands                 → CatalogController@brands        (есть)
GET  /api/catalog/categories             → CatalogController@categories    (есть)
```

Все API возвращают JSON. Не требуют аутентификации (публичные).

---

## 4. API контракты

### 4.1 `GET /api/catalog/products`

**Параметры запроса** (query string):

| Параметр | Compact | Тип | Описание |
|----------|---------|-----|----------|
| `q` | — | string | Поисковый запрос |
| `category_id` | — | int | ID категории (из маршрута) |
| `category_ids[]` | `c` | int[] | Массив ID категорий |
| `include_descendants` | — | bool | Включать подкатегории (default: `1`) |
| `brand_ids[]` | `b` | int[] | Массив ID брендов |
| `collection_ids[]` | `cl` | int[] | Массив ID подборок |
| `price_min` | — | numeric | Мин. цена |
| `price_max` | — | numeric | Макс. цена |
| `in_stock` | — | bool | Фильтр по наличию |
| `in_stock_mode` | — | enum | `instock` / `preorder` / `notavailable` |
| `in_sale` | — | `1` / `0` / пусто | Фильтр по скидке |
| `in_favourites` | — | bool | Только избранные |
| `attribute_value_ids[]` | `fv` | int[] | Массив ID значений атрибутов |
| `attribute_any` | — | `1` / `0` | OR-логика для атрибутов (default: AND) |
| `sort` | `s` | enum | Сортировка |
| `view` | `v` | enum | `grid` / `list` |
| `per_page` | `pp` | int | 10/20/40/60/100 (default: 20) |
| `page` | `p` | int | Номер страницы |

**Ответ** (200):

```json
{
  "data": [
    {
      "id": 1,
      "name": "Название товара",
      "slug": "nazvanie-tovara",
      "sku": "SKU001",
      "base_price": 1200.00,
      "sale_price": 1020.00,
      "discount_percent": 15,
      "brand": { "id": 1, "name": "Lelo", "slug": "lelo" },
      "category": { "id": 5, "name": "Бельё", "slug": "bele" },
      "image_url": "https://.../thumb/product.jpg",
      "has_video": false,
      "stock_status": "instock",
      "stock_quantity": 12,
      "is_favorited": false
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 98,
    "from": 1,
    "to": 20
  }
}
```

### 4.2 `GET /api/catalog/products/facets`

Принимает **те же** фильтр-параметры. Возвращает:

```json
{
  "brands": [
    { "id": 1, "name": "Lelo", "slug": "lelo", "count": 15 }
  ],
  "categories": [
    { "id": 5, "name": "Бельё", "slug": "bele", "count": 23 }
  ],
  "attributes": [
    {
      "id": 1,
      "name": "Цвет",
      "values": [
        { "id": 10, "value": "Чёрный", "count": 12 },
        { "id": 11, "value": "Красный", "count": 5 }
      ]
    }
  ]
}
```

> [!TIP]
> Фасеты считаются **одним SQL-запросом** с `GROUP BY attribute_id, attribute_value_id` (см. раздел «Оптимизация»).

### 4.3 `GET /api/catalog/products/price-intervals`

Принимает те же фильтр-параметры (без `price_min`/`price_max`). Возвращает:

```json
{
  "min": 100,
  "max": 15000,
  "buckets": [
    { "from": 100, "to": 1000, "count": 34 },
    { "from": 1000, "to": 5000, "count": 45 },
    { "from": 5000, "to": 15000, "count": 19 }
  ]
}
```

---

## 5. Изменения БД

### 5.1 Необходимые индексы на `products`

Проверить наличие, добавить если нет:

```sql
INDEX idx_products_category_id (category_id);
INDEX idx_products_brand_id (brand_id);
INDEX idx_products_base_price (base_price);
INDEX idx_products_created_at (created_at);
INDEX idx_products_slug (slug);
```

---

## 6. Backend-архитектура

### 6.1 Файловая структура

```
app/
├── Http/
│   ├── Controllers/User/
│   │   ├── ProductController.php        (МОДИФИЦИРОВАТЬ — добавить методы)
│   │   └── CatalogApiController.php     (НОВЫЙ)
│   └── Requests/User/
│       └── ProductFilterRequest.php     (НОВЫЙ)
├── Models/
│   ├── Product.php                      (МОДИФИЦИРОВАТЬ — добавить трейт)
│   └── Traits/
│       └── ProductQueryScopes.php       (НОВЫЙ)
├── Enums/
│   └── CatalogSort.php                  (НОВЫЙ)
└── Services/
    └── Product/
        └── CatalogFacetService.php      (НОВЫЙ)
```

### 6.2 `ProductQueryScopes` — трейт

Подключается к `Product`. Содержит Eloquent-скоупы:

| Скоуп | Параметры | Логика |
|-------|-----------|--------|
| `scopeActive` | — | `is_active = 1` (если поле будет добавлено) или без условия |
| `scopeSearch` | `string $q` | `LOWER(name) LIKE`, `LOWER(sku) LIKE`, `barcode =` через `orWhere` |
| `scopeInCategory` | `int $id`, `bool $descendants` | С `include_descendants` — подзапрос по `_lft`/`_rgt` вложенного множества |
| `scopeInCategories` | `int[] $ids`, `bool $descendants` | Множественный `whereIn` + потомки |
| `scopeInBrands` | `int[] $ids` | `whereIn('brand_id', $ids)` |
| `scopeInCollections` | `int[] $ids` | `whereHas('productSelections', whereIn)` |
| `scopeByPrice` | `?float $min`, `?float $max` | `where base_price >=` / `<=` (с учётом пользовательских цен) |
| `scopeInStock` | `string $mode`, `?int $regionId` | Подзапрос к `product_warehouse` через pivot `region_warehouse` (связь N-N Warehouse↔Region) |
| `scopeInSale` | `bool $value` | `whereHas('discounts')` или `whereDoesntHave` |
| `scopeInFavourites` | `int $userId` | `whereHas('favoritedByUsers', where user_id)` |
| `scopeByAttributes` | `int[] $valueIds`, `bool $any` | `whereHas('attributeValues')` с AND/OR логикой |

### 6.3 `CatalogSort` — PHP Enum

```php
enum CatalogSort: string
{
    case Newest    = 'newest';
    case PriceAsc  = 'price_asc';
    case PriceDesc = 'price_desc';
    case NameAsc   = 'name_asc';
    case NameDesc  = 'name_desc';

    public function apply(Builder $query): Builder
    {
        $query->reorder();
        return match ($this) {
            self::Newest    => $query->orderByDesc('created_at')->orderByDesc('id'),
            self::PriceAsc  => $query->orderBy('base_price')->orderByDesc('id'),
            self::PriceDesc => $query->orderByDesc('base_price')->orderByDesc('id'),
            self::NameAsc   => $query->orderBy('name')->orderByDesc('id'),
            self::NameDesc  => $query->orderByDesc('name')->orderByDesc('id'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Newest    => 'Новинки',
            self::PriceAsc  => 'Сначала дешёвые',
            self::PriceDesc => 'Сначала дорогие',
            self::NameAsc   => 'По имени А-Я',
            self::NameDesc  => 'По имени Я-А',
        };
    }
}
```

### 6.4 `ProductFilterRequest`

**Валидация**:

```php
public function rules(): array
{
    return [
        'q'                    => 'nullable|string|max:200',
        'category_id'          => 'nullable|integer|exists:categories,id',
        'category_ids'         => 'nullable|array',
        'category_ids.*'       => 'integer|exists:categories,id',
        'include_descendants'  => 'nullable|boolean',
        'brand_ids'            => 'nullable|array',
        'brand_ids.*'          => 'integer|exists:brands,id',
        'collection_ids'       => 'nullable|array',
        'collection_ids.*'     => 'integer|exists:product_selections,id',
        'price_min'            => 'nullable|numeric|min:0',
        'price_max'            => 'nullable|numeric|min:0',
        'in_stock'             => 'nullable|boolean',
        'in_stock_mode'        => 'nullable|in:instock,preorder,notavailable',
        'in_sale'              => 'nullable|in:0,1',
        'in_favourites'        => 'nullable|boolean',
        'attribute_value_ids'  => 'nullable|array',
        'attribute_value_ids.*'=> 'integer|exists:attribute_values,id',
        'attribute_any'        => 'nullable|in:0,1',
        'sort'                 => 'nullable|in:newest,price_asc,price_desc,name_asc,name_desc',
        'per_page'             => 'nullable|in:10,20,40,60,100',
        'page'                 => 'nullable|integer|min:1',
    ];
}
```

**`prepareForValidation()`** — разворачивает compact URL:

| Compact | Полное имя |
|---------|-----------|
| `fv` | `attribute_value_ids[]` |
| `b` | `brand_ids[]` |
| `c` | `category_ids[]` |
| `cl` | `collection_ids[]` |
| `s` | `sort` |
| `v` | `view` |
| `pp` | `per_page` |
| `p` | `page` |

### 6.5 `CatalogFacetService`

```php
class CatalogFacetService
{
    // Один SQL: GROUP BY attribute_id, attribute_value_id
    public function getAttributeFacets(Builder $baseQuery): array;

    // Один SQL: GROUP BY brand_id
    public function getBrandFacets(Builder $baseQuery): array;

    // Один SQL: GROUP BY category_id
    public function getCategoryFacets(Builder $baseQuery): array;

    // MIN/MAX + динамические buckets
    public function getPriceIntervals(Builder $baseQuery): array;
}
```

> [!NOTE]
> `SavedFilterController`, `SavedFilter` модель и таблица `saved_filters` — **отложены** (см. раздел «На потом»).

---

## 7. Frontend-архитектура

### 7.1 Компонентное дерево

```
User/Products/Index.jsx                    ← Inertia Page (единая точка входа)
├── Breadcrumbs.jsx                        ← Хлебные крошки по категории/бренду
├── CatalogHeader.jsx                      ← Заголовок + счётчик + описание
├── CatalogControls.jsx                    ← [Фильтры] [Вид] [Показ.] [Сорт.]
├── SelectedFilters.jsx                    ← Chips + «Сбросить»
├── ProductFilters.jsx                     ← Sidebar / Sheet с блоками фильтров
│   ├── SearchFilter.jsx                   ← Поиск (q)
│   ├── CategoryFilter.jsx                 ← Дерево категорий с чекбоксами
│   ├── BrandFilter.jsx                    ← Список брендов с фасетами
│   ├── PriceFilter.jsx                    ← min/max + buckets
│   ├── StockFilter.jsx                    ← В наличии / Предзаказ / Нет
│   ├── SaleFilter.jsx                     ← Со скидкой
│   ├── AttributeFilters.jsx               ← Динамические блоки по характеристикам
│   └── FavoritesFilter.jsx                ← В избранном
├── ProductGrid.jsx                        ← Сетка / Список товаров
│   ├── ProductGridItem.jsx                ← Карточка в grid-режиме
│   └── ProductListItem.jsx                ← Карточка в list-режиме
└── ProductPagination.jsx                  ← Пагинация + infinite scroll toggle
```

### 7.2 Расположение файлов

```
resources/js/Pages/User/Products/
├── Index.jsx
├── CatalogHeader.jsx
├── CatalogControls.jsx
├── SelectedFilters.jsx
├── ProductFilters.jsx
├── ProductGrid.jsx
├── ProductGridItem.jsx
├── ProductListItem.jsx
├── ProductPagination.jsx
├── filters/
│   ├── SearchFilter.jsx
│   ├── CategoryFilter.jsx
│   ├── BrandFilter.jsx
│   ├── PriceFilter.jsx
│   ├── StockFilter.jsx
│   ├── SaleFilter.jsx
│   ├── AttributeFilters.jsx
│   └── FavoritesFilter.jsx
└── hooks/
    ├── useCatalogFilters.js               ← State management + URL sync
    ├── useCatalogProducts.js              ← fetch products + AbortController
    ├── useCatalogFacets.js                ← fetch facets
    └── usePriceIntervals.js               ← fetch price intervals
```

### 7.3 Compact URL утилиты

Файл: `resources/js/utils/compactFilters.js`

```js
// Маппинг: полное имя → compact alias
const ALIASES = {
  attribute_value_ids: 'fv',
  brand_ids: 'b',
  category_ids: 'c',
  collection_ids: 'cl',
  sort: 's',
  view: 'v',
  per_page: 'pp',
  page: 'p',
};

// Дефолтные значения (не попадают в URL)
const DEFAULTS = {
  sort: 'newest',
  view: 'grid',
  per_page: '20',
  page: '1',
};

export function buildCompactQuery(filters) { /* ... */ }
export function parseCompactQuery(search) { /* ... */ }
```

---

## 8. Управление состоянием (Index.jsx)

### 8.1 Жизненный цикл

```
┌──────────────────────────────────────────────────────────┐
│  Inertia SSR → Page render с props:                      │
│  initialBrand, initialCategory, initialSelection,        │
│  seo                                                     │
└───────────────────────┬──────────────────────────────────┘
                        │ useEffect (mount)
                        ▼
┌──────────────────────────────────────────────────────────┐
│  parseCompactQuery(window.location.search) → filters     │
│  fetchProducts(filters)  ──┐                             │
│  fetchFacets(filters)    ──┼── параллельно               │
│  fetchPriceIntervals()   ──┘                             │
└───────────────────────┬──────────────────────────────────┘
                        │ onChange любого фильтра
                        ▼
┌──────────────────────────────────────────────────────────┐
│  1. Обновить state фильтров                              │
│  2. page = 1 (сброс)                                     │
│  3. buildCompactQuery(filters) → replaceState             │
│  4. AbortController.abort() предыдущих                   │
│  5. fetchProducts + fetchFacets + fetchPriceIntervals     │
└──────────────────────────────────────────────────────────┘
```

### 8.2 AbortController

```jsx
useEffect(() => {
    const controller = new AbortController();

    fetchProducts(filters, controller.signal);
    fetchFacets(filters, controller.signal);
    fetchPriceIntervals(filters, controller.signal);

    return () => controller.abort();
}, [filtersKey]); // filtersKey = JSON.stringify(filters)
```

---

## 9. Фильтры — детальное описание

### 9.1 Общие требования ко всем фильтрам

- Каждый блок фильтра — `Collapsible` с заголовком и стрелкой.
- Каждый блок имеет кнопку «Очистить» (стирает только этот фильтр).
- При изменении фильтра: `page → 1`, URL обновляется, запрос уходит.
- Все надписи на **русском**.

### 9.2 Поиск (`SearchFilter`)

- Input с иконкой 🔍 и кнопкой ×.
- **Debounce 300ms** — запрос уходит не сразу.
- Отправляет `q` параметр.

### 9.3 Категории (`CategoryFilter`)

- Дерево с чекбоксами. Раскрываемые узлы.
- При выборе родителя — автоматически `include_descendants=1`.
- Фасетные счётчики рядом с каждой категорией.
- API: `GET /api/catalog/categories`.

### 9.4 Бренды (`BrandFilter`)

- Список с чекбоксами + счётчики из фасетов.
- Поиск внутри блока при ≥10 брендов.
- API: `GET /api/catalog/brands`.

### 9.5 Цена (`PriceFilter`)

- Два поля: `min` / `max`.
- **Debounce 500ms**.
- Ниже — кнопки-buckets: `100–1 000 (34)`, `1 000–5 000 (45)`, `5 000+ (19)`.
- Клик по bucket заполняет min/max.

### 9.6 Наличие (`StockFilter`)

- Три radio-кнопки: «В наличии», «Предзаказ», «Нет в наличии».
- Отправляет `in_stock=1` + `in_stock_mode`.

### 9.7 Скидка (`SaleFilter`)

- Чекбокс «Только со скидкой».

### 9.8 Характеристики (`AttributeFilters`)

- Динамически формируются из `facets.attributes`.
- Каждый атрибут — отдельный `Collapsible` блок.
- Чекбоксы по значениям + счётчики.
- Поиск внутри блока при ≥10 значений.
- Значения с `count=0` скрываются (кроме уже выбранных).
- По умолчанию AND; переключатель «Любое из» включает OR (`attribute_any=1`).

### 9.9 Избранное (`FavoritesFilter`)

- Чекбокс «Только избранные».
- Доступен только авторизованным пользователям.

---

## 10. Сортировка

Компонент `CatalogControls` — Select с опциями:

| Значение | Подпись (RU) |
|----------|-------------|
| `newest` | Новинки |
| `price_asc` | Сначала дешёвые |
| `price_desc` | Сначала дорогие |
| `name_asc` | По имени А-Я |
| `name_desc` | По имени Я-А |

Default: `newest`. Дефолтное значение не попадает в URL.

---

## 11. Отображение: Grid / List

- Select в `CatalogControls`: «Сетка» / «Список».
- Grid: `grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5` (без sidebar) / `xl:grid-cols-4` (с sidebar).
- List: вертикальный стек с горизонтальными карточками.
- Skeleton: 12 карточек при загрузке.
- Empty state: иконка + «Ничего не найдено. Попробуйте изменить параметры поиска».
- Default: `grid`. Дефолтное значение не попадает в URL.

---

## 12. Пагинация

- **Классическая**: номера страниц (≤5 видимых), «‹ Предыдущая» / «Следующая ›», многоточия.
- Информация: «Показано X–Y из Z товаров».
- **per_page**: Select 10/20/40/60/100 (default 20).
- **Infinite scroll**: чекбокс «Включить бесконечную прокрутку».
  - При включении: пагинация скрывается, автозагрузка при скролле до 80%.
  - Дедупликация по `id` через `Set` на клиенте.
  - Сброс `loadedPages` при смене фильтров.
- Если всего 1 страница — пагинация полностью скрыта.

---

## 13. Выбранные фильтры (SelectedFilters)

- Горизонтальная строка chips (бейджей) под `CatalogControls`.
- Каждый chip: текст фильтра + кнопка ×.
- Подписи: бренды/категории — имена (не ID), атрибуты — `«Цвет: Чёрный»`.
- Кнопка **«Сбросить всё»** — очищает фильтры, не трогает sort/view.

---

> [!NOTE]
> Сохранение фильтров **отложено** (см. раздел «На потом»).

---

## 15. SEO

- **`<SeoHead>`** — рендерит `<title>`, `<meta description>`, `og:title`, `og:description`.
- Уникальный `<title>` для каждого контекста:
  - Категория: `«{category.name} — купить в Pecado»`
  - Бренд: `«{brand.name} — каталог в Pecado»`
  - Подборка: `«{selection.name} — Pecado»`
  - Поиск: `«Поиск: {q} — Pecado»`
  - Каталог: `«Каталог товаров — Pecado»`
- `seo` prop формируется на сервере в каждом методе контроллера.

---

## 16. Мобильная версия

| Аспект | Реализация |
|--------|------------|
| Фильтры | Sheet (drawer) справа, кнопка «Фильтры» + × для закрытия |
| Контролы | Горизонтальный скролл (`overflow-x-auto`) |
| Сетка | `grid-cols-2` |
| Отступы | `px-2` mobile, `sm:px-6` desktop |
| Шрифты | Адаптивные (`text-xs` → `text-sm`) |
| Пагинация | Упрощённая (без номеров, только ‹ › ) |

---

## 17. Оптимизация производительности

| # | Что | Как |
|---|-----|-----|
| 1 | **AbortController** | Отмена предыдущих запросов при смене фильтров |
| 2 | **Debounce** | 300ms для поиска, 500ms для цены |
| 3 | **Единый SQL фасетов** | `GROUP BY` вместо N подзапросов |
| 4 | **Compact URL** | Сжатие параметров для чистых URL |
| 5 | **Опуск дефолтов** | sort=newest, page=1, view=grid не в URL |
| 6 | **Skeleton** | Мгновенная обратная связь при загрузке |
| 7 | **Индексы БД** | На `category_id`, `brand_id`, `base_price`, `created_at` |
| 8 | **Условный eager load** | `whenLoaded()` в ресурсах |
| 9 | **Currency switch** | Listener `currency:switched` перезагружает данные |

---

## 18. Критерии приёмки

### 18.1 Функциональные

- [ ] Каталог открывается по всем 5 маршрутам
- [ ] Все 8 типов фильтров работают корректно
- [ ] Фасетные счётчики обновляются при смене фильтров
- [ ] Сортировка по 5 режимам
- [ ] Переключение grid/list
- [ ] Пагинация: классическая + infinite scroll
- [ ] per_page: 10/20/40/60/100
- [ ] Compact URL: корректный round-trip (encode → decode → encode)
- [ ] Хлебные крошки для категории
- [ ] Skeleton при загрузке
- [ ] Empty state «Ничего не найдено»
- [ ] Интерфейс полностью на русском

### 18.2 Нефункциональные

- [ ] Мобильный вид корректен (≤768px)
- [ ] Фильтры в Sheet на мобильных
- [ ] Нет race conditions (AbortController)
- [ ] Debounce для поиска и цены
- [ ] SEO: уникальные title/description для каждого контекста
- [ ] Время ответа API < 500ms при 10 000 товаров

---

## 19. План реализации по спринтам

### Спринт 1 — Ядро backend

- Backend: `ProductQueryScopes`, `CatalogSort`, `ProductFilterRequest`
- Backend: `CatalogApiController` (products, facets, price-intervals)
- Backend: `CatalogFacetService`
- Backend: Маршруты web + API
- Миграция: индексы на `products`

### Спринт 2 — Каталог frontend (основа)

- Frontend: `Index.jsx`, `CatalogHeader`, `CatalogControls`
- Frontend: `ProductGrid`, `ProductGridItem`, `ProductListItem`
- Frontend: `ProductPagination` (классическая + infinite scroll)
- Frontend: Hooks (`useCatalogProducts`, `useCatalogFilters`)
- Compact URL утилиты

### Спринт 3 — Фильтрация + SEO

- Frontend: Все фильтры (8 компонентов)
- Frontend: `SelectedFilters` (chips)
- Frontend: `ProductFilters` (sidebar + Sheet на mobile)
- Frontend: Hooks (`useCatalogFacets`, `usePriceIntervals`)
- AbortController + debounce
- SEO: SeoHead, уникальные title, meta

### Спринт 4 — Полировка

- Мобильный адаптив
- Skeleton loading
- Empty state
- Тестирование: feature tests, browser tests
- Оптимизация запросов, индексы

---

## 20. На потом (отложенные задачи)

Следующие функции **не входят** в текущую реализацию, но предусмотрены архитектурно:

| # | Функция | Описание |
|---|---------|----------|
| 1 | **Фильтр «Просмотренные»** | Таблица `product_views`, скоуп `scopeViewed`, компонент `ViewedFilter.jsx`. Требует запись просмотров при открытии карточки товара. |
| 2 | **Фильтр «В корзине»** | Скоуп `scopeInCart`, компонент `CartFilter.jsx`. Переключатель «в корзине» / «не в корзине». |
| 3 | **Сохранение фильтров** | Таблица `saved_filters`, модель `SavedFilter`, `SavedFilterController`, хук `useSavedFilters`, кнопка «★ Сохранить», localStorage merge для гостей. |
| 4 | **Сортировка «По популярности»** | Значение `popularity` в `CatalogSort`. Требует подсчёта просмотров (зависит от п.1). |
| 5 | **Маршрут акций** | `GET /products/promotion/{slug}` → `byPromotion()`. Skоуп `scopeInPromotion`. Когда появятся промо-акции. |
