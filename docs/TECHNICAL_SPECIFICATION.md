# Техническое задание на реализацию пользовательской части

## 1. Стек технологий и контекст

| Слой | Технология | Версия/Особенности |
|------|-----------|-------------------|
| Backend | Laravel (PHP) | Inertia.js adapter, Sanctum, Scout (Meilisearch) |
| Frontend | React + Inertia.js | SPA-навигация без полного SPA |
| UI-система | Chakra UI v4 | Кастомная тема `pecado` (dark red / silver) |
| State management | Zustand | Лёгкие сторы, cross-tab sync |
| HTTP | Axios (window.axios) | Настроен в `bootstrap.js` |
| Routing | `routes/user.php` | Prefix-free (публичные) + prefix `/cabinet` (auth) |

### Текущая архитектура

```
resources/js/
├── app.jsx              ← entry: Inertia + Chakra Provider
├── theme.js             ← Chakra system (pecado palette)
├── components/ui/       ← Chakra UI snippets (provider, toaster и т.д.)
├── Pages/
│   ├── Auth/            ← Login, Register, ForgotPassword, ResetPassword
│   └── User/            ← Home, Products/Index, CatalogPanel, Cabinet/Dashboard
└── Admin/               ← НЕ ТРОГАТЬ
```

---

## 2. Архитектурные принципы

> **Референс-проект:** в каталоге `/reference/` находится похожий проект, откуда можно подсматривать дизайн и структуру UI-компонентов (`/reference/resources/js/Components/`, `/reference/resources/js/shared/`, `/reference/resources/css/`). Однако предпочтительнее создавать собственные компоненты, адаптированные под Chakra UI и тему `pecado`, а не копировать 1-в-1.

### 2.1 Структура директорий (целевая)

```
resources/js/
├── app.jsx
├── theme.js
├── components/
│   ├── ui/              ← Chakra UI snippets (существуют)
│   ├── common/          ← ← НОВАЯ: переиспользуемые UI-компоненты
│   │   ├── Breadcrumbs.jsx
│   │   ├── EmptyState.jsx
│   │   ├── NotFound.jsx
│   │   ├── PageHeader.jsx
│   │   ├── Pagination.jsx
│   │   ├── ScrollToTop.jsx
│   │   ├── SeoHead.jsx
│   │   ├── Tag.jsx
│   │   ├── TagList.jsx
│   │   ├── TagFilter.jsx
│   │   ├── QuantityControl.jsx
│   │   └── IconWithCounter.jsx
│   ├── product/         ← ← НОВАЯ: компоненты товара
│   │   ├── ProductCard.jsx
│   │   ├── ProductGrid.jsx
│   │   ├── ProductGallery.jsx
│   │   ├── ProductInfo.jsx
│   │   ├── ProductTabs.jsx
│   │   └── ProductVariants.jsx
│   ├── cart/             ← ← НОВАЯ
│   │   ├── CartDropdown.jsx
│   │   ├── CartItemRow.jsx
│   │   └── CartSwitcher.jsx
│   ├── search/           ← ← НОВАЯ
│   │   └── SearchDropdown.jsx
│   ├── stories/          ← ← НОВАЯ
│   │   ├── StoryCircles.jsx
│   │   └── StoryViewer.jsx
│   └── banner/           ← ← НОВАЯ
│       └── BannerSlider.jsx
├── hooks/               ← ← НОВАЯ: custom hooks
│   ├── useToast.js
│   ├── useSearch.js
│   ├── useScrollDirection.js
│   └── usePagination.js
├── stores/              ← ← НОВАЯ: Zustand stores
│   ├── useFavoritesStore.js
│   ├── useCartStore.js
│   └── useCurrencyStore.js
├── utils/               ← ← НОВАЯ
│   ├── toast.js
│   ├── formatPrice.js
│   ├── formatDate.js
│   └── api.js
├── Pages/
│   ├── Auth/            ← существует
│   └── User/            ← пользовательские страницы
│       ├── Home.jsx
│       ├── UserLayout.jsx
│       ├── UserHeader.jsx
│       ├── UserFooter.jsx
│       ├── CatalogPanel.jsx
│       ├── Products/
│       │   ├── Index.jsx
│       │   └── Show.jsx
│       ├── News/
│       │   ├── Index.jsx
│       │   └── Show.jsx
│       ├── Articles/
│       │   ├── Index.jsx
│       │   └── Show.jsx
│       ├── Faq/
│       │   └── Index.jsx
│       ├── Pages/
│       │   └── Show.jsx
│       ├── Promotions/
│       │   ├── Index.jsx
│       │   └── Show.jsx
│       ├── Favorites.jsx
│       ├── Wishlist.jsx
│       ├── Search/
│       │   └── Index.jsx
│       ├── Cart/
│       │   └── Index.jsx
│       ├── Checkout/
│       │   └── Index.jsx
│       ├── Media/
│       │   └── Index.jsx
│       └── Cabinet/
│           ├── Dashboard.jsx
│           ├── Profile.jsx
│           ├── ChangePassword.jsx
│           ├── Orders/
│           │   ├── Index.jsx
│           │   └── Show.jsx
│           ├── Companies/
│           │   ├── Index.jsx
│           │   └── Upsert.jsx
│           ├── Addresses/
│           │   ├── Index.jsx
│           │   └── Upsert.jsx
│           ├── Returns/
│           │   ├── Index.jsx
│           │   ├── Create.jsx
│           │   └── Show.jsx
│           └── Exports/
│               ├── Index.jsx
│               └── Upsert.jsx
└── Admin/              ← НЕ ТРОГАТЬ
```

### 2.2 Правила именования

| Что | Правило | Пример |
|-----|---------|--------|
| Компоненты | PascalCase, один компонент = один файл | `ProductCard.jsx` |
| Хуки | `use` + PascalCase | `useSearch.js` |
| Сторы (Zustand) | `use` + PascalCase + `Store` | `useCartStore.js` |
| Утилиты | camelCase | `formatPrice.js` |
| Страницы | PascalCase, в `Pages/User/` | `Pages/User/Cart/Index.jsx` |
| Контроллеры | PascalCase, в `Controllers/User/` | `User/FaqController.php` |
| Routes | kebab-case URLs | `/cabinet/product-exports` |

### 2.3 Правила компонентов

