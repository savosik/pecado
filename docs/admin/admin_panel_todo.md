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
- [ ] Создать директорию `resources/js/Admin/`
- [ ] Создать директорию `resources/js/Admin/Layouts/`
- [ ] Создать директорию `resources/js/Admin/Components/`
- [ ] Создать директорию `resources/js/Admin/Pages/`

### 2.2. Главный Layout
- [ ] Создать `AdminLayout.jsx` — основной shell для всех страниц
- [ ] Реализовать адаптивную структуру (Sidebar + Content Area)
- [ ] Добавить поддержку темной/светлой темы

### 2.3. Sidebar (Боковое меню)
- [ ] Создать `Sidebar.jsx` с Accordion-навигацией
- [ ] Создать `menuConfig.ts` — конфигурация меню из документации
- [ ] Реализовать выделение активного пункта меню
- [ ] Добавить иконки из `react-icons/lu`
- [ ] Реализовать collapsed-режим (свёрнутое меню)
- [ ] Создать `MobileSidebar.jsx` с использованием Drawer

### 2.4. Header (Верхняя панель)
- [ ] Создать `Header.jsx`
- [ ] Добавить кнопку переключения темы (ColorModeButton)
- [ ] Добавить Breadcrumbs для навигации
- [ ] Добавить UserMenu (dropdown с профилем, выход)
- [ ] Добавить кнопку toggle для мобильного меню

### 2.5. Dashboard страница
- [ ] Создать `resources/js/Admin/Pages/Dashboard.jsx`
- [ ] Добавить карточки с базовой статистикой (заглушки)
- [ ] Подключить `AdminLayout`

---

## Фаза 3: Переиспользуемые компоненты

### 3.1. DataTable (Таблица данных)
- [ ] Создать `DataTable.jsx` — универсальный компонент таблицы
- [ ] Реализовать конфигурацию колонок через props
- [ ] Добавить поддержку пагинации (интеграция с Laravel Paginator)
- [ ] Добавить сортировку по колонкам
- [ ] Добавить поиск/фильтрацию
- [ ] Добавить Skeleton-загрузку
- [ ] Добавить выбор количества записей на странице
- [ ] Добавить массовые действия (удаление, экспорт)

### 3.2. Формы
- [ ] Создать `FormField.jsx` — обёртка для полей с label и ошибками
- [ ] Создать `FormActions.jsx` — кнопки сохранения/отмены
- [ ] Интегрировать с `useForm` от Inertia.js
- [ ] Добавить компонент `ImageUploader.jsx` для загрузки изображений
- [ ] Добавить компонент `RichTextEditor.jsx` (если требуется)
- [ ] Добавить компонент `SelectRelation.jsx` для связей

### 3.3. Модальные окна и уведомления
- [ ] Создать `ConfirmDialog.jsx` — диалог подтверждения удаления
- [ ] Настроить `Toaster` для уведомлений (успех, ошибка)
- [ ] Создать `PageHeader.jsx` — заголовок страницы с кнопкой "Создать"

### 3.4. Фильтры
- [ ] Создать `FilterPanel.jsx` — панель фильтров для таблиц
- [ ] Создать `SearchInput.jsx` — поле поиска с debounce
- [ ] Создать `DateRangePicker.jsx` — выбор диапазона дат

---

## Фаза 4: CRUD-контроллеры (Backend)

### 4.1. Каталог

#### Товары (Products)
- [ ] Создать `Admin\ProductController` с методами CRUD
- [ ] Добавить маршруты `admin/products`
- [ ] Реализовать пагинацию, поиск, сортировку
- [ ] Добавить загрузку связей (brand, categories, attributes)

#### Категории (Categories)
- [ ] Создать `Admin\CategoryController`
- [ ] Добавить маршруты `admin/categories`
- [ ] Реализовать иерархическое отображение (parent_id)

#### Бренды (Brands)
- [ ] Создать `Admin\BrandController`
- [ ] Добавить маршруты `admin/brands`
- [ ] Реализовать иерархию брендов (parent_id)

#### Модели товаров (ProductModels)
- [ ] Создать `Admin\ProductModelController`
- [ ] Добавить маршруты `admin/product-models`

#### Атрибуты (Attributes)
- [ ] Создать `Admin\AttributeController`
- [ ] Добавить маршруты `admin/attributes`
- [ ] Включить управление значениями атрибутов (AttributeValues)

#### Размерные сетки (SizeCharts)
- [ ] Создать `Admin\SizeChartController`
- [ ] Добавить маршруты `admin/size-charts`

#### Штрихкоды (ProductBarcodes)
- [ ] Создать `Admin\ProductBarcodeController`
- [ ] Добавить маршруты `admin/product-barcodes`

#### Сертификаты (Certificates)
- [ ] Создать `Admin\CertificateController`
- [ ] Добавить маршруты `admin/certificates`

#### Сегменты (Segments)
- [ ] Создать `Admin\SegmentController`
- [ ] Добавить маршруты `admin/segments`

---

### 4.2. Склады и логистика

