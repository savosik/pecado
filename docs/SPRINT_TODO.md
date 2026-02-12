# Спринт-план реализации пользовательской части

> На основе [TECHNICAL_SPECIFICATION.md](./TECHNICAL_SPECIFICATION.md)
> Дата создания: 2026-02-12

---

## Текущее состояние проекта

**Уже реализовано:**
- `Pages/User`: Home, Products/Index, CatalogPanel, UserLayout, UserHeader, UserFooter, Cabinet/Dashboard, Cabinet/CabinetLayout
- `Controllers/User`: HomeController, CatalogController, ProductController, CabinetController, PasswordResetController, SocialAuthController
- `components/ui`: provider, toaster, button, checkbox, color-mode, field, select, switch, tooltip
- Тема `pecado` (dark red / silver)
- Авторизация: Login, Register, ForgotPassword, ResetPassword

**Не реализовано:**
- Директории `components/common`, `components/product`, `components/cart`, `components/search`, `components/stories`, `components/banner`
- Директории `hooks/`, `stores/`, `utils/`
- Все страницы кабинета кроме Dashboard
- Контент-страницы (FAQ, Новости, Статьи, CMS-страницы)
- Корзина, Избранное, Поиск, Checkout, Заказы, и т.д.

---

## Спринт 1 — Базовая инфраструктура (Слой 1)

> **Цель:** Общие компоненты, утилиты, хуки и сторы — фундамент для всех следующих спринтов.
> **Ветка:** `feature/layer-01-infrastructure`

### 1.1 Утилиты

- [x] Создать директорию `resources/js/utils/`
- [x] `utils/formatPrice.js` — `formatPrice(amount, currency?)`, разделитель тысяч, знак валюты
- [x] `utils/formatDate.js` — `formatDate(date, format?)`, русская локаль
- [x] `utils/api.js` — обёртки `apiGet`, `apiPost`, `apiPut`, `apiDelete` над `window.axios` с обработкой ошибок
- [x] `utils/toast.js` — императивные тосты `toastSuccess`, `toastError`, `toastInfo`

### 1.2 Хуки

- [x] Создать директорию `resources/js/hooks/`
- [x] `hooks/useToast.js` — хук для Chakra Toaster → `{ success, error, info }`
- [x] `hooks/useSearch.js` — debounced запрос к `/api/search/suggestions`, управление dropdown
- [x] `hooks/useScrollDirection.js` — определение направления скролла (для авто-скрытия хедера)
- [x] `hooks/usePagination.js` — обёртка над Laravel-пагинацией

### 1.3 Общие компоненты

- [x] Создать директорию `resources/js/components/common/`
- [x] `SeoHead.jsx` — `<Head>` от Inertia с OG, Twitter Card, JSON-LD structured data
- [x] `Breadcrumbs.jsx` — Chakra Breadcrumb, последний элемент без ссылки, генерация `BreadcrumbList` JSON-LD
- [x] `PageHeader.jsx` — заголовок страницы с подзаголовком и кнопками действий
- [x] `EmptyState.jsx` — пустое состояние с иконкой, заголовком, описанием и кнопкой действия
- [x] `NotFound.jsx` — страница 404, подключить как fallback в Inertia
- [x] `ScrollToTop.jsx` — кнопка прокрутки вверх, появление при `scrollY > 300px`
- [x] `Pagination.jsx` — обёртка над Laravel пагинацией (links, onPageChange)
- [x] `IconWithCounter.jsx` — иконка с badge-счётчиком (для корзины, избранного)
- [x] `QuantityControl.jsx` — input с +/- кнопками, min/max ограничения

### 1.4 Zustand-сторы

