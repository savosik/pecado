# TODO: Пошаговый план создания админ-панели

Данный документ содержит подробный пошаговый план разработки админ-панели на базе Laravel, Inertia.js и Chakra UI v3.

---

## Фаза 1: Базовая инфраструктура

### 1.1. Маршрутизация и аутентификация
- [x] Создать файл маршрутов `routes/admin.php`
- [x] Зарегистрировать маршруты в `RouteServiceProvider`
- [x] Настроить middleware `auth` и `admin` для защиты роутов
- [x] Создать middleware `EnsureUserIsAdmin` (проверка `is_admin`)
- [x] Настроить редирект неавторизованных пользователей

### 1.2. Базовый контроллер
- [x] Создать `App\Http\Controllers\Admin\AdminController` (базовый класс)
- [x] Создать `App\Http\Controllers\Admin\DashboardController`
- [x] Реализовать метод `index()` для главной страницы

### 1.3. Inertia.js настройка для админки
- [x] Создать middleware `HandleAdminInertiaRequests` (share общих данных)
- [x] Добавить передачу `auth.user`, `menu`, `flash` сообщений
- [x] Настроить отдельную точку входа для админки (опционально)

---

## Фаза 2: Базовый Layout (App Shell)

### 2.1. Структура компонентов
- [x] Создать директорию `resources/js/Admin/`
- [x] Создать директорию `resources/js/Admin/Layouts/`
- [x] Создать директорию `resources/js/Admin/Components/`
- [x] Создать директорию `resources/js/Admin/Pages/`

### 2.2. Главный Layout
- [x] Создать `AdminLayout.jsx` — основной shell для всех страниц
- [x] Реализовать адаптивную структуру (Sidebar + Content Area)
- [x] Добавить поддержку темной/светлой темы

### 2.3. Sidebar (Боковое меню)
- [x] Создать `Sidebar.jsx` с Accordion-навигацией
- [x] Создать `menuConfig.ts` — конфигурация меню из документации
- [x] Реализовать выделение активного пункта меню
- [x] Добавить иконки из `react-icons/lu`
- [x] Реализовать collapsed-режим (свёрнутое меню)
- [x] Создать `MobileSidebar.jsx` с использованием Drawer

### 2.4. Header (Верхняя панель)
- [x] Создать `Header.jsx`
- [x] Добавить кнопку переключения темы (ColorModeButton)
- [x] Добавить Breadcrumbs для навигации
- [x] Добавить UserMenu (dropdown с профилем, выход)
- [x] Добавить кнопку toggle для мобильного меню

### 2.5. Dashboard страница
- [x] Создать `resources/js/Admin/Pages/Dashboard.jsx`
- [x] Добавить карточки с базовой статистикой (заглушки)
- [x] Подключить `AdminLayout`

---

## Фаза 3: Переиспользуемые компоненты

### 3.1. DataTable (Таблица данных)
- [x] Создать `DataTable.jsx` — универсальный компонент таблицы
- [x] Реализовать конфигурацию колонок через props
- [x] Добавить поддержку пагинации (интеграция с Laravel Paginator)
- [x] Добавить сортировку по колонкам
- [x] Добавить поиск/фильтрацию
- [x] Добавить Skeleton-загрузку
- [x] Добавить выбор количества записей на странице
- [x] Добавить массовые действия (удаление, экспорт)

### 3.2. Формы
- [x] Создать `FormField.jsx` — обёртка для полей с label и ошибками
- [x] Создать `FormActions.jsx` — кнопки сохранения/отмены
- [x] Интегрировать с `useForm` от Inertia.js
- [x] Добавить компонент `ImageUploader.jsx` для загрузки изображений
- [/] Добавить компонент `RichTextEditor.jsx` (если требуется) — отложено
- [x] Добавить компонент `SelectRelation.jsx` для связей

### 3.3. Модальные окна и уведомления
- [x] Создать `ConfirmDialog.jsx` — диалог подтверждения удаления
- [/] Настроить `Toaster` для уведомлений (успех, ошибка) — уже настроен
- [x] Создать `PageHeader.jsx` — заголовок страницы с кнопкой "Создать"

### 3.4. Фильтры
- [x] Создать `FilterPanel.jsx` — панель фильтров для таблиц
- [x] Создать `SearchInput.jsx` — поле поиска с debounce
- [/] Создать `DateRangePicker.jsx` — выбор диапазона дат — отложено

