# Дорожная карта реализации пользовательской части

> Последовательный план, упорядоченный от наименее зависимых модулей к наиболее зависимым.
> Включены **только** сущности с готовыми моделями, миграциями и CRUD в админке.

---

## ✅ Уже реализовано

| Модуль | Бэкенд | Фронтенд |
|--------|--------|----------|
| Авторизация | `Controllers/Auth/*`, `SocialAuthController` | `Pages/Auth/Login`, `Register`, `ForgotPassword`, `ResetPassword`, `SocialAuthButtons` |
| Главная | `User/HomeController` | `Pages/User/Home.jsx` |
| Каталог (список) | `User/ProductController`, `User/CatalogController` | `Pages/User/Products/Index.jsx`, `CatalogPanel.jsx` |
| Дашборд кабинета | `User/CabinetController` | `Pages/User/Cabinet/Dashboard.jsx` |
| Layout | — | `UserLayout.jsx`, `UserHeader.jsx`, `UserFooter.jsx` |
| Тема | — | `theme.js` |

### Готовые сервисы (бэкенд уже написан)

| Сервис | Что делает |
|--------|-----------|
| `PriceService` | Базовая цена, цена со скидкой, цена в валюте пользователя |
| `CurrencyConversionService` | Конвертация из базовой валюты |
| `UserCurrencyResolver` | Определение валюты пользователя |
| `StockService` | Остатки: доступно / предзаказ |
| `CartService` | Сводка корзины, перемещение товаров |
| `CheckoutService` | Оформление заказа с разбивкой по наличию |
| `ProductExportService` | Генерация файлов выгрузок |

---

## ❌ Исключено (нет моделей/админки в pecado)

Вакансии · Чат · Тесты знаний · Комментарии · Где купить · Вопросы пользователя · Документы компании · Акты сверки · Группы · Динамическое меню · Коллекции · Просмотренные товары · История поиска · Категории медиа · Пункты выдачи · Вебинары · Верификация 18+

---

## Этап 1 · Базовая инфраструктура

> Зависимости: **нет**. Основа для всех следующих этапов.

### Что делать

| # | Задача | Тип | Детали |
|---|--------|-----|--------|
| 1.1 | Общие UI-компоненты | front | `Breadcrumbs`, `PageHeader`, `NotFound` (404), `EmptyState`, `ScrollToTop`, `Pagination` |
| 1.2 | Система тостов | front | `useToast` хук + утилита `toast.js` |
| 1.3 | Стор избранного | front | `useFavoritesStore.js` — загрузка ID избранных, toggle |
| 1.4 | Стор корзины | front | `useCartStore.js` — счётчик товаров, базовые операции |
| 1.5 | SEO-компонент | front | `SeoHead.jsx` — рендер мета-тегов из props `seo` |

> **Примечание:** `SeoService` и `StructuredDataService` уже существуют на бэкенде и используются в `HomeController`.

---

## Этап 2 · Статические публичные страницы

> Зависимости: **Этап 1**

### 2.1 FAQ

- **Модель:** `Faq` ✅ | **Админка:** CRUD ✅
- **Бэкенд:** `User/FaqController` → `GET /faq`
- **Фронтенд:** `Pages/User/Faq/Index.jsx` — аккордеон

### 2.2 CMS-страницы

- **Модель:** `Page` ✅ | **Админка:** CRUD ✅
- **Бэкенд:** `User/PageController` → `GET /pages/{slug}`
- **Фронтенд:** `Pages/User/Pages/Show.jsx` — рендер HTML-контента

---

## Этап 3 · Контент: Новости и Статьи

> Зависимости: **Этап 1**

### 3.1 Новости

- **Модель:** `News` ✅ (поля: title, slug, content, image, published_at, is_published)
- **Админка:** CRUD + search ✅
- **Бэкенд:** `User/NewsController`
  - `GET /news` — список с пагинацией
  - `GET /news/{slug}` — детальная
- **Фронтенд:** `Pages/User/News/Index.jsx`, `Show.jsx`

### 3.2 Статьи

- **Модель:** `Article` ✅ (поля: title, slug, content, image, published_at, is_published)
- **Админка:** CRUD + search ✅
- **Бэкенд:** `User/ArticleController`
  - `GET /articles` — список
  - `GET /articles/{slug}` — детальная
- **Фронтенд:** `Pages/User/Articles/Index.jsx`, `Show.jsx`

---

## Этап 4 · Баннеры и Stories

> Зависимости: **Этап 1**

### 4.1 Баннеры на главной

- **Модель:** `Banner` ✅ (media, позиция, ссылка, активность)
- **Админка:** CRUD ✅
- **Бэкенд:** `User/BannerController` → `GET /api/banners`
- **Фронтенд:** `Components/BannerSlider.jsx` → подключить в `Home.jsx`