- [x] Установить пакет `zustand`
- [x] Создать директорию `resources/js/stores/`
- [x] `stores/useFavoritesStore.js` — `loadOnce()`, `isFavorite(id)`, `toggle(id)` с optimistic update
- [x] `stores/useCartStore.js` — `init()`, `getQuantity(id)`, `getTotalQuantity()`, `setQuantity(id, qty)`, `clear()`, debounced API sync
- [x] `stores/useCurrencyStore.js` — `current`, `switch(code)`, `router.reload()` после переключения
- [x] Все сторы — проверка `auth.user` перед инициализацией (гости не инициализируют)

### 1.5 Бэкенд API для сторов

- [x] `app/Http/Controllers/User/FavoriteController.php` — `ids()`, `store()`, `destroy()`
- [x] `app/Http/Controllers/User/CartController.php` — `summary()`, `addItem()`, `updateItem()`, `removeItem()`
- [x] `app/Http/Controllers/User/CurrencyController.php` — `switch()`
- [x] Добавить API-маршруты в `routes/user.php`:
  - [x] `GET /api/favorites/ids` (auth)
  - [x] `POST /api/favorites/{product}` (auth)
  - [x] `DELETE /api/favorites/{product}` (auth)
  - [x] `GET /api/cart/summary` (auth)
  - [x] `POST /api/cart/items` (auth)
  - [x] `PATCH /api/cart/items/{item}` (auth)
  - [x] `DELETE /api/cart/items/{item}` (auth)
  - [x] `POST /api/currency/switch`

### 1.6 Обработка ошибок

- [x] Настроить axios interceptor в `bootstrap.js` (401 → login, 403 → toast, 500 → toast)
- [x] Создать React Error Boundary с fallback UI «Что-то пошло не так»
- [x] Создать fallback-изображение `/public/images/no-image.svg`

---

## Спринт 2 — Контент и статические страницы (Слой 2)

> **Цель:** Все текстовые публичные страницы (FAQ, CMS, Новости, Статьи).
> **Зависимости:** Спринт 1 (Breadcrumbs, SeoHead, Pagination, PageHeader)
> **Ветка:** `feature/layer-02-content-pages`

### 2.1 FAQ

- [x] `app/Http/Controllers/User/FaqController.php` — `index()`, данные: faqs с группировкой по category
- [x] `Pages/User/Faq/Index.jsx` — Chakra Accordion с категориями
- [x] Маршрут `GET /faq`
- [x] SEO: title, description, breadcrumbs
- [x] Empty state: если FAQ пуст

### 2.2 CMS-страницы

- [x] `app/Http/Controllers/User/PageController.php` — `show($slug)`, проверка `is_published`, abort(404)
- [x] `Pages/User/Pages/Show.jsx` — рендер HTML-контента через `dangerouslySetInnerHTML` + Chakra prose стили
- [x] Маршрут `GET /pages/{slug}`
- [x] SEO + breadcrumbs
- [x] HTML-санитизация на бэкенде

### 2.3 Новости

- [x] `app/Http/Controllers/User/NewsController.php` — `index()` пагинация 12/стр., `show($slug)`
- [x] `components/common/ContentCard.jsx` — переиспользуемая карточка (изображение, дата, заголовок, excerpt, url, tags)
- [x] `Pages/User/News/Index.jsx` — Grid карточек ContentCard
- [x] `Pages/User/News/Show.jsx` — детальная: заголовок, дата, изображение, контент
- [x] Маршруты `GET /news`, `GET /news/{slug}`
- [x] SEO + breadcrumbs + structured data (Article)
- [x] Пагинация

### 2.4 Статьи

- [x] `app/Http/Controllers/User/ArticleController.php` — `index()`, `show($slug)`
- [x] `Pages/User/Articles/Index.jsx` — аналогично News, переиспользует `ContentCard`
- [x] `Pages/User/Articles/Show.jsx` — детальная страница
- [x] Маршруты `GET /articles`, `GET /articles/{slug}`
- [x] SEO + breadcrumbs

### 2.5 Обновление навигации

- [x] Добавить в `UserHeader.jsx` ссылки: Новости, Статьи, FAQ
- [x] Добавить ссылки в `UserFooter.jsx`