```jsx
// ✅ Правильный компонент
export default function ProductCard({ product, onFavoriteToggle, showPrice = true }) {
    // 1. Hooks — сначала все хуки
    const { isFavorite, add, remove } = useFavoritesStore();
    const toast = useToast();

    // 2. Derived state
    const isFav = isFavorite(product.id);

    // 3. Handlers
    const handleToggleFavorite = useCallback(() => {
        // ...
    }, [product.id]);

    // 4. Render
    return (
        <Card.Root>...</Card.Root>
    );
}
```

**Правила:**
1. Один `export default` на файл = одна страница/компонент
2. Props деструктурируются в сигнатуре
3. Компоненты — function declarations (не arrow functions для компонентов верхнего уровня)
4. Chakra UI компоненты вместо HTML (`Box` вместо `div`, `Text` вместо `p`)
5. Никаких inline-стилей — только Chakra props и тема
6. Мемоизация через `useMemo`/`useCallback` где оправдано (списки, тяжёлые рендеры)
7. Все строки UI — на русском языке, захардкожены прямо в компонентах (сайт только на русском, i18n не используется)

### 2.4 Паттерн страниц (Inertia)

```jsx
// Pages/User/News/Index.jsx
import { Head } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';

export default function NewsIndex({ news, seo, breadcrumbs }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Head title={seo?.title} />
            <Breadcrumbs items={breadcrumbs} />
            {/* Content */}
        </UserLayout>
    );
}
```

### 2.5 Паттерн контроллеров (Laravel)

```php
// app/Http/Controllers/User/NewsController.php
namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\News;
use Inertia\Inertia;

class NewsController extends Controller
{
    public function index(Request $request)
    {
        $news = News::where('is_published', true)
            ->orderByDesc('published_at')
            ->paginate(12);

        $seo = app(SeoService::class)->generate([
            'title' => 'Новости',
            'url' => $request->url(),
        ]);

        return Inertia::render('User/News/Index', [
            'news' => $news,
            'seo' => $seo,
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => '/'],
                ['label' => 'Новости'],
            ],
        ]);
    }
}
```

### 2.6 Паттерн Zustand-стора

```js
// stores/useFavoritesStore.js
import { create } from 'zustand';

export const useFavoritesStore = create((set, get) => ({
    ids: new Set(),
    loaded: false,

    loadOnce: async () => {
        if (get().loaded) return;
        const { data } = await window.axios.get('/api/favorites/ids');
        set({ ids: new Set(data?.product_ids || []), loaded: true });
    },

    isFavorite: (id) => get().ids.has(Number(id)),

    toggle: async (productId) => {
        const pid = Number(productId);
        const was = get().isFavorite(pid);
        // Optimistic update
        set((s) => {
            const next = new Set(s.ids);
            was ? next.delete(pid) : next.add(pid);
            return { ids: next };
        });
        try {
            was
                ? await window.axios.delete(`/api/favorites/${pid}`)
                : await window.axios.post(`/api/favorites/${pid}`);
        } catch {
            // Rollback
            set((s) => {
                const next = new Set(s.ids);
                was ? next.add(pid) : next.delete(pid);
                return { ids: next };
            });
        }
    },
}));
```

### 2.7 Паттерн API-маршрутов

```php
// routes/user.php — структура
// Публичные страницы (без авторизации)
Route::get('/...', [...]);

// API-endpoints (без авторизации, для гостей)
Route::prefix('api')->group(function () {
    Route::get('/banners', ...);
    Route::get('/stories', ...);
    Route::get('/search/suggestions', ...);
});

// API-endpoints (с авторизацией)
Route::prefix('api')->middleware('auth')->group(function () {
    Route::get('/favorites/ids', ...);
    Route::post('/favorites/{product}', ...);
    Route::delete('/favorites/{product}', ...);
    Route::get('/cart/summary', ...);
    Route::post('/cart/items', ...);
    // ...
});

// Cabinet (с авторизацией)
Route::middleware('auth')->prefix('cabinet')->name('cabinet.')->group(function () {
    Route::get('/dashboard', ...);
    Route::get('/orders', ...);
    // ...
});
```

### 2.8 Ограничения для неавторизованных пользователей

> **Ключевое бизнес-правило:** неавторизованный пользователь (гость) **НЕ видит** цены, остатки, избранное, корзину и курсы валют.

| Функция | Гость | Авторизованный |
|---------|-------|----------------|
| Каталог / карточка товара | ✅ (без цены, без остатков) | ✅ (с ценой и остатками) |
| Кнопка «В корзину» | ❌ скрыта | ✅ |
| Кнопка «❤️ Избранное» | ❌ скрыта | ✅ |
| Переключатель валюты | ❌ скрыт | ✅ |
| Мини-корзина в хедере | ❌ скрыта | ✅ |
| Счётчик избранного в хедере | ❌ скрыт | ✅ |
| Страницы корзины, избранного, кабинета | Redirect → Login | ✅ |
| Публичные страницы (новости, FAQ, статьи) | ✅ | ✅ |
| Поиск | ✅ (без цен в suggestions) | ✅ (с ценами) |

**Реализация на фронтенде:**

Inertia передаёт `auth.user` во все страницы через `HandleInertiaRequests` middleware. Компоненты проверяют наличие пользователя:

```jsx
import { usePage } from '@inertiajs/react';

function ProductCard({ product }) {
    const { auth } = usePage().props;
    const isAuthenticated = !!auth?.user;

    return (
        <Card.Root>
            <Image src={product.main_image} />
            <Text>{product.name}</Text>
            {isAuthenticated && <Text fontWeight="bold">{formatPrice(product.userPrice)}</Text>}
            {isAuthenticated && (
                <HStack>
                    <FavoriteButton productId={product.id} />
                    <AddToCartButton productId={product.id} />
                </HStack>
            )}
        </Card.Root>
    );
}
```

**Реализация на бэкенде:**

Контроллеры передают цены и остатки **только** авторизованным пользователям:

```php
public function show(Request $request, Product $product)
{
    $data = ['product' => $product, 'seo' => ..., 'breadcrumbs' => ...];

    if ($request->user()) {
        $data['userPrice'] = app(PriceService::class)->getUserPrice($product, $request->user());
        $data['stock'] = app(StockService::class)->getStock($product);
    }

    return Inertia::render('User/Products/Show', $data);
}
```

**Zustand-сторы:** `useFavoritesStore`, `useCartStore`, `useCurrencyStore` — **не инициализируются** для гостей. Метод `loadOnce()` проверяет `auth.user` и ничего не делает если гость.

---

## 3. Послойная реализация

### Слой 1 · Базовая инфраструктура

> **Цель:** Общие компоненты и утилиты, которые используются везде.
> **Зависимости:** нет
> **Результат:** можно строить любые страницы