#### Склады (Warehouses)
- [ ] Создать `Admin\WarehouseController`
- [ ] Добавить маршруты `admin/warehouses`

#### Регионы (Regions)
- [ ] Создать `Admin\RegionController`
- [ ] Добавить маршруты `admin/regions`
- [ ] Реализовать привязку регионов к складам

---

### 4.3. Продажи

#### Заказы (Orders)
- [ ] Создать `Admin\OrderController`
- [ ] Добавить маршруты `admin/orders`
- [ ] Реализовать просмотр деталей заказа (order_items)
- [ ] Добавить смену статуса заказа

#### Корзины (Carts)
- [ ] Создать `Admin\CartController`
- [ ] Добавить маршруты `admin/carts`
- [ ] Реализовать просмотр cart_items

#### Возвраты (Returns)
- [ ] Создать `Admin\ReturnController`
- [ ] Добавить маршруты `admin/returns`
- [ ] Реализовать просмотр return_items

#### Избранное (Favorites)
- [ ] Создать `Admin\FavoriteController`
- [ ] Добавить маршруты `admin/favorites`

#### Список желаний (Wishlist)
- [ ] Создать `Admin\WishlistController`
- [ ] Добавить маршруты `admin/wishlist`

---

### 4.4. Маркетинг

#### Акции (Promotions)
- [ ] Создать `Admin\PromotionController`
- [ ] Добавить маршруты `admin/promotions`
- [ ] Реализовать привязку товаров к акциям

#### Скидки (Discounts)
- [ ] Создать `Admin\DiscountController`
- [ ] Добавить маршруты `admin/discounts`
- [ ] Реализовать привязку к товарам и пользователям

#### Подборки товаров (ProductSelections)
- [ ] Создать `Admin\ProductSelectionController`
- [ ] Добавить маршруты `admin/product-selections`

---

### 4.5. Пользователи

#### Пользователи (Users)
- [ ] Создать `Admin\UserController`
- [ ] Добавить маршруты `admin/users`
- [ ] Реализовать фильтрацию по региону, is_admin

#### Компании (Companies)
- [ ] Создать `Admin\CompanyController`
- [ ] Добавить маршруты `admin/companies`

#### Банковские счета (CompanyBankAccounts)
- [ ] Создать `Admin\CompanyBankAccountController`
- [ ] Добавить маршруты `admin/company-bank-accounts`

#### Адреса доставки (DeliveryAddresses)
- [ ] Создать `Admin\DeliveryAddressController`
- [ ] Добавить маршруты `admin/delivery-addresses`

---

### 4.6. Финансы

#### Валюты (Currencies)
- [ ] Создать `Admin\CurrencyController`
- [ ] Добавить маршруты `admin/currencies`

#### Балансы пользователей (UserBalances)
- [ ] Создать `Admin\UserBalanceController`
- [ ] Добавить маршруты `admin/user-balances`

---

### 4.7. Контент

#### Статьи (Articles)
- [ ] Создать `Admin\ArticleController`
- [ ] Добавить маршруты `admin/articles`
- [ ] Реализовать загрузку изображений
- [ ] Интегрировать теги

#### Новости (News)
- [ ] Создать `Admin\NewsController`
- [ ] Добавить маршруты `admin/news`

#### FAQ
- [ ] Создать `Admin\FaqController`
- [ ] Добавить маршруты `admin/faqs`

#### Баннеры (Banners)
- [ ] Создать `Admin\BannerController`
- [ ] Добавить маршруты `admin/banners`
- [ ] Реализовать polymorphic-связь (linkable)

#### Страницы (Pages)
- [ ] Создать `Admin\PageController`
- [ ] Добавить маршруты `admin/pages`

#### Истории (Stories)
- [ ] Создать `Admin\StoryController`
- [ ] Добавить маршруты `admin/stories`
- [ ] Реализовать управление story_slides

---

### 4.8. Теги

- [ ] Создать `Admin\TagController`
- [ ] Добавить маршруты `admin/tags`

---

### 4.9. Система

#### Медиа (Media)
- [ ] Создать `Admin\MediaController`
- [ ] Добавить маршруты `admin/media`
- [ ] Реализовать просмотр и удаление медиафайлов

#### Настройки
- [ ] Создать `Admin\SettingsController`
- [ ] Добавить маршруты `admin/settings`

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
- [ ] Создать `Admin/Pages/Products/Index.jsx`
- [ ] Создать `Admin/Pages/Products/Create.jsx`
- [ ] Создать `Admin/Pages/Products/Edit.jsx`
- [ ] Реализовать выбор категорий (множественный)
- [ ] Реализовать выбор атрибутов и значений
- [ ] Реализовать загрузку изображений

#### Категории
- [ ] Создать `Admin/Pages/Categories/Index.jsx`
- [ ] Создать `Admin/Pages/Categories/Create.jsx`
- [ ] Создать `Admin/Pages/Categories/Edit.jsx`
- [ ] Реализовать выбор родительской категории

#### Бренды
- [ ] Создать `Admin/Pages/Brands/Index.jsx`
- [ ] Создать `Admin/Pages/Brands/Create.jsx`
- [ ] Создать `Admin/Pages/Brands/Edit.jsx`