---

## Спринт 3 — Визуальные элементы главной (Слой 3)

> **Цель:** Баннеры, Stories, подборки — наполнение Home.jsx.
> **Зависимости:** Спринт 1
> **Ветка:** `feature/layer-03-home-visuals`

### 3.1 Баннеры

- [ ] `app/Http/Controllers/User/BannerController.php` — `GET /api/banners`, активные + sort_order
- [ ] Создать директорию `resources/js/components/banner/`
- [ ] `components/banner/BannerSlider.jsx` — карусель: изображение, ссылка, overlay-текст
- [ ] API-маршрут `GET /api/banners`
- [ ] Серверное кеширование баннеров (TTL 10 мин)

### 3.2 Stories

- [ ] `app/Http/Controllers/User/StoryController.php` — `GET /api/stories`, активные со slides
- [ ] Создать директорию `resources/js/components/stories/`
- [ ] `components/stories/StoryCircles.jsx` — горизонтальная прокрутка круглых превью
- [ ] `components/stories/StoryViewer.jsx` — полноэкранный просмотр, slide-by-slide, auto-progress
- [ ] Lazy loading для `StoryViewer` (heavy component)
- [ ] API-маршрут `GET /api/stories`
- [ ] Серверное кеширование (TTL 10 мин)

### 3.3 Подборки товаров

- [ ] `app/Http/Controllers/User/ProductSelectionController.php` — данные для главной
- [ ] `components/product/ProductSelectionCarousel.jsx` — горизонтальная карусель `ProductCard`
- [ ] Для этого нужен `ProductCard` (часть Слоя 4 → делаем базовую версию)

### 3.4 Обновление Home.jsx

- [ ] Интегрировать `StoryCircles`, `BannerSlider`, подборки товаров
- [ ] Обновить `HomeController` — передавать banners, stories, productSelections
- [ ] SEO + structured data для главной

---

## Спринт 4 — Товары: каталог и карточка (Слой 4)

> **Цель:** Полноценный каталог с фильтрацией, карточка товара — ключевой слой.
> **Зависимости:** Спринты 1-2
> **Ветка:** `feature/layer-04-product-catalog`

### 4.1 Компоненты товара

- [ ] Создать директорию `resources/js/components/product/` (если ещё нет)
- [ ] `ProductCard.jsx` — фото, название, бренд, значки new/bestseller; цена и кнопки скрыты для гостей
- [ ] `ProductGrid.jsx` — responsive сетка (1→2→3→4 колонки), skeleton при загрузке
- [ ] `ProductGallery.jsx` — главное фото + миниатюры + видео, Lightbox при клике
- [ ] `ProductInfo.jsx` — цена (старая/новая), наличие, бренд, SKU, кнопки «В корзину» / «❤️»; цена и остатки скрыты для гостей
- [ ] `ProductTabs.jsx` — вкладки: Описание, Характеристики, Сертификаты, Размерная сетка
- [ ] `ProductVariants.jsx` — варианты товара (если применимо)

### 4.2 Карточка товара (страница)

- [ ] `app/Http/Controllers/User/ProductDetailController.php` — `show($slug)`
  - [ ] Загрузка: product с relations (brand, categories, media, attributes, certificates, promotions)
  - [ ] Для авторизованных: `userPrice` (PriceService), `stock` (StockService), `warehouses`
  - [ ] `relatedProducts` для блока «Похожие товары»
  - [ ] SEO: structured data (Product schema), breadcrumbs
- [ ] `Pages/User/Products/Show.jsx` — сборка из ProductGallery + ProductInfo + ProductTabs + ProductGrid (related)
- [ ] Маршрут `GET /products/{slug}`

### 4.3 Каталог по категории