---

## Фаза 4: CRUD-контроллеры (Backend)

### 4.1. Каталог

#### Товары (Products)
- [x] Создать `Admin\ProductController` с методами CRUD
- [x] Добавить маршруты `admin/products`
- [x] Реализовать пагинацию, поиск, сортировку
- [x] Добавить загрузку связей (brand, categories, attributes)

#### Категории (Categories)
- [x] Создать `Admin\CategoryController`
- [x] Добавить маршруты `admin/categories`
- [x] Реализовать иерархическое отображение (parent_id)

#### Бренды (Brands)
- [x] Создать `Admin\BrandController`
- [x] Добавить маршруты `admin/brands`
- [x] Реализовать иерархию брендов (parent_id)

#### Модели товаров (ProductModels)
- [x] Создать `Admin\ProductModelController`
- [x] Добавить маршруты `admin/product-models`

#### Атрибуты (Attributes)
- [x] Создать `Admin\AttributeController`
- [x] Добавить маршруты `admin/attributes`
- [x] Включить управление значениями атрибутов (AttributeValues)

#### Размерные сетки (SizeCharts)
- [x] Создать `Admin\SizeChartController`
- [x] Добавить маршруты `admin/size-charts`

#### Штрихкоды (ProductBarcodes)
- [x] Создать `Admin\ProductBarcodeController`
- [x] Добавить маршруты `admin/product-barcodes`

#### Сертификаты (Certificates)
- [x] Создать `Admin\CertificateController`
- [x] Добавить маршруты `admin/certificates`

#### Сегменты (Segments)
- [x] Создать `Admin\SegmentController`
- [x] Добавить маршруты `admin/segments`

---

### 4.2. Склады и логистика

#### Склады (Warehouses)
- [x] Создать `Admin\WarehouseController`
- [x] Добавить маршруты `admin/warehouses`

#### Регионы (Regions)
- [x] Создать `Admin\RegionController`
- [x] Добавить маршруты `admin/regions`
- [x] Реализовать привязку регионов к складам

---

### 4.3. Продажи

#### Заказы (Orders)
- [x] Создать `Admin\OrderController`
- [x] Добавить маршруты `admin/orders`
- [x] Реализовать просмотр деталей заказа (order_items)
- [x] Добавить смену статуса заказа

#### Корзины (Carts)
- [x] Создать `Admin\CartController`
- [x] Добавить маршруты `admin/carts`
- [x] Реализовать просмотр cart_items

#### Возвраты (Returns)
- [x] Создать `Admin\ReturnController`
- [x] Добавить маршруты `admin/returns`
- [x] Реализовать просмотр return_items

#### Избранное (Favorites)
- [x] Создать `Admin\FavoriteController`
- [x] Добавить маршруты `admin/favorites`

#### Список желаний (Wishlist)
- [x] Создать `Admin\WishlistController`
- [x] Добавить маршруты `admin/wishlist`

---

### 4.4. Маркетинг

#### Акции (Promotions)
- [x] Создать `Admin\PromotionController`
- [x] Добавить маршруты `admin/promotions`
- [x] Реализовать привязку товаров к акциям

#### Скидки (Discounts)
- [x] Создать `Admin\DiscountController`
- [x] Добавить маршруты `admin/discounts`
- [x] Реализовать привязку к товарам и пользователям

#### Подборки товаров (ProductSelections)
- [x] Создать `Admin\ProductSelectionController`
- [x] Добавить маршруты `admin/product-selections`

---

### 4.5. Пользователи

#### Пользователи (Users)
- [x] Создать `Admin\UserController`
- [x] Добавить маршруты `admin/users`
- [x] Реализовать фильтрацию по региону, is_admin, status

#### Компании (Companies)
- [x] Создать `Admin\CompanyController`
- [x] Добавить маршруты `admin/companies`

#### Банковские счета (CompanyBankAccounts)
- [x] Создать `Admin\CompanyBankAccountController`
- [x] Добавить маршруты `admin/company-bank-accounts`

#### Адреса доставки (DeliveryAddresses)
- [x] Создать `Admin\DeliveryAddressController`
- [x] Добавить маршруты `admin/delivery-addresses`

---

### 4.6. Финансы

#### Валюты (Currencies)
- [x] Создать `Admin\CurrencyController`
- [x] Добавить маршруты `admin/currencies`