#### 1.1 SEO-компонент `SeoHead.jsx`

**Файл:** `components/common/SeoHead.jsx`

**Входные данные (props):**
```ts
{
    seo: {
        title: string,
        description: string,
        keywords?: string,
        image?: string,
        url: string,
        type: 'website' | 'article' | 'product',
        structured_data?: object[],
    }
}
```

**Логика:**
- Рендерит `<Head>` от Inertia с мета-тегами Open Graph + Twitter Card
- Рендерит `<script type="application/ld+json">` для structured data
- Не рендерит пустые/null мета-теги

**Бэкенд:** `SeoService` и `StructuredDataService` уже есть и используются в `HomeController`.

#### 1.2 Breadcrumbs

**Файл:** `components/common/Breadcrumbs.jsx`

**Props:**
```ts
{ items: Array<{ label: string, url?: string }> }
```

- Chakra `Breadcrumb` компонент
- Последний элемент — текст без ссылки
- Генерирует `BreadcrumbList` JSON-LD (через `SeoHead` или inline)

#### 1.3 Компоненты общего назначения

| Файл | Props | Назначение |
|------|-------|-----------|
| `PageHeader.jsx` | `{ title, subtitle?, actions? }` | Заголовок страницы с подзаголовком и кнопками |
| `EmptyState.jsx` | `{ icon, title, description, action? }` | Пустое состояние (0 результатов) |
| `NotFound.jsx` | — | Страница 404, подключить как fallback в Inertia |
| `ScrollToTop.jsx` | — | Кнопка прокрутки вверх, появляется при scroll > 300px |
| `Pagination.jsx` | `{ links, onPageChange }` | Обёртка над Laravel пагинацией |
| `IconWithCounter.jsx` | `{ icon, count, onClick }` | Иконка с бейджем (корзина, избранное) |
| `QuantityControl.jsx` | `{ value, onChange, min?, max? }` | Input с +/- кнопками |

#### 1.4 Утилиты

| Файл | Экспорт | Назначение |
|------|---------|-----------|
| `utils/formatPrice.js` | `formatPrice(amount, currency?)` | Форматирование цены с валютой |
| `utils/formatDate.js` | `formatDate(date, format?)` | Форматирование дат |
| `utils/api.js` | `apiGet`, `apiPost`, `apiPut`, `apiDelete` | Обёртки над `window.axios` с обработкой ошибок |
| `hooks/useToast.js` | `useToast()` → `{ success, error, info }` | Хук для Chakra Toaster |
| `utils/toast.js` | `toastSuccess`, `toastError` | Императивные тосты |

#### 1.5 Zustand-сторы (начальные)

**`stores/useFavoritesStore.js`**

| Метод | Описание |
|-------|---------|
| `loadOnce()` | Загрузка ID избранных с сервера (одноразово) |
| `isFavorite(id)` | Проверка |
| `toggle(id)` | Optimistic add/remove + API |

**`stores/useCartStore.js`**

| Метод | Описание |
|-------|---------|
| `init()` | Загрузка из localStorage |
| `getQuantity(id)` | Кол-во товара в корзине |
| `getTotalQuantity()` | Общее кол-во |
| `setQuantity(id, qty)` | Установка + debounced API sync |
| `clear()` | Очистка корзины |

**`stores/useCurrencyStore.js`**

| Метод | Описание |
|-------|---------|
| `current` | Текущий код валюты |
| `switch(code)` | Переключение + API |

#### 1.6 Бэкенд для API Этапа 1

Нужны endpoint-ы для сторов:

```php
// routes/user.php — добавить
Route::prefix('api')->middleware('auth')->group(function () {
    Route::get('/favorites/ids', [User\FavoriteController::class, 'ids']);
    Route::post('/favorites/{product}', [User\FavoriteController::class, 'store']);
    Route::delete('/favorites/{product}', [User\FavoriteController::class, 'destroy']);

    Route::get('/cart/summary', [User\CartController::class, 'summary']);
    Route::post('/cart/items', [User\CartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [User\CartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [User\CartController::class, 'removeItem']);
});

Route::prefix('api')->group(function () {
    Route::post('/currency/switch', [User\CurrencyController::class, 'switch']);
});
```

**Контроллеры:**
- `User/FavoriteController` — toggle избранного
- `User/CartController` — CRUD items (использует `CartService`)
- `User/CurrencyController` — переключение валюты (сессия/cookie)

---

### Слой 2 · Контент и статические страницы

> **Зависимости:** Слой 1 (Breadcrumbs, SeoHead, Pagination, PageHeader)
> **Цель:** все текстовые публичные страницы

#### 2.1 FAQ

| Компонент | Роль |
|-----------|------|
| Контроллер `User/FaqController` | `GET /faq` → Inertia render |
| Страница `Pages/User/Faq/Index.jsx` | Chakra `Accordion` с категориями |

**Данные из бэкенда:**
```ts
{
    faqs: Array<{ id, question, answer, category?, sort_order }>,
    seo: SeoData,
    breadcrumbs: BreadcrumbItem[],
}
```

**Компонент:** группировка по `category`, каждая группа — аккордеон.

#### 2.2 CMS-страницы

| Компонент | Роль |
|-----------|------|
| Контроллер `User/PageController` | `GET /pages/{slug}` — поиск Page по slug |
| Страница `Pages/User/Pages/Show.jsx` | Рендер `dangerouslySetInnerHTML` + стили prose |

**Обработка 404:** если Page не найден или `is_published = false` → `abort(404)`.

#### 2.3 Новости

| Элемент | Детали |
|---------|--------|
| Контроллер `User/NewsController` | `index()` — пагинация 12/стр., `show($slug)` |
| `Pages/User/News/Index.jsx` | Grid карточек: изображение, дата, заголовок, excerpt |
| `Pages/User/News/Show.jsx` | Детальная: заголовок, дата, изображение, контент |
| Компонент `components/common/ContentCard.jsx` | Переиспользуемая карточка (для новостей и статей) |

**ContentCard props:**
```ts
{
    title: string,
    excerpt?: string,
    image?: string,
    date: string,
    url: string,
    tags?: Tag[],
}
```

#### 2.4 Статьи

Аналогично новостям: `User/ArticleController`, `Pages/User/Articles/Index.jsx`, `Show.jsx`.
Переиспользуют `ContentCard`.

---

### Слой 3 · Визуальные элементы главной

> **Зависимости:** Слой 1
> **Цель:** Баннеры, Stories, подборки — наполнение Home.jsx