- [ ] `app/Http/Controllers/User/CategoryController.php` — `show($slug)`, товары с пагинацией, фильтрами, сортировкой
- [ ] Маршрут `GET /categories/{slug}`
- [ ] Передача данных: initialCategory, products, filters, seo, breadcrumbs

### 4.4 Каталог по бренду

- [ ] `app/Http/Controllers/User/BrandController.php` — `show($slug)`, аналогично категории
- [ ] Маршрут `GET /brands/{slug}`

### 4.5 Расширенная фильтрация каталога

- [ ] Обновить `CatalogPanel.jsx`:
  - [ ] Боковая панель фильтров: категория, бренд, цена (range), атрибуты, теги
  - [ ] Сортировка: цена ↑↓, название, новинки, бестселлеры
  - [ ] URL-параметры: `?category=&brand=&min_price=&max_price=&sort=&page=`
  - [ ] Пагинация (Pagination из Слоя 1)
  - [ ] Drawer для фильтров на мобильных
- [ ] `components/common/FilterBlock.jsx` — checkbox / range / radio, title, options, onChange
- [ ] `components/common/Tag.jsx` — отображение тега
- [ ] `components/common/TagList.jsx` — список тегов
- [ ] `components/common/TagFilter.jsx` — фильтрация по тегам
- [ ] Обновить `Products/Index.jsx` — интеграция с CatalogPanel и ProductGrid

---

## Спринт 5 — Валюта, Акции, Избранное, Поиск (Слои 5-8)

> **Цель:** Функциональность для авторизованных: валюта, избранное; публичные: акции, поиск.
> **Ветки:** `feature/layer-05-currency`, `feature/layer-06-promotions`, `feature/layer-07-favorites`, `feature/layer-08-search`

### 5.1 Переключатель валюты (Слой 5)

- [ ] `app/Http/Controllers/User/CurrencyController.php` — `GET /api/currencies` (список), `POST /api/currency/switch`
- [ ] `components/common/CurrencySwitcher.jsx` — dropdown в хедере: флаг + код валюты; скрыт для гостей
- [ ] Логика переключения: POST → сохранение currency_id → стор → `router.reload()`
- [ ] Интеграция в `UserHeader.jsx`

### 5.2 Акции (Слой 6)

- [ ] `app/Http/Controllers/User/PromotionController.php` — `index()` пагинация, `show($slug)`
- [ ] `Pages/User/Promotions/Index.jsx` — Grid карточек акций: изображение, название, даты
- [ ] `Pages/User/Promotions/Show.jsx` — детальная: описание + ProductGrid товаров акции
- [ ] Компонент `PromotionCard` (или variant ContentCard)
- [ ] Маршруты `GET /promotions`, `GET /promotions/{slug}`
- [ ] SEO + breadcrumbs
- [ ] Добавить ссылку «Акции» в навигацию хедера

### 5.3 Избранное (Слой 7)

- [ ] `app/Http/Controllers/User/FavoriteController.php` — `index()` (страница со списком)
- [ ] `Pages/User/Favorites.jsx` — `ProductGrid` с товарами из избранного
- [ ] Маршрут `GET /favorites` (auth)
- [ ] Интеграция кнопки ❤️ в `ProductCard` → `useFavoritesStore.toggle(id)`
- [ ] `IconWithCounter` в хедере (count из стора)
- [ ] Empty state: «У вас пока нет избранных товаров» + «Перейти в каталог»

### 5.4 Wishlist (Слой 7)

- [ ] `app/Http/Controllers/User/WishlistController.php` — аналогично FavoriteController
- [ ] `stores/useWishlistStore.js` — отдельный стор
- [ ] `Pages/User/Wishlist.jsx` — страница
- [ ] Маршруты + API
- [ ] Empty state

### 5.5 Поиск (Слой 8)

- [ ] `app/Http/Controllers/User/SearchController.php`:
  - [ ] `index()` — страница полного поиска с пагинацией
  - [ ] `suggestions()` — `GET /api/search/suggestions?q=` → топ-5 результатов (цена только для auth)