#### Балансы пользователей (UserBalances)
- [x] Создать `Admin\UserBalanceController`
- [x] Добавить маршруты `admin/user-balances`

---

### 4.7. Контент

#### Статьи (Articles)
- [x] Создать `Admin\ArticleController`
- [x] Добавить маршруты `admin/articles`
- [x] Реализовать загрузку изображений
- [x] Интегрировать теги

#### Новости (News)
- [x] Создать `Admin\NewsController`
- [x] Добавить маршруты `admin/news`

#### FAQ
- [x] Создать `Admin\FaqController`
- [x] Добавить маршруты `admin/faqs`

#### Баннеры (Banners)
- [x] Создать `Admin\BannerController`
- [x] Добавить маршруты `admin/banners`
- [x] Реализовать polymorphic-связь (linkable)

#### Страницы (Pages)
- [x] Создать `Admin\PageController`
- [x] Добавить маршруты `admin/pages`

#### Истории (Stories)
- [x] Создать `Admin\StoryController` и `Admin\StorySlideController`
- [x] Добавить маршруты `admin/stories` (с nested slides)
- [x] Реализовать CRUD для Stories
- [x] Реализовать inline создание/редактирование слайдов
- [x] Реализовать drag-and-drop сортировку

---

### 4.8. Теги

- [x] Создать `Admin\TagController`
- [x] Добавить маршруты `admin/tags`

---

### 4.9. Система

#### Медиа (Media)
- [x] Создать `Admin\MediaController`
- [x] Добавить маршруты `admin/media`
- [x] Реализовать просмотр и удаление медиафайлов

#### Настройки
- [x] Создать `Admin\SettingsController`
- [x] Добавить маршруты `admin/settings`

---

## Фаза 5: CRUD-страницы (Frontend)

### 5.1. Шаблон CRUD-страниц

Для каждой сущности создать:
- [ ] `Index.jsx` — список с таблицей, пагинацией, поиском
- [ ] `Create.jsx` — форма создания
- [ ] `Edit.jsx` — форма редактирования
- [ ] `Show.jsx` — страница просмотра (опционально)

### 5.2. Каталог

#### Товары
- [x] Создать `Admin/Pages/Products/Index.jsx`
- [x] Создать `Admin/Pages/Products/Create.jsx`
- [x] Создать `Admin/Pages/Products/Edit.jsx`
- [x] Реализовать выбор категорий (множественный)
- [x] Реализовать выбор атрибутов и значений
- [x] Реализовать загрузку изображений

#### Категории
- [x] Создать `Admin/Pages/Categories/Index.jsx`
- [x] Создать `Admin/Pages/Categories/Create.jsx`
- [x] Создать `Admin/Pages/Categories/Edit.jsx`
- [x] Реализовать выбор родительской категории

#### Бренды
- [x] Создать `Admin/Pages/Brands/Index.jsx`
- [x] Создать `Admin/Pages/Brands/Create.jsx`
- [x] Создать `Admin/Pages/Brands/Edit.jsx`

#### Модели товаров
- [x] Создать `Admin/Pages/ProductModels/Index.jsx`
- [x] Создать `Admin/Pages/ProductModels/Create.jsx`
- [x] Создать `Admin/Pages/ProductModels/Edit.jsx`

#### Атрибуты
- [x] Создать `Admin/Pages/Attributes/Index.jsx`
- [x] Создать `Admin/Pages/Attributes/Create.jsx`
- [x] Создать `Admin/Pages/Attributes/Edit.jsx`
- [x] Реализовать inline-редактирование значений атрибутов

#### Размерные сетки
- [x] Создать `Admin/Pages/SizeCharts/Index.jsx`
- [x] Создать `Admin/Pages/SizeCharts/Create.jsx`
- [x] Создать `Admin/Pages/SizeCharts/Edit.jsx`

#### Штрихкоды
- [x] Создать `Admin/Pages/ProductBarcodes/Index.jsx`
- [x] Создать `Admin/Pages/ProductBarcodes/Create.jsx`
- [x] Создать `Admin/Pages/ProductBarcodes/Edit.jsx`

#### Сертификаты
- [x] Создать `Admin/Pages/Certificates/Index.jsx`
- [x] Создать `Admin/Pages/Certificates/Create.jsx`
- [x] Создать `Admin/Pages/Certificates/Edit.jsx`