#### 3.1 Баннеры

| Элемент | Детали |
|---------|--------|
| Контроллер `User/BannerController` | `GET /api/banners` — активные баннеры, отсортированные по `sort_order` |
| `components/banner/BannerSlider.jsx` | Карусель (Chakra Carousel или swiper): изображение, ссылка, overlay-текст |

**Квери:** `Banner::where('is_active', true)->orderBy('sort_order')->get()`

**Подключение:** вызов из `Home.jsx` через `useEffect` + API или через Inertia prop.

#### 3.2 Stories

| Элемент | Детали |
|---------|--------|
| Контроллер `User/StoryController` | `GET /api/stories` — активные stories со slides |
| `components/stories/StoryCircles.jsx` | Горизонтальная прокрутка круглых превью |
| `components/stories/StoryViewer.jsx` | Полноэкранный просмотр: slide-by-slide, swipe, auto-progress |

**Квери:** `Story::where('is_active', true)->with('slides')->orderBy('sort_order')->get()`

#### 3.3 Обновление Home.jsx

```jsx
export default function Home({ seo, banners, stories, productSelections }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <StoryCircles stories={stories} />
            <BannerSlider banners={banners} />
            {productSelections.map(sel => (
                <ProductSelectionCarousel key={sel.id} selection={sel} />
            ))}
        </UserLayout>
    );
}
```

#### 3.4 Подборки товаров

| Элемент | Детали |
|---------|--------|
| Контроллер `User/ProductSelectionController` | Данные для главной и карточки товара |
| `components/product/ProductSelectionCarousel.jsx` | Горизонтальная карусель `ProductCard` |

---

### Слой 4 · Товары: карточка и расширенный каталог

> **Зависимости:** Слои 1, 2 (инфраструктура + контент для «похожих»)
> **Ключевой слой** — открывает большинство дальнейших функций

#### 4.1 Карточка товара

**Маршрут:** `GET /products/{slug}`
**Контроллер:** `User/ProductDetailController`

**Данные для фронтенда:**
```ts
{
    product: {
        id, name, slug, description, description_html,
        sku, code, is_new, is_bestseller,
        brand: { id, name, slug },
        categories: [{ id, name, slug }],
        media: { main: url, additional: url[], video?: url },
        attributes: [{ name, group, values: [{ value }] }],
        certificates: [{ name, media_url }],
        size_chart?: { name, data },
        promotions: [{ id, title, slug }],
    },
    // ⚠️ Только для авторизованных:
    userPrice?: number,                       // из PriceService (null для гостя)
    stock?: { available, preorder },           // из StockService (null для гостя)
    warehouses?: [{ name, city, quantity }],   // остатки по складам (null для гостя)
    relatedProducts: Product[],
    seo: SeoData,
    breadcrumbs: BreadcrumbItem[],
}
```

**Компоненты фронтенда:**

| Компонент | Файл | Назначение |
|-----------|------|-----------|
| ProductGallery | `components/product/ProductGallery.jsx` | Главное фото + миниатюры + видео. Lightbox при клике |
| ProductInfo | `components/product/ProductInfo.jsx` | Цена (старая/новая), наличие, бренд, SKU, кнопки «В корзину» / «❤️» — **цена, остатки, кнопки скрыты для гостей** |
| ProductTabs | `components/product/ProductTabs.jsx` | Вкладки: Описание, Характеристики, Сертификаты, Размерная сетка |
| ProductCard | `components/product/ProductCard.jsx` | Карточка в сетке: фото, название, бренд. **Цена и кнопки — только для авторизованных** |
| ProductGrid | `components/product/ProductGrid.jsx` | Сетка карточек (responsive: 2-3-4 колонки) |

**`ProductCard` props:**
```ts
{
    product: {
        id, name, slug,
        main_image?: string,
        brand?: { name },
        is_new?: boolean,
        is_bestseller?: boolean,
    },
    userPrice?: number,       // null для гостей → блок цены скрыт
    isAuthenticated: boolean, // из usePage().props.auth
    isFavorite?: boolean,     // скрыто для гостей
    cartQuantity?: number,    // скрыто для гостей
    onFavoriteToggle?: (id) => void,
    onAddToCart?: (id) => void,
}
```

#### 4.2 Каталог по категории

**Маршрут:** `GET /categories/{slug}`
**Контроллер:** `User/CategoryController`

```php
public function show(Request $request, Category $category)
{
    // Товары категории с пагинацией, фильтрами, сортировкой
    return Inertia::render('User/Products/Index', [
        'initialCategory' => $category,
        'products' => $products,
        'filters' => $availableFilters,
        'seo' => ...,
    ]);
}
```

#### 4.3 Каталог по бренду

**Маршрут:** `GET /brands/{slug}`
**Контроллер:** `User/BrandController`

Аналогично категории, но с `initialBrand`.

#### 4.4 Фильтрация в каталоге

**Обновить существующий `CatalogPanel.jsx`:**

- Боковая панель фильтров: по категории, бренду, цене (range), атрибутам, тегам
- Сортировка: цена ↑↓, название, новинки, бестселлеры
- Пагинация (использовать `Pagination.jsx` из Слоя 1)
- URL-параметры: `?category=&brand=&min_price=&max_price=&sort=&page=`

**Компонент `components/common/FilterBlock.jsx`:**
```ts
{
    title: string,
    type: 'checkbox' | 'range' | 'radio',
    options?: Array<{ value, label, count? }>,
    value: any,
    onChange: (value) => void,
}
```

---

### Слой 5 · Валюта

> **Зависимости:** Слой 1
> ⚠️ **Доступно только авторизованным пользователям**

#### 5.1 Переключатель валюты

| Элемент | Детали |
|---------|--------|
| `User/CurrencyController` | `GET /api/currencies` — список, `POST /api/currency/switch` — выбор |
| `components/common/CurrencySwitcher.jsx` | Dropdown в хедере: флаг + код валюты. **Скрыт для гостей** |
| `stores/useCurrencyStore.js` | Текущая валюта, переключение. Не инициализируется для гостей |

**Логика переключения:**
1. POST → сервер сохраняет `currency_id` в User (middleware `auth`)
2. Стор обновляется
3. `router.reload()` для обновления цен на текущей странице

---

### Слой 6 · Акции

> **Зависимости:** Слои 1, 4 (нужны ProductCard, ProductGrid)

#### 6.1 Список акций