- [ ] Создать директорию `resources/js/components/search/`
- [ ] `components/search/SearchDropdown.jsx` — Input в хедере + dropdown с suggestions
- [ ] `hooks/useSearch.js` — debounce 300ms, запрос suggestions, Enter → `/search?q=`
- [ ] `Pages/User/Search/Index.jsx` — `ProductGrid` + фильтры + пагинация
- [ ] Маршруты `GET /search`, `GET /api/search/suggestions`
- [ ] SEO для страницы поиска
- [ ] Empty state: «По запросу «...» ничего не найдено»
- [ ] Интеграция SearchDropdown в `UserHeader.jsx`

---

## Спринт 6 — Корзина и Checkout (Слои 9-10)

> **Цель:** Полный флоу покупки: корзина → checkout → заказ.
> **Зависимости:** Спринты 4-5
> **Ветки:** `feature/layer-09-cart`, `feature/layer-10-checkout-orders`

### 6.1 Корзина (Слой 9)

- [ ] Создать директорию `resources/js/components/cart/`
- [ ] `components/cart/CartItemRow.jsx` — строка: фото, название, цена, QuantityControl, удалить
- [ ] `components/cart/CartDropdown.jsx` — мини-корзина в хедере (popup), скрыта для гостей
- [ ] `components/cart/CartSwitcher.jsx` — (если требуется)
- [ ] `Pages/User/Cart/Index.jsx` — полная таблица товаров, итоги, кнопка «Оформить заказ»
- [ ] Маршрут `GET /cart` (auth)
- [ ] Интеграция `CartDropdown` и `IconWithCounter` в `UserHeader.jsx`
- [ ] Skeleton state при загрузке
- [ ] Empty state: «Ваша корзина пуста» + «Перейти в каталог»

### 6.2 Checkout (Слой 10)

- [ ] `app/Http/Controllers/User/CheckoutController.php` — `index()`, `store()`
- [ ] `Pages/User/Checkout/Index.jsx` — форма: компания, адрес, комментарий, итоги + `useForm`
- [ ] Маршрут `GET /checkout`, `POST /checkout` (auth)
- [ ] Данные: cart (items + summary), companies, addresses, seo
- [ ] Логика: выбор компании → выбор адреса → POST → redirect `/cabinet/orders/{uuid}`
- [ ] Валидация через FormRequest
- [ ] Компонент `components/common/AddressSelect.jsx` — dropdown выбора адреса

### 6.3 Список заказов

- [ ] `app/Http/Controllers/User/OrderController.php` — `index()`, `show($uuid)`
- [ ] `Pages/User/Cabinet/Orders/Index.jsx` — таблица: №, дата, статус, сумма, действия
  - [ ] Фильтры по статусу (OrderStatus enum) и дате
  - [ ] Пагинация
  - [ ] Responsive: карточки на мобильных
- [ ] Маршруты `GET /cabinet/orders`, `GET /cabinet/orders/{uuid}`
- [ ] Empty state: «У вас пока нет заказов» + «Перейти в каталог»

### 6.4 Детали заказа

- [ ] `Pages/User/Cabinet/Orders/Show.jsx`:
  - [ ] Позиции заказа (товар × кол-во = сумма)
  - [ ] Общий итог
  - [ ] Компания + адрес доставки
  - [ ] История статусов (timeline)
  - [ ] SEO: breadcrumbs

---

## Спринт 7 — Профиль и Кабинет (Слои 11-15)

> **Цель:** Полный кабинет пользователя: профиль, компании, адреса, возвраты, экспорт.
> **Зависимости:** Спринты 1, 4, 6
> **Ветки:** `feature/layer-11-profile` ... `feature/layer-15-exports`

### 7.1 Навигация кабинета