### 4.2 Stories

- **Модели:** `Story`, `StorySlide` ✅ (media, порядок, активность)
- **Админка:** CRUD + reorder ✅
- **Бэкенд:** `User/StoryController` → `GET /api/stories`
- **Фронтенд:** `Components/Stories/StoryCircles.jsx`, `StoryViewer.jsx` → подключить в `Home.jsx`

---

## Этап 5 · Детальная карточка товара

> Зависимости: **Этапы 1, 3** (базовая инфраструктура + контент для «похожих»)
>
> **Ключевой этап** — открывает доступ к избранному, поиску, корзине, акциям.

### Что есть на бэкенде

Модель `Product` со связями: `brand`, `categories`, `certificates`, `attributeValues`, `warehouses` (pivot с qty), `barcodes`, `sizeChart`, `promotions`, `discounts`, `productSelections`, `media` (main/additional/video).

Сервисы: `PriceService` (цена для пользователя), `StockService` (остатки).

### Что делать

| # | Задача | Детали |
|---|--------|--------|
| 5.1 | Карточка товара | `User/ProductDetailController` → `GET /products/{slug}`. Страница `Pages/User/Products/Show.jsx` |
| 5.2 | Галерея | `ProductGallery.jsx` — главное фото + доп. фото + видео |
| 5.3 | Информация | `ProductInfo.jsx` — цена (с учётом скидки и валюты), наличие, кнопки |
| 5.4 | Вкладки | `ProductTabs.jsx` — описание, характеристики (из `attributeValues`), сертификаты, размерная сетка |
| 5.5 | Похожие товары | Виджет «Похожие» по категории/бренду |
| 5.6 | Каталог по категории | `GET /categories/{slug}` → `Products/Index.jsx` с `initialCategory` |
| 5.7 | Каталог по бренду | `GET /brands/{slug}` → `Products/Index.jsx` с `initialBrand` |
| 5.8 | Подборки товаров | `ProductSelection` ✅ → виджет подборки на главной и в карточке |

---

## Этап 6 · Переключатель валюты

> Зависимости: **Этап 1**

- **Модель:** `Currency` ✅ (code, name, exchange_rate, correction_factor)
- **Сервисы:** `CurrencyConversionService`, `UserCurrencyResolver` ✅
- **Бэкенд:** `User/CurrencyController` → `POST /api/currency/switch`
- **Фронтенд:** `Components/CurrencySwitcher.jsx` → встроить в хедер

---

## Этап 7 · Акции

> Зависимости: **Этапы 1, 5**

- **Модель:** `Promotion` ✅ (title, slug, description, media, dates, products M2M)
- **Админка:** CRUD + search ✅
- **Бэкенд:** `User/PromotionController`
  - `GET /promotions` — список
  - `GET /promotions/{slug}` — детальная
- **Фронтенд:** `Pages/User/Promotions/Index.jsx`, `Show.jsx`
- Также: `GET /products?promotion={slug}` — товары по акции (через `CatalogPanel`)

---

## Этап 8 · Избранное и Wishlist

> Зависимости: **Этапы 1, 5**

### 8.1 Избранное

- **Модель:** `Favorite` ✅ (user_id, product_id)
- **Админка:** CRUD ✅
- **Бэкенд:** `User/FavoriteController`
  - `GET /api/favorites/ids` — ID избранных для стора
  - `POST /api/favorites/{product}` — добавить
  - `DELETE /api/favorites/{product}` — удалить
  - `GET /favorites` — страница избранного
- **Фронтенд:** `Pages/User/Favorites.jsx`, кнопка ❤️ в карточках каталога

### 8.2 Список желаний (Wishlist)

- **Модель:** `WishlistItem` ✅ (user_id, product_id)
- **Админка:** CRUD ✅
- **Бэкенд:** `User/WishlistController` — аналогично избранному
- **Фронтенд:** кнопка Wishlist в карточке товара

---

## Этап 9 · Поиск товаров

> Зависимости: **Этапы 1, 5**

- **Инфраструктура:** `Product` использует `Laravel\Scout\Searchable` с `toSearchableArray` (name, brand, category, description, sku, code, barcodes, translit, layout) ✅
- **Бэкенд:** `User/SearchController`
  - `GET /search?q=` — страница результатов
  - `GET /api/search/suggestions?q=` — автодополнение (top-5)
- **Фронтенд:**
  - `Components/SearchDropdown.jsx` — поиск в хедере с dropdown
  - `Pages/User/Search/Index.jsx` — полная страница результатов

---

## Этап 10 · Корзина