#### Сегменты
- [x] Создать `Admin/Pages/Segments/Index.jsx`
- [x] Создать `Admin/Pages/Segments/Create.jsx`
- [x] Создать `Admin/Pages/Segments/Edit.jsx`

---

### 5.3. Склады

- [x] Создать `Admin/Pages/Warehouses/Index.jsx`
- [x] Создать `Admin/Pages/Warehouses/Create.jsx`
- [x] Создать `Admin/Pages/Warehouses/Edit.jsx`
- [x] Создать `Admin/Pages/Regions/Index.jsx`
- [x] Создать `Admin/Pages/Regions/Create.jsx`
- [x] Создать `Admin/Pages/Regions/Edit.jsx`

---

### 5.4. Продажи

#### Заказы
- [x] Создать `Admin/Pages/Orders/Index.jsx`
- [x] Создать `Admin/Pages/Orders/Show.jsx` — детальный просмотр
- [x] Реализовать изменение статуса заказа

#### Корзины
- [x] Создать `Admin/Pages/Carts/Index.jsx`
- [x] Создать `Admin/Pages/Carts/Show.jsx`

#### Возвраты
- [x] Создать `Admin/Pages/Returns/Index.jsx`
- [x] Создать `Admin/Pages/Returns/Show.jsx`

#### Избранное
- [x] Создать `Admin/Pages/Favorites/Index.jsx`

#### Список желаний
- [x] Создать `Admin/Pages/Wishlist/Index.jsx`

---

### 5.5. Маркетинг

#### Promotions
- [x] Создать `Admin/Pages/Promotions/Index.jsx`
- [x] Создать `Admin/Pages/Promotions/Create.jsx`
- [x] Создать `Admin/Pages/Promotions/Edit.jsx`

#### Discounts
- [x] Создать `Admin/Pages/Discounts/Index.jsx`
- [x] Создать `Admin/Pages/Discounts/Create.jsx`
- [x] Создать `Admin/Pages/Discounts/Edit.jsx`

#### ProductSelections
- [x] Создать `Admin/Pages/ProductSelections/Index.jsx`
- [x] Создать `Admin/Pages/ProductSelections/Create.jsx`
- [x] Создать `Admin/Pages/ProductSelections/Edit.jsx`

---

### 5.6. Пользователи

- [x] Создать страницы CRUD для Users
- [x] Создать страницы CRUD для Companies
- [x] Создать страницы CRUD для CompanyBankAccounts
- [x] Создать страницы CRUD для DeliveryAddresses

---

### 5.7. Финансы

- [x] Создать страницы CRUD для Currencies
- [x] Создать страницы CRUD для UserBalances

---

### 5.8. Контент

- [x] Создать страницы CRUD для Articles (с тегами, изображениями)
- [x] Создать страницы CRUD для News
- [x] Создать страницы CRUD для Faqs
- [x] Создать страницы CRUD для Banners
- [x] Создать страницы CRUD для Pages
- [x] Создать страницы CRUD для Stories (с управлением слайдами)

---

### 5.9. Теги

- [x] Создать страницы CRUD для Tags

---

### 5.10. Система

- [x] Создать страницу Media (галерея медиафайлов)
- [x] Создать страницу Settings

---

## Фаза 6: Сложные связи и вложенные сущности

### 6.1. Продукты и связи
- [x] Реализовать привязку товаров к категориям (many-to-many)
- [x] Реализовать привязку товаров к складам с quantity
- [x] Реализовать управление атрибутами товара (product_attribute_values)
- [x] Реализовать привязку сертификатов к товарам
- [x] ~~Реализовать привязку сегментов к товарам~~ (сегменты удалены из проекта)

### 6.2. Заказы
- [x] Реализовать просмотр и редактирование позиций заказа (order_items)
- [x] Реализовать историю изменения статусов

### 6.3. Истории (Stories)
- [x] Реализовать drag-and-drop для сортировки слайдов
- [x] Реализовать inline-создание слайдов

### 6.4. Атрибуты
- [x] Реализовать inline-редактирование значений атрибутов
- [x] Реализовать сортировку значений (sort_order)

---

## Фаза 7: UX/UI Полировка

### 7.1. Темизация
- [ ] Настроить цветовую палитру в соответствии с брендом
- [ ] Добавить кастомные semantic tokens
- [ ] Проверить оба режима (светлый/тёмный)