- [ ] Обновить `CabinetSidebar.jsx` (или `CabinetLayout.jsx`):
  - [ ] Пункты: Дашборд, Заказы, Избранное, Корзина, Профиль, Компании, Адреса, Возвраты, Экспорт, Выйти
  - [ ] Активный пункт — подсветка
  - [ ] Мобильный: скрыт, выезжает по кнопке

### 7.2 Профиль (Слой 11)

- [ ] `app/Http/Controllers/User/ProfileController.php` — `show()`, `update()`, `updatePassword()`
- [ ] `Pages/User/Cabinet/Profile.jsx` — форма: имя, фамилия, отчество, email, телефон, город, страна; `useForm`
- [ ] `Pages/User/Cabinet/ChangePassword.jsx` — текущий пароль, новый × 2; `useForm`
- [ ] FormRequest для валидации (ProfileUpdateRequest, PasswordUpdateRequest)
- [ ] Маршруты `GET /cabinet/profile`, `PUT /cabinet/profile`, `PUT /cabinet/password`
- [ ] Toast при успешном обновлении

### 7.3 Компании (Слой 12)

- [ ] `app/Http/Controllers/User/CompanyController.php` — полный CRUD (index, create, store, edit, update, destroy)
- [ ] `Pages/User/Cabinet/Companies/Index.jsx` — таблица: название, ИНН, действия (редактировать, удалить)
- [ ] `Pages/User/Cabinet/Companies/Upsert.jsx` — форма:
  - [ ] Название, юр. название, ИНН, ОГРН, КПП, ОКПО
  - [ ] Адреса, контакты
  - [ ] Банковские реквизиты (inline nested CRUD: bank_name, bik, account, corr_account, is_primary)
- [ ] `app/Policies/CompanyPolicy.php` — scope `user_id`
- [ ] FormRequest
- [ ] Маршруты `GET/POST /cabinet/companies`, `GET/PUT/DELETE /cabinet/companies/{company}`
- [ ] Empty state: «Вы ещё не добавили компанию» + «Добавить компанию»

### 7.4 Адреса доставки (Слой 13)

- [ ] `app/Http/Controllers/User/DeliveryAddressController.php` — CRUD
- [ ] `Pages/User/Cabinet/Addresses/Index.jsx` — список адресов
- [ ] `Pages/User/Cabinet/Addresses/Upsert.jsx` — форма создания/редактирования
- [ ] `app/Policies/DeliveryAddressPolicy.php` — scope `user_id`
- [ ] FormRequest
- [ ] Маршруты `GET/POST /cabinet/addresses`, `GET/PUT/DELETE /cabinet/addresses/{address}`

### 7.5 Возвраты (Слой 14)

- [ ] `app/Http/Controllers/User/ReturnController.php` — index, create, store, show
- [ ] `Pages/User/Cabinet/Returns/Index.jsx` — таблица возвратов
- [ ] `Pages/User/Cabinet/Returns/Create.jsx` — Wizard:
  - [ ] Шаг 1: Выбор заказа (из списка заказов пользователя)
  - [ ] Шаг 2: Выбор позиций и кол-ва (из OrderItems)
  - [ ] Шаг 3: Причина (ReturnReason enum) + комментарий
  - [ ] Подтверждение → POST
- [ ] `Pages/User/Cabinet/Returns/Show.jsx` — детали возврата
- [ ] `app/Policies/ReturnPolicy.php` — scope `user_id`
- [ ] Enums: `ReturnStatus`, `ReturnReason`
- [ ] FormRequest
- [ ] Маршруты `GET /cabinet/returns`, `GET /cabinet/returns/create`, `POST /cabinet/returns`, `GET /cabinet/returns/{return}`
- [ ] Empty state: «У вас нет оформленных возвратов»

### 7.6 Экспорт товаров (Слой 15)