| Элемент | Детали |
|---------|--------|
| `User/PromotionController` | `index()` — пагинация, `show($slug)` |
| `Pages/User/Promotions/Index.jsx` | Grid карточек акций: изображение, название, даты |
| `Pages/User/Promotions/Show.jsx` | Детальная: описание + ProductGrid товаров акции |

**Компонент `PromotionCard`** — можно как variant `ContentCard` или отдельный.

---

### Слой 7 · Избранное и Wishlist

> **Зависимости:** Слои 1, 4
> ⚠️ **Доступно только авторизованным пользователям** — гость не видит кнопки и не имеет доступа к страницам

#### 7.1 Избранное

| Элемент | Детали |
|---------|--------|
| `User/FavoriteController` | `index()` — страница, API: ids/store/destroy |
| `Pages/User/Favorites.jsx` | `ProductGrid` с товарами из избранного |
| Стор `useFavoritesStore` | Уже создан в Слое 1, используется в `ProductCard` |

**Интеграция с `ProductCard`:** кнопка ❤️ вызывает `useFavoritesStore.toggle(id)`.

**Интеграция с `UserHeader`:** `IconWithCounter` с count из стора.

#### 7.2 Wishlist

Аналогично избранному, но через `User/WishlistController` и отдельный стор `useWishlistStore`.

---

### Слой 8 · Поиск

> **Зависимости:** Слои 1, 4

#### 8.1 Поиск

| Элемент | Детали |
|---------|--------|
| `User/SearchController` | `index()` — страница, `suggestions()` — API top-5 |
| `Pages/User/Search/Index.jsx` | `ProductGrid` + фильтры + пагинация |
| `components/search/SearchDropdown.jsx` | Input в хедере + dropdown с suggestions |
| `hooks/useSearch.js` | Debounced запрос к `/api/search/suggestions` |

**SearchDropdown props:**
```ts
{
    // без props — хук useSearch управляет состоянием
}
```

**Логика useSearch:**
1. Input → debounce 300ms → `GET /api/search/suggestions?q=`
2. Показать dropdown с результатами
3. Enter или клик → `router.visit('/search?q=')`

**Backend `User/SearchController`:**
```php
public function suggestions(Request $request)
{
    $results = Product::search($request->q)
        ->take(5)
        ->get(['id', 'name', 'slug', 'base_price'])
        ->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'url' => "/products/{$p->slug}",
            'image' => $p->getFirstMediaUrl('main', 'thumb'),
            // ⚠️ Цена только для авторизованных
            'price' => $request->user()
                ? app(PriceService::class)->getUserPrice($p, $request->user())
                : null,
        ]);

    return response()->json($results);
}
```

---

### Слой 9 · Корзина

> **Зависимости:** Слои 4, 5
> ⚠️ **Доступно только авторизованным пользователям** — гость не видит корзину, мини-корзину и кнопки «В корзину»

#### 9.1 Полная страница корзины

| Элемент | Детали |
|---------|--------|
| `User/CartController` | `index()` — страница, API: add/update/remove/summary |
| `Pages/User/Cart/Index.jsx` | Таблица товаров, итоги, кнопка «Оформить» |
| `components/cart/CartItemRow.jsx` | Строка: фото, название, цена, QuantityControl, удалить |
| `components/cart/CartDropdown.jsx` | Мини-корзина в хедере (popup) |

**CartItemRow props:**
```ts
{
    item: { id, product_id, product: { name, slug, main_image }, quantity, price },
    onQuantityChange: (itemId, qty) => void,
    onRemove: (itemId) => void,
}
```

**Cart/Index.jsx — структура:**
```
┌─────────────────────────────────────────────┐
│ Корзина                           [Очистить]│
├──────┬──────────┬───────┬────────┬──────────┤
│ Фото │ Название │ Цена  │ Кол-во │ Сумма    │
│      │          │       │ [-][+] │          │
├──────┴──────────┴───────┴────────┴──────────┤
│                         Итого: 15 000 ₽     │
│                    [Оформить заказ]          │
└─────────────────────────────────────────────┘
```

**Backend `User/CartController`:**
- `addItem()` — принимает `{ product_id, quantity }`, создаёт/обновляет CartItem
- `updateItem()` — `PATCH /api/cart/items/{item}` с `{ quantity }`
- `removeItem()` — `DELETE /api/cart/items/{item}`
- `summary()` — `GET /api/cart/summary` → возвращает результат `CartService::getCartSummary()`

---

### Слой 10 · Checkout и заказы

> **Зависимости:** Слой 9 (корзина)

#### 10.1 Checkout

| Элемент | Детали |
|---------|--------|
| `User/CheckoutController` | `index()` — страница, `store()` — оформление |
| `Pages/User/Checkout/Index.jsx` | Форма: компания, адрес, комментарий, итоги |

**Данные для фронтенда:**
```ts
{
    cart: { items: CartItem[], summary: CartSummary },
    companies: Company[],
    addresses: DeliveryAddress[],
    seo: SeoData,
}
```

**Логика оформления:**
1. Пользователь выбирает компанию и адрес
2. POST `/checkout` → `CheckoutService::checkout()` (уже есть)
3. Redirect на `/cabinet/orders/{uuid}` при успехе

#### 10.2 Список заказов

| Элемент | Детали |
|---------|--------|
| `User/OrderController` | `index()`, `show($uuid)` |
| `Pages/User/Cabinet/Orders/Index.jsx` | Таблица: №, дата, статус, сумма, действия |
| `Pages/User/Cabinet/Orders/Show.jsx` | Детали: позиции, статусы, история |

**Orders/Index.jsx — структура:**
```
┌───────┬────────────┬──────────┬──────────┬─────────┐
│ №     │ Дата       │ Статус   │ Сумма    │         │
│ 00001 │ 12.02.2026 │ ● Новый  │ 15 000 ₽ │ [>]     │
└───────┴────────────┴──────────┴──────────┴─────────┘
```

**Фильтры:** по статусу (`OrderStatus` enum), по дате.

**Orders/Show.jsx — структура:**
```
Заказ #00001   Статус: ● Новый
─────────────────────────────
Позиции:
  Товар А × 2   = 10 000 ₽
  Товар Б × 1   = 5 000 ₽
─────────────────────────────
Итого: 15 000 ₽

Компания: ООО «Пример»
Адрес доставки: г. Москва, ул. ...

История статусов:
  12.02 10:00   Создан
  12.02 11:00   В обработке
```

---

### Слой 11 · Профиль пользователя

> **Зависимости:** Слой 1

#### 11.1 Профиль