### 7.2. Анимации и микро-взаимодействия
- [ ] Добавить hover-эффекты на строки таблиц
- [ ] Добавить transitions на раскрытие меню
- [ ] Добавить анимации загрузки (Skeleton)
- [ ] Добавить glassmorphism-эффекты (опционально)

### 7.3. Адаптивность
- [ ] Протестировать на мобильных устройствах
- [ ] Убедиться в корректной работе Drawer-меню
- [ ] Оптимизировать таблицы для мобилок (горизонтальный скролл или карточки)

### 7.4. Доступность (a11y)
- [ ] Добавить `aria-current="page"` для активных ссылок
- [ ] Убедиться в keyboard-навигации
- [ ] Проверить контрастность цветов

---

## Фаза 8: Дополнительный функционал

### 8.1. Dashboard аналитика
- [ ] Добавить карточки статистики (количество заказов, товаров, пользователей)
- [ ] Добавить графики (Chart.js или Recharts)
- [ ] Добавить последние заказы / активность

### 8.2. Экспорт данных
- [ ] Реализовать экспорт в CSV для таблиц
- [ ] Реализовать экспорт в Excel (опционально)

### 8.3. Массовые действия
- [ ] Массовое удаление записей
- [ ] Массовое изменение статуса
- [ ] Массовый импорт (опционально)

### 8.4. Логирование действий
- [ ] Добавить логирование действий администраторов
- [ ] Создать страницу "Журнал действий"

---

## Фаза 9: Тестирование

### 9.1. Feature тесты (Backend)
- [ ] Тесты для аутентификации админа
- [ ] Тесты CRUD для основных сущностей
- [ ] Тесты авторизации (только admin)

### 9.2. Browser тесты
- [ ] Тесты навигации по меню
- [ ] Тесты CRUD-операций в браузере
- [ ] Тесты форм и валидации

---

## Фаза 10: Документация и финализация

### 10.1. Документация
- [ ] Обновить README с инструкциями по запуску
- [ ] Задокументировать структуру компонентов
- [ ] Написать гайд для добавления новых CRUD-ресурсов

### 10.2. Код ревью и рефакторинг
- [ ] Проверить консистентность кода
- [ ] Удалить неиспользуемые зависимости
- [ ] Оптимизировать SQL-запросы (eager loading)

### 10.3. Деплой
- [ ] Подготовить production-конфигурацию
- [ ] Настроить assets compilation
- [ ] Проверить работу на production-окружении

---

## Приоритеты реализации

Рекомендуемый порядок разработки:

1. **Базовая инфраструктура** (Фаза 1) — **КРИТИЧНО**
2. **Layout и навигация** (Фаза 2) — **КРИТИЧНО**
3. **Переиспользуемые компоненты** (Фаза 3) — **ВЫСОКИЙ**
4. **Пилотный CRUD: Articles** — проверка всего pipeline
5. **CRUD для контента** (Articles, News, FAQ, Pages) — **ВЫСОКИЙ**
6. **CRUD для каталога** (Products, Categories, Brands) — **ВЫСОКИЙ**
7. **CRUD для продаж** (Orders) — **ВЫСОКИЙ**
8. **Остальные CRUD** — **СРЕДНИЙ**
9. **UX-полировка, аналитика** — **НИЗКИЙ**
10. **Тестирование и документация** — **ФИНАЛ**

---

## Оценка трудозатрат

| Фаза | Оценка (часы) |
|------|---------------|
| Фаза 1: Инфраструктура | 4-6 |
| Фаза 2: Layout | 8-12 |
| Фаза 3: Компоненты | 12-16 |
| Фаза 4: Backend CRUD (все сущности) | 24-32 |
| Фаза 5: Frontend CRUD (все сущности) | 40-56 |
| Фаза 6: Сложные связи | 16-24 |
| Фаза 7: UX-полировка | 8-12 |
| Фаза 8: Доп. функционал | 12-16 |
| Фаза 9: Тестирование | 16-24 |
| Фаза 10: Документация | 4-8 |
| **ИТОГО** | **~144-206 часов** |

---

## Чеклист готовности

- [ ] Все CRUD-операции работают для всех сущностей
- [ ] Меню полностью соответствует структуре из `admin_menu_structure.md`
- [ ] UI на русском языке (включая валидацию и пагинацию)
- [ ] Темная/светлая тема переключается корректно
- [ ] Мобильная версия работает
- [ ] Все связи между сущностями реализованы
- [ ] Feature-тесты покрывают основные сценарии
- [ ] Документация актуальна