- [ ] `app/Http/Controllers/User/ProductExportController.php` — CRUD + generate + download
- [ ] `Pages/User/Cabinet/Exports/Index.jsx` — список выгрузок со статусами
- [ ] `Pages/User/Cabinet/Exports/Upsert.jsx` — конструктор: фильтры, колонки, формат (изучить админку для аналогии)
- [ ] `app/Policies/ProductExportPolicy.php` — scope `user_id`
- [ ] Backend: использовать `ProductExportService` (уже реализован)
- [ ] Маршруты `GET/POST /cabinet/product-exports`, `GET/PUT/DELETE /cabinet/product-exports/{export}`, `POST .../generate`, `GET .../download`

---

## Спринт 8 — Финальные функции и полировка (Слои 16-17)

> **Цель:** Медиа-каталог, сервисные маршруты, обновление хедера, полировка.
> **Ветки:** `feature/layer-16-media`, `feature/layer-17-services`

### 8.1 Медиа-каталог (Слой 16)

- [ ] `app/Http/Controllers/User/MediaCatalogController.php` — `index()`, API: список, скачивание
- [ ] `Pages/User/Media/Index.jsx` — сетка файлов с фильтрами (тип: image/video/document, имя) и скачиванием
- [ ] Маршрут `GET /media`

### 8.2 Сервисные маршруты (Слой 17)

- [ ] `app/Http/Controllers/User/SitemapController.php` — XML sitemap: товары, категории, бренды, новости, статьи, страницы
- [ ] `app/Http/Controllers/User/HealthController.php` — `{ status: ok, timestamp }`
- [ ] Маршруты `GET /sitemap.xml`, `GET /health`

### 8.3 Финальное обновление UserHeader.jsx

- [ ] Навигация: Каталог, Акции, Новости, Статьи, FAQ
- [ ] Поиск: `SearchDropdown` (Слой 8)
- [ ] Валюта: `CurrencySwitcher` (Слой 5) — скрыт для гостей
- [ ] Избранное: `IconWithCounter` + store (Слой 7) — скрыт для гостей
- [ ] Корзина: `CartDropdown` (Слой 9) — скрыт для гостей
- [ ] Пользователь: `UserMenu` (профиль / вход)
- [ ] Бургер-меню на мобильных

### 8.4 Общая полировка

- [ ] Responsive-дизайн: проверка на всех breakpoints (mobile → desktop)
- [ ] Loading states: skeleton на каждой странице с данными
- [ ] Empty states: осмысленные сообщения + действия для всех списков
- [ ] Error Boundary: fallback UI на критических компонентах
- [ ] Accessibility: alt-теги, aria-labels, keyboard navigation
- [ ] Удалить все `console.log` / `console.error`
- [ ] Проверить N+1 запросы: `preventLazyLoading()` в AppServiceProvider
- [ ] Rate limiting: `throttle:60,1` на API-эндпоинты (поиск, избранное, корзина)
- [ ] Серверное кеширование: категории (1 час), FAQ (1 час), баннеры (10 мин), stories (10 мин), курсы валют (1 час)
- [ ] Проверить ЧПУ на всех публичных URL

---

## Чеклист качества (для каждого спринта)

Перед закрытием спринта убедиться:

- [ ] Компоненты переиспользуемы
- [ ] Props описаны через JSDoc
- [ ] Все строки интерфейса на русском
- [ ] SEO: title, description, OG, structured data
- [ ] Breadcrumbs на каждой внутренней странице
- [ ] Responsive: mobile, tablet, desktop
- [ ] Empty states с действием
- [ ] Loading / skeleton states
- [ ] Error handling → toast + fallback
- [ ] Контроллеры используют FormRequest
- [ ] API защищён middleware `auth` где нужно
- [ ] Гостевые ограничения: цены, остатки, избранное, корзина, валюта скрыты
- [ ] Изображения: lazy, fallback, конверсии
- [ ] N+1 запросы: все `with()` прописаны
- [ ] Policy проверяет принадлежность ресурса
- [ ] Кеширование статических данных
- [ ] URL-ы с slug вместо ID