#### Модели товаров
- [ ] Создать `Admin/Pages/ProductModels/Index.jsx`
- [ ] Создать `Admin/Pages/ProductModels/Create.jsx`
- [ ] Создать `Admin/Pages/ProductModels/Edit.jsx`

#### Атрибуты
- [ ] Создать `Admin/Pages/Attributes/Index.jsx`
- [ ] Создать `Admin/Pages/Attributes/Create.jsx`
- [ ] Создать `Admin/Pages/Attributes/Edit.jsx`
- [ ] Реализовать inline-редактирование значений атрибутов

#### Размерные сетки
- [ ] Создать `Admin/Pages/SizeCharts/Index.jsx`
- [ ] Создать `Admin/Pages/SizeCharts/Create.jsx`
- [ ] Создать `Admin/Pages/SizeCharts/Edit.jsx`

#### Штрихкоды
- [ ] Создать `Admin/Pages/ProductBarcodes/Index.jsx`
- [ ] Создать `Admin/Pages/ProductBarcodes/Create.jsx`
- [ ] Создать `Admin/Pages/ProductBarcodes/Edit.jsx`

#### Сертификаты
- [ ] Создать `Admin/Pages/Certificates/Index.jsx`
- [ ] Создать `Admin/Pages/Certificates/Create.jsx`
- [ ] Создать `Admin/Pages/Certificates/Edit.jsx`

#### Сегменты
- [ ] Создать `Admin/Pages/Segments/Index.jsx`
- [ ] Создать `Admin/Pages/Segments/Create.jsx`
- [ ] Создать `Admin/Pages/Segments/Edit.jsx`

---

### 5.3. Склады

- [ ] Создать `Admin/Pages/Warehouses/Index.jsx`
- [ ] Создать `Admin/Pages/Warehouses/Create.jsx`
- [ ] Создать `Admin/Pages/Warehouses/Edit.jsx`
- [ ] Создать `Admin/Pages/Regions/Index.jsx`
- [ ] Создать `Admin/Pages/Regions/Create.jsx`
- [ ] Создать `Admin/Pages/Regions/Edit.jsx`

---

### 5.4. Продажи

#### Заказы
- [ ] Создать `Admin/Pages/Orders/Index.jsx`
- [ ] Создать `Admin/Pages/Orders/Show.jsx` — детальный просмотр
- [ ] Реализовать изменение статуса заказа

#### Корзины
- [ ] Создать `Admin/Pages/Carts/Index.jsx`
- [ ] Создать `Admin/Pages/Carts/Show.jsx`

#### Возвраты
- [ ] Создать `Admin/Pages/Returns/Index.jsx`
- [ ] Создать `Admin/Pages/Returns/Show.jsx`

#### Избранное
- [ ] Создать `Admin/Pages/Favorites/Index.jsx`

#### Список желаний
- [ ] Создать `Admin/Pages/Wishlist/Index.jsx`

---

### 5.5. Маркетинг

- [ ] Создать страницы CRUD для Promotions
- [ ] Создать страницы CRUD для Discounts
- [ ] Создать страницы CRUD для ProductSelections

---

### 5.6. Пользователи

- [ ] Создать страницы CRUD для Users
- [ ] Создать страницы CRUD для Companies
- [ ] Создать страницы CRUD для CompanyBankAccounts
- [ ] Создать страницы CRUD для DeliveryAddresses

---

### 5.7. Финансы

- [ ] Создать страницы CRUD для Currencies
- [ ] Создать страницы CRUD для UserBalances

---

### 5.8. Контент

- [ ] Создать страницы CRUD для Articles (с тегами, изображениями)
- [ ] Создать страницы CRUD для News
- [ ] Создать страницы CRUD для Faqs
- [ ] Создать страницы CRUD для Banners
- [ ] Создать страницы CRUD для Pages
- [ ] Создать страницы CRUD для Stories (с управлением слайдами)

---

### 5.9. Теги

- [ ] Создать страницы CRUD для Tags

---

### 5.10. Система

- [ ] Создать страницу Media (галерея медиафайлов)
- [ ] Создать страницу Settings

---

## Фаза 6: Сложные связи и вложенные сущности

### 6.1. Продукты и связи
- [ ] Реализовать привязку товаров к категориям (many-to-many)
- [ ] Реализовать привязку товаров к складам с quantity
- [ ] Реализовать управление атрибутами товара (product_attribute_values)
- [ ] Реализовать привязку сертификатов к товарам
- [ ] Реализовать привязку сегментов к товарам

### 6.2. Заказы
- [ ] Реализовать просмотр и редактирование позиций заказа (order_items)
- [ ] Реализовать историю изменения статусов

### 6.3. Истории (Stories)
- [ ] Реализовать drag-and-drop для сортировки слайдов
- [ ] Реализовать inline-создание слайдов

### 6.4. Атрибуты
- [ ] Реализовать inline-редактирование значений атрибутов
- [ ] Реализовать сортировку значений (sort_order)

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