| Элемент | Детали |
|---------|--------|
| `User/ProfileController` | `show()`, `update()`, `updatePassword()` |
| `Pages/User/Cabinet/Profile.jsx` | Форма: имя, фамилия, отчество, email, телефон, город, страна |
| `Pages/User/Cabinet/ChangePassword.jsx` | Текущий пароль, новый × 2 |

**Валидация на бэкенде:** FormRequest с правилами.

---

### Слой 12 · Компании

> **Зависимости:** Слои 1, 11

#### 12.1 CRUD компаний

| Элемент | Детали |
|---------|--------|
| `User/CompanyController` | CRUD: index, create, store, edit, update, destroy |
| `Pages/User/Cabinet/Companies/Index.jsx` | Таблица: название, ИНН, действия |
| `Pages/User/Cabinet/Companies/Upsert.jsx` | Форма: название, юр. название, ИНН, ОГРН, КПП, ОКПО, адреса, контакты, банковские реквизиты |

**Банковские счета:** inline-форма внутри Upsert (nested CRUD).
Модель `CompanyBankAccount`: bank_name, bik, account, corr_account, is_primary.

**Scope:** компании только текущего пользователя (`CompanyScope` уже есть).

---

### Слой 13 · Адреса доставки

> **Зависимости:** Слои 11, 10 (используются в checkout)

| Элемент | Детали |
|---------|--------|
| `User/DeliveryAddressController` | CRUD |
| `Pages/User/Cabinet/Addresses/Index.jsx` | Список адресов |
| `Pages/User/Cabinet/Addresses/Upsert.jsx` | Форма |
| `components/common/AddressSelect.jsx` | Dropdown выбора адреса для checkout |

---

### Слой 14 · Возвраты

> **Зависимости:** Слои 10, 12 (нужны заказы и компании)

| Элемент | Детали |
|---------|--------|
| `User/ReturnController` | index, create, store, show |
| `Pages/User/Cabinet/Returns/Index.jsx` | Таблица возвратов |
| `Pages/User/Cabinet/Returns/Create.jsx` | Wizard: заказ → позиции → причина |
| `Pages/User/Cabinet/Returns/Show.jsx` | Детали возврата |

**Wizard создания:**
1. Шаг 1: Выбор заказа (из списка заказов пользователя)
2. Шаг 2: Выбор позиций и кол-ва (из OrderItems выбранного заказа)
3. Шаг 3: Причина (`ReturnReason` enum) + комментарий
4. Подтверждение → POST

**Enums:** `ReturnStatus` (new, approved, rejected, completed), `ReturnReason` (defect, wrong_item, other...).

---

### Слой 15 · Экспорт товаров

> **Зависимости:** Слой 4

| Элемент | Детали |
|---------|--------|
| `User/ProductExportController` | CRUD + generate + download |
| `Pages/User/Cabinet/Exports/Index.jsx` | Список выгрузок со статусами |
| `Pages/User/Cabinet/Exports/Upsert.jsx` | Конструктор: фильтры, колонки, формат |

**Конструктор выгрузки:**
- нужно посмотреть как реализованы выгрузки в админке с сделать идентично для пользователей.

**Backend:** `ProductExportService` уже реализован.

---

### Слой 16 · Медиа-каталог

> **Зависимости:** Слой 1

| Элемент | Детали |
|---------|--------|
| `User/MediaCatalogController` | `index()` — страница, API: список, скачивание |
| `Pages/User/Media/Index.jsx` | Сетка файлов с фильтрами и скачиванием |

**Фильтры:** по типу файла (image, video, document), по имени.

---

### Слой 17 · Сервисные маршруты

> **Зависимости:** все предыдущие

| Маршрут | Контроллер | Детали |
|---------|-----------|--------|
| `GET /sitemap.xml` | `User/SitemapController` | XML sitemap: товары, категории, бренды, новости, статьи, страницы |
| `GET /health` | `User/HealthController` | `{ status: ok, timestamp }` |

---

## 4. Навигация кабинета

**Компонент `CabinetSidebar.jsx`** — боковое меню кабинета:

```
┌──────────────────────┐
│ 📊 Дашборд           │
│ 📦 Заказы            │
│ ❤️ Избранное          │
│ 🛒 Корзина           │
│ 👤 Профиль           │
│ 🏢 Компании          │
│ 📍 Адреса            │
│ ↩️ Возвраты           │
│ 📤 Экспорт           │
│ 🚪 Выйти             │
└──────────────────────┘
```

**CabinetLayout.jsx** — обёртка для страниц кабинета:
```jsx
export default function CabinetLayout({ children }) {
    return (
        <UserLayout>
            <Flex>
                <CabinetSidebar />
                <Box flex="1" p={6}>
                    {children}
                </Box>
            </Flex>
        </UserLayout>
    );
}
```

---

## 5. Обновление UserHeader.jsx

После реализации всех слоёв, хедер должен содержать:

```
┌──────────────────────────────────────────────────────┐
│ [Logo]  Каталог  Акции  Новости  Статьи  FAQ         │
│                    [🔍 Поиск...]  [💱] [❤️3] [🛒5] [👤]│
└──────────────────────────────────────────────────────┘
```

| Элемент | Компонент | Слой |
|---------|-----------|------|
| Навигация | inline links | 2 |
| Поиск | `SearchDropdown` | 8 |
| Валюта | `CurrencySwitcher` | 5 |
| Избранное | `IconWithCounter` + store | 7 |
| Корзина | `CartDropdown` | 9 |
| Пользователь | `UserMenu` | 1 |

---

## 6. Порядок коммитов

Каждый слой = отдельная ветка + PR:

```
feature/layer-01-infrastructure
feature/layer-02-content-pages
feature/layer-03-home-visuals
feature/layer-04-product-catalog
feature/layer-05-currency
feature/layer-06-promotions
feature/layer-07-favorites
feature/layer-08-search
feature/layer-09-cart
feature/layer-10-checkout-orders
feature/layer-11-profile
feature/layer-12-companies
feature/layer-13-addresses
feature/layer-14-returns
feature/layer-15-exports
feature/layer-16-media
feature/layer-17-services
```

---

## 7. Best Practices

### 7.1 Обработка ошибок

**Фронтенд — три уровня:**

```
1. Компонент  →  try/catch в обработчиках  →  toast с сообщением
2. API-слой   →  interceptor в axios       →  403 → redirect /login
                                           →  422 → показ ошибок валидации
                                           →  500 → toast «Ошибка сервера»
3. Глобально  →  React Error Boundary      →  fallback UI «Что-то пошло не так»
```