> Зависимости: **Этапы 5, 6** (карточка товара + валюта)

- **Модели:** `Cart` ✅ (user_id, name), `CartItem` ✅ (cart_id, product_id, quantity)
- **Сервис:** `CartService` (getCartSummary, moveItems) ✅
- **Админка:** полный CRUD ✅
- **Бэкенд:** `User/CartController`
  - `GET /cart` — страница корзины
  - `POST /api/cart/items` — добавить товар
  - `PATCH /api/cart/items/{item}` — изменить кол-во
  - `DELETE /api/cart/items/{item}` — удалить
  - `GET /api/cart/summary` — сводка (кол-во, сумма)
- **Фронтенд:**
  - `Pages/User/Cart/Index.jsx` — полная страница
  - `Components/CartDropdown.jsx` — мини-корзина в хедере
  - `Components/QuantityControl.jsx`

---

## Этап 11 · Оформление заказа и история заказов

> Зависимости: **Этап 10** (корзина)

### 11.1 Checkout

- **Сервис:** `CheckoutService` ✅ (создание заказа с разбивкой in_stock/preorder)
- **Связи:** `Order` → `user`, `company`, `cart`, `items`; нужны `Company`, `DeliveryAddress`
- **Бэкенд:** `User/CheckoutController`
  - `GET /checkout` — страница оформления
  - `POST /checkout` — создание заказа
- **Фронтенд:** `Pages/User/Checkout/Index.jsx` — выбор компании, адреса, комментарий

### 11.2 История заказов

- **Модели:** `Order` ✅, `OrderItem` ✅, `OrderStatusHistory` ✅
- **Enums:** `OrderStatus` (PENDING и др.), `OrderType` (STANDARD, IN_STOCK, PREORDER)
- **Бэкенд:** `User/OrderController`
  - `GET /cabinet/orders` — список
  - `GET /cabinet/orders/{uuid}` — детали
- **Фронтенд:**
  - `Pages/User/Cabinet/Orders/Index.jsx` — список с фильтрами по статусу
  - `Pages/User/Cabinet/Orders/Show.jsx` — детали: позиции, статусы, сумма

---

## Этап 12 · Профиль пользователя

> Зависимости: **Этап 1**

- **Модель:** `User` ✅ (name, surname, patronymic, phone, country, city, email, region_id, currency_id, is_subscribed)
- **Бэкенд:** `User/ProfileController`
  - `GET /cabinet/profile` — страница профиля
  - `PUT /cabinet/profile` — обновление данных
  - `PUT /cabinet/password` — смена пароля
- **Фронтенд:**
  - `Pages/User/Cabinet/Profile.jsx` — форма редактирования
  - `Pages/User/Cabinet/ChangePassword.jsx`

---

## Этап 13 · Компании и банковские счета

> Зависимости: **Этапы 1, 12**

- **Модели:** `Company` ✅ (user_id, name, legal_name, tax_id, registration_number, tax_code, okpo_code, адреса, телефон, email), `CompanyBankAccount` ✅ (company_id, bank_name, bik, account, corr_account, is_primary)
- **Админка:** CRUD + search ✅
- **Бэкенд:** `User/CompanyController`
  - `GET /cabinet/companies` — список
  - `GET /cabinet/companies/create` — создание
  - `POST /cabinet/companies` — сохранение
  - `GET /cabinet/companies/{id}/edit` — редактирование
  - `PUT /cabinet/companies/{id}` — обновление
  - `DELETE /cabinet/companies/{id}` — удаление
- **Фронтенд:**
  - `Pages/User/Cabinet/Companies/Index.jsx`
  - `Pages/User/Cabinet/Companies/Upsert.jsx` (create + edit)

---

## Этап 14 · Адреса доставки

> Зависимости: **Этапы 12, 11** (профиль + используется в checkout)

- **Модель:** `DeliveryAddress` ✅ (user_id, name, city, address, postal_code, phone, is_default)
- **Админка:** CRUD + search ✅
- **Бэкенд:** `User/DeliveryAddressController`
  - CRUD внутри кабинета `/cabinet/addresses`
  - API для выбора при checkout
- **Фронтенд:**
  - `Pages/User/Cabinet/Addresses/Index.jsx`
  - `Pages/User/Cabinet/Addresses/Upsert.jsx`
  - `Components/AddressSelect.jsx` — выбор в checkout

---

## Этап 15 · Возвраты

> Зависимости: **Этапы 11, 13** (нужны заказы + компании)