**Пример axios interceptor** (`bootstrap.js`):
```js
window.axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 401) {
            window.location.href = '/login';
        }
        if (error.response?.status === 403) {
            toastError('Нет доступа');
        }
        if (error.response?.status >= 500) {
            toastError('Ошибка сервера. Попробуйте позже.');
        }
        return Promise.reject(error);
    }
);
```

**Бэкенд — правила:**
- Никогда не возвращать stack trace в production
- Все ожидаемые ошибки — через `abort()` с осмысленным статусом
- Валидация — только через `FormRequest` (не в контроллере)
- Все неожиданные исключения логируются в Laravel log

### 7.2 Работа с формами (Inertia useForm)

Всегда использовать `useForm` от Inertia для форм с отправкой данных — он даёт бесплатную обработку ошибок валидации, состояния загрузки и reset:

```jsx
import { useForm } from '@inertiajs/react';

export default function ProfileForm({ user }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put('/cabinet/profile', {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Профиль обновлён'),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <Field label="Имя" invalid={!!errors.name} errorText={errors.name}>
                <Input value={data.name} onChange={e => setData('name', e.target.value)} />
            </Field>
            {/* ... */}
            <Button type="submit" loading={processing}>Сохранить</Button>
        </form>
    );
}
```

**Правила:**
- `useForm` — для POST/PUT/DELETE форм (профиль, checkout, создание компании)
- `router.get` с параметрами — для фильтров / поиска (GET-запросы, обновление URL)
- Не смешивать `useForm` и ручной `axios` — выбрать одно

### 7.3 Loading и Skeleton states

**Правило:** каждая страница с данными должна иметь skeleton-состояние, пока данные не загружены.

```jsx
import { Skeleton, SkeletonText } from '@chakra-ui/react';

function ProductCardSkeleton() {
    return (
        <Card.Root>
            <Skeleton height="200px" />
            <Card.Body>
                <SkeletonText noOfLines={2} />
                <Skeleton height="20px" width="60%" mt={2} />
            </Card.Body>
        </Card.Root>
    );
}
```

**Где использовать:**
- `ProductGrid` — при смене фильтров/страницы
- `CartDropdown` — при инициализации стора
- Любой компонент с `useEffect` → API

**Inertia NProgress:** уже настроен в `app.jsx` (`progress: { color: '#4B5563' }`). Полоса загрузки показывается при навигации между страницами автоматически.

### 7.4 Производительность

**Lazy loading компонентов:**
```jsx
import { lazy, Suspense } from 'react';

const StoryViewer = lazy(() => import('@/components/stories/StoryViewer'));

// В компоненте:
<Suspense fallback={<Spinner />}>
    <StoryViewer story={activeStory} />
</Suspense>
```

**Когда использовать lazy loading:**
- Модальные окна / drawer-ы (StoryViewer, ProductQuickView)
- Тяжёлые компоненты, которые не видны при первой загрузке

**Когда НЕ использовать:**
- Основной контент страницы (ProductGrid, CartItemRow)
- Компоненты в хедере/футере

**Изображения:**
- Всегда указывать `width` и `height` (или aspect-ratio) для предотвращения CLS
- Использовать `loading="lazy"` для изображений ниже viewport
- Thumbnails: из spatie media library → conversions (уже настроены)
- Формат: WebP с fallback (через `<picture>` или серверную конвертацию)

**Пагинация:**
- Никогда не загружать все товары — только `paginate(12)` / `paginate(24)`
- Каталог: серверная пагинация через Inertia (URL меняется)
- Бесконечный скролл НЕ используется — только кнопки страниц

**Запросы к БД:**
- Всегда `with()` для предотвращения N+1 (использовать `preventLazyLoading()` в AppServiceProvider)
- Не загружать лишние поля — `select()` где большие текстовые колонки
- В списках не грузить `description_html` — только в детальной

### 7.5 Безопасность

| Угроза | Защита |
|--------|--------|
| CSRF | Автоматическая через Inertia (отправляет XSRF-TOKEN cookie) |
| XSS | Не использовать `dangerouslySetInnerHTML` кроме CMS-контента. CMS-контент рендерить с HTML-санитизацией на бэкенде |
| Mass Assignment | `$fillable` в моделях (уже есть). Никогда `$guarded = []` |
| IDOR | Scope модели к текущему пользователю: `$request->user()->companies()` вместо `Company::find($id)` |
| SQL Injection | Только Eloquent / Query Builder. Никаких raw запросов с пользовательским вводом |
| Rate Limiting | `throttle:60,1` middleware на API-эндпоинты (особенно поиск, избранное, корзина) |
| File Upload | Валидация MIME-типа и размера через FormRequest |

**Авторизация (Policy):**

Для каждой CRUD-сущности в кабинете создавать Policy:

```php
// app/Policies/CompanyPolicy.php
class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->id === $company->user_id;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->id === $company->user_id;
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->id === $company->user_id;
    }
}

// В контроллере:
$this->authorize('update', $company);
```

**Где нужны Policy:**
- Company (user_id scope)
- DeliveryAddress (user_id scope)
- ProductReturn (user_id scope)
- ProductExport (user_id scope)
- Order (user_id scope — только просмотр)

### 7.6 Управление состоянием — когда что использовать

```
┌─────────────────────────────────────────────────────────┐
│ Данные приходят с сервера при навигации?                │
│   ДА → Inertia props (products, orders, news)          │
│   НЕТ ↓                                                │
├─────────────────────────────────────────────────────────┤
│ Данные нужны на нескольких страницах?                   │
│   ДА → Zustand store (favorites, cart, currency)       │
│   НЕТ ↓                                                │
├─────────────────────────────────────────────────────────┤
│ Данные нужны нескольким компонентам на одной странице?  │
│   ДА → Поднять state до общего родителя (lift state up)│
│   НЕТ ↓                                                │
├─────────────────────────────────────────────────────────┤
│ Данные локальны для одного компонента?                  │
│   ДА → useState / useReducer                           │
└─────────────────────────────────────────────────────────┘
```

**Что НЕ класть в Zustand:**
- Данные, которые приходят через Inertia (товары, заказы, новости) — они уже реактивные
- Состояние формы — использовать `useForm`
- UI-состояние (открыт ли modal) — `useState`

**Что класть в Zustand:**
- Избранное (нужно на всех страницах с товарами + хедер)
- Корзина (нужно в хедере + карточке товара + странице корзины)
- Текущая валюта (нужно везде где цены)

### 7.7 Работа с изображениями и медиа

**Spatie Media Library** уже используется в проекте. Правила:

```php
// В контроллере — вернуть URL нужной конверсии
'main_image' => $product->getFirstMediaUrl('main', 'thumb'),    // 300x300
'gallery' => $product->getMedia('additional')->map->getUrl('medium'), // 800x800
'original' => $product->getFirstMediaUrl('main'),                // оригинал
```

**На фронтенде:**
```jsx
// Всегда с fallback
<Image
    src={product.main_image || '/images/no-image.svg'}
    alt={product.name}
    loading="lazy"
    objectFit="cover"
    height="200px"
    width="100%"
/>
```

**Правила:**
- В каталоге — только thumbnail (300×300)
- В карточке товара — medium (800×800) + оригинал для lightbox
- Всегда указывать `alt` — название товара / описание баннера
- Fallback-изображение: `/images/no-image.svg` (создать один раз)

### 7.8 Responsive-дизайн

**Breakpoints Chakra UI:**
| Токен | Размер | Устройство |
|-------|--------|-----------|
| `sm` | 480px | Мобильный (landscape) |
| `md` | 768px | Планшет |
| `lg` | 992px | Маленький десктоп |
| `xl` | 1280px | Десктоп |

**Паттерн responsive props:**
```jsx
<SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} gap={4}>
    {products.map(p => <ProductCard key={p.id} product={p} />)}
</SimpleGrid>
```

**Правила по breakpoints:**
- Каталог: 1 колонка (mobile) → 2 (sm) → 3 (md) → 4 (lg)
- Sidebar фильтров: drawer на mobile, sidebar на lg+
- Хедер: бургер-меню на mobile, полная навигация на md+
- Кабинет sidebar: скрыт на mobile, выезжает по кнопке
- Таблицы (заказы, возвраты): горизонтальный скролл на mobile или карточное представление

### 7.9 Кеширование

**Серверное:**
```php
// Тяжёлые запросы кешировать через Cache
$categories = Cache::remember('categories:tree', 3600, function () {
    return Category::with('children')->whereNull('parent_id')->get();
});

$banners = Cache::remember('banners:active', 600, function () {
    return Banner::where('is_active', true)->orderBy('sort_order')->get();
});
```

**Что кешировать:**
| Данные | TTL | Инвалидация |
|--------|-----|-------------|
| Дерево категорий | 1 час | При CRUD категории в админке |
| Баннеры | 10 мин | При CRUD баннера |
| Stories | 10 мин | При CRUD story |
| FAQ | 1 час | При CRUD |
| Курсы валют | 1 час | При обновлении курсов |

**Что НЕ кешировать:**
- Персональные данные (корзина, избранное, заказы)
- Поиск (результаты уникальны)
- Цены (зависят от пользователя, скидок, валюты)

**Фронтенд:**
- Zustand сторы с `localStorage` — для корзины (cross-tab sync)
- Inertia кеширует props между навигациями автоматически

### 7.10 Обработка пустых состояний

Каждый список должен иметь осмысленное пустое состояние, а не просто пустоту:

| Страница | Сообщение | Действие |
|----------|-----------|----------|
| Каталог (0 товаров) | «Товары по заданным фильтрам не найдены» | Кнопка «Сбросить фильтры» |
| Поиск (0 результатов) | «По запросу «...» ничего не найдено» | Предложение скорректировать запрос |
| Избранное (пусто) | «У вас пока нет избранных товаров» | Кнопка «Перейти в каталог» |
| Корзина (пусто) | «Ваша корзина пуста» | Кнопка «Перейти в каталог» |
| Заказы (0 заказов) | «У вас пока нет заказов» | Кнопка «Перейти в каталог» |
| Компании (0) | «Вы ещё не добавили компанию» | Кнопка «Добавить компанию» |
| Возвраты (0) | «У вас нет оформленных возвратов» | — |

**Компонент EmptyState:**
```jsx
<EmptyState
    icon={<LuShoppingCart />}
    title="Ваша корзина пуста"
    description="Добавьте товары из каталога, чтобы оформить заказ"
>
    <Button asChild>
        <Link href="/products">Перейти в каталог</Link>
    </Button>
</EmptyState>
```

### 7.11 Правила для URL и навигации

- Все публичные страницы — ЧПУ (человекопонятные URL):
  - `/products/krossovki-nike-air-max` — не `/products/123`
  - `/categories/obuv` — не `/categories/5`
  - `/brands/nike` — не `/brands/12`
  - `/news/novaya-kollekciya-2026` — не `/news/42`
- Кабинет: `/cabinet/orders`, `/cabinet/companies` — без slug
- API: `/api/favorites/ids`, `/api/cart/summary` — без версионирования (внутренний API)
- При 404 — показывать кастомную страницу NotFound, а не Laravel blade

### 7.12 Логирование действий пользователя

На бэкенде логировать ключевые бизнес-события через `Log::info()`:

```php
Log::info('Order created', ['order_id' => $order->id, 'user_id' => $user->id, 'total' => $total]);
Log::info('Return requested', ['return_id' => $return->id, 'order_id' => $order->id]);
Log::warning('Stock exceeded', ['product_id' => $product->id, 'requested' => $qty, 'available' => $stock]);
```

Не логировать: просмотры страниц, API-запросы поиска (Meilisearch, nginx access log достаточно).

---

## 8. Чеклист качества для каждого слоя

- [ ] Компоненты переиспользуемы (не дублируются между страницами)
- [ ] Props типизированы через JSDoc или пояснения
- [ ] Все строки интерфейса на русском языке
- [ ] SEO: title, description, OG, structured data
- [ ] Breadcrumbs на каждой внутренней странице
- [ ] Responsive: мобильная, планшет, desktop
- [ ] Empty states: осмысленное сообщение + действие при 0 результатов
- [ ] Loading states: скелетоны при загрузке данных
- [ ] Error handling: ошибки API → toast + fallback UI
- [ ] Accessibility: alt-теги, aria-labels, keyboard navigation
- [ ] Нет console.log / console.error в production
- [ ] Контроллеры используют FormRequest для валидации
- [ ] API-маршруты защищены middleware `auth` где требуется
- [ ] Гостевые ограничения: цены, остатки, избранное, корзина, валюта скрыты
- [ ] Изображения: lazy loading, fallback, правильные конверсии
- [ ] N+1 запросы: все `with()` прописаны, lazy loading предотвращён
- [ ] Policy проверяет принадлежность ресурса пользователю
- [ ] Кеширование: статические данные (категории, баннеры, FAQ) кешируются
- [ ] URL-ы человекопонятные (slug вместо ID)