- **Модели:** `ProductReturn` ✅ (user_id, company_id, status, comment), `ReturnItem` ✅ (return_id, product_id, order_id, quantity, reason)
- **Enums:** `ReturnStatus`, `ReturnReason` ✅
- **Админка:** CRUD + статусы ✅
- **Бэкенд:** `User/ReturnController`
  - `GET /cabinet/returns` — список
  - `GET /cabinet/returns/create` — создание
  - `POST /cabinet/returns` — сохранение
  - `GET /cabinet/returns/{id}` — детальная
- **Фронтенд:**
  - `Pages/User/Cabinet/Returns/Index.jsx`
  - `Pages/User/Cabinet/Returns/Create.jsx` — выбор заказа → товаров → причина
  - `Pages/User/Cabinet/Returns/Show.jsx`

---

## Этап 16 · Экспорт товаров

> Зависимости: **Этап 5** (нужны товары)

- **Модель:** `ProductExport` ✅ (user_id, name, format, filters, columns)
- **Сервис:** `ProductExportService` ✅
- **Админка:** CRUD + preview ✅
- **Бэкенд:** `User/ProductExportController`
  - `GET /cabinet/exports` — список
  - `GET /cabinet/exports/create` — создание
  - `POST /cabinet/exports` — сохранение
  - `POST /cabinet/exports/{id}/generate` — генерация файла
  - `GET /cabinet/exports/{id}/download` — скачивание
- **Фронтенд:**
  - `Pages/User/Cabinet/Exports/Index.jsx`
  - `Pages/User/Cabinet/Exports/Upsert.jsx`

---

## Этап 17 · Медиа-каталог

> Зависимости: **Этап 1**

- **Модель:** `Media` ✅ (spatie/laravel-medialibrary)
- **Админка:** index + destroy ✅
- **Бэкенд:** `User/MediaCatalogController`
  - `GET /media` — каталог
  - `GET /api/media` — API с фильтрацией
  - `GET /api/media/{id}/download` — скачивание
- **Фронтенд:** `Pages/User/Media/Index.jsx` — сетка с фильтрами и скачиванием

---

## Этап 18 · Сервисные маршруты

> Зависимости: **все предыдущие этапы**

| # | Маршрут | Детали |
|---|---------|--------|
| 18.1 | `GET /health` | Health check (ping, detailed) |
| 18.2 | `GET /sitemap.xml` | XML sitemap (products, categories, brands, news, articles, pages) |
| 18.3 | `GET /export/{hash}` | Публичная ссылка на экспорт (уже работает) |

---

## Карта зависимостей

```
Этап 1: Инфраструктура (UI, тосты, сторы, SEO)
 │
 ├─── Этап 2: FAQ + CMS
 ├─── Этап 3: Новости + Статьи
 ├─── Этап 4: Баннеры + Stories
 ├─── Этап 6: Валюта
 ├─── Этап 12: Профиль
 └─── Этап 17: Медиа-каталог
         │
         ▼
Этап 5: Карточка товара + Каталог по категории/бренду
 │
 ├─── Этап 7: Акции
 ├─── Этап 8: Избранное + Wishlist
 ├─── Этап 9: Поиск
 └─── Этап 16: Экспорт
         │
         ▼
Этап 10: Корзина ──── (+ Этап 6)
         │
         ▼
Этап 11: Checkout + Заказы
         │
 ├─── Этап 13: Компании ──── (+ Этап 12)
 ├─── Этап 14: Адреса ─────── (+ Этап 12)
 └─── Этап 15: Возвраты ──── (+ Этап 13)
         │
         ▼
Этап 18: Сервисные маршруты
```

---

## Параллельная разработка

```
┌──────────────────────────────────────────────┐
│ После Этапа 1 (параллельно):                │
│   • Этап 2 (FAQ, CMS)                       │
│   • Этап 3 (Новости, Статьи)                │
│   • Этап 4 (Баннеры, Stories)                │
│   • Этап 6 (Валюта)                          │
│   • Этап 12 (Профиль)                        │
│   • Этап 17 (Медиа)                          │
├──────────────────────────────────────────────┤
│ После Этапа 5 (параллельно):                │
│   • Этап 7 (Акции)                           │
│   • Этап 8 (Избранное)                       │
│   • Этап 9 (Поиск)                           │
│   • Этап 16 (Экспорт)                        │
├──────────────────────────────────────────────┤
│ После Этапа 11 + 12 (параллельно):          │
│   • Этап 13 (Компании)                       │
│   • Этап 14 (Адреса)                         │
└──────────────────────────────────────────────┘
```

---

## Конвенции

- **Контроллеры** → `app/Http/Controllers/User/`
- **Страницы** → `resources/js/Pages/User/`
- **Компоненты** → `resources/js/Components/`
- **Маршруты** → `routes/user.php`
- **Сервисы** → `app/Services/` (уже есть: Cart, Order, Pricing, Currency, Stock, ProductExport)
