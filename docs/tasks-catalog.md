# Задачи по реализации Каталога (Pecado)

Документ содержит декомпозицию задач на основе [Технического задания](tz-catalog.md).
**Стек**: Laravel 12, Inertia 2, React 19, Tailwind 4.

---

## Спринт 1: Ядро Backend

### [BE-01] Подготовка БД и Моделей
**Описание**: Создать необходимые индексы и трейты.
**Детали**:
- [x] Добавить индексы в таблицу `products`: `category_id`, `brand_id`, `base_price`, `created_at`, `slug`.
- [x] Создать трейт `App\Models\Traits\ProductQueryScopes`.
- [x] Реализовать в трейте заготовки скоупов: `scopeActive`, `scopeSearch`, `scopeInCategory`, `scopeInBrands`, `scopeByPrice`, `scopeInStock`, `scopeInSale`.
- [x] Подключить трейт к модели `Product`.

### [BE-02] Enum сортировки и Request валидации
**Описание**: Создать структуры для управления параметрами каталога.
**Детали**:
- [x] Создать Enum `App\Enums\CatalogSort`:
    - Значения: `newest`, `price_asc`, `price_desc`, `name_asc`, `name_desc`.
    - Метод `apply(Builder $query)`.
    - Метод `label()` (русские названия).
- [x] Создать `App\Http\Requests\User\ProductFilterRequest`:
    - Правила валидации для всех фильтров (см. п. 6.4 ТЗ).
    - Метод `prepareForValidation` для разворота Compact URL параметров (`fv` -> `attribute_value_ids`, `b` -> `brand_ids` и т.д.).

### [BE-03] Сервис фасетов (CatalogFacetService)
**Описание**: Реализовать логику агрегации данных для фильтров.
**Детали**:
- [x] Создать класс `App\Services\Product\CatalogFacetService`.
- [x] Метод `getAttributeFacets`: агрегация по `attribute_values` (связь через `product_attribute_values`).
- [x] Метод `getBrandFacets`: агрегация по `brand_id`.
- [x] Метод `getCategoryFacets`: агрегация по `category_id`.
- [x] Метод `getPriceIntervals`: min/max цены + гистограмма (buckets).
- **Важно**: Использовать `GROUP BY` для оптимизации, избегать N+1.

### [BE-04] Контроллер API (CatalogApiController)
**Описание**: Эндпоинты для получения JSON данных каталога.
**Детали**:
- [x] Создать `App\Http\Controllers\User\CatalogApiController`.
- [x] Метод `products(ProductFilterRequest $request)`: возвращает список товаров с пагинацией (Resource Collection).
- [x] Метод `facets(ProductFilterRequest $request)`: возвращает доступные бренды, категории, атрибуты.
- [x] Метод `priceIntervals(...)`: возвращает min/max и бакеты.
- [x] Зарегистрировать роуты: `GET /api/catalog/products`, `/facets`, `/price-intervals`.

### [BE-05] Web-контроллер и Роутинг
**Описание**: Настройка точек входа для Inertia страниц.
**Детали**:
- [x] Обновить `User\ProductController`:
    - Метод `index`: рендер `User/Products/Index`.
    - Метод `byBrand($slug)`: рендер `Index` с пресетом бренда.
    - Метод `byCategory($slug)`: рендер `Index` с пресетом категории.
    - Метод `bySelection($slug)`: рендер `Index` с пресетом подборки.
    - Метод `favorites()`: рендер `Index` с пресетом `in_favourites=1`.
- [x] Зарегистрировать роуты в `web.php`.
- [x] Обеспечить передачу базовых props (SEO, начальные фильтры) в Inertia.

---

## Спринт 2: Frontend Каталога (UI Ядро)

### [FE-01] Макет страницы каталога и Хедер
**Описание**: Создать общую структуру страницы.
**Детали**:
- [x] Компонент `User/Products/Index.jsx`:
    - Layout с сайдбаром (desktop) / без (mobile).
    - Интеграция `CatalogHeader` (Заголовок H1, кол-во товаров).
- [x] Компонент `Breadcrumbs.jsx`:
    - Динамическая генерация на основе текущей категории/бренда.

### [FE-02] Контролы каталога
**Описание**: Панель управления видом и сортировкой.
**Детали**:
- [x] Компонент `CatalogControls.jsx`:
    - Сортировка (Select): по новизне, цене, имени.
    - Вид (Icon Buttons): Сетка / Список.
    - Кол-во на страницу (Select): 10, 20, 40, 60, 100.
- [x] Синхронизация контролов с URL (через `history.replaceState`).

### [FE-03] Сетка товаров (ProductGrid)
**Описание**: Отображение списка товаров.
**Детали**:
- [x] Компонент `ProductGrid.jsx`:
    - Адаптивная сетка: 2 колонки (mobile), 3 (lg), 4-5 (xl).
- [x] Компонент `ProductGridItem.jsx`:
    - Фото, цена (старая/новая), лейблы (скидка/новинка), кнопка "В корзину", "Избранное".
- [x] Компонент `ProductListItem.jsx`:
    - Горизонтальная верстка карточки.
- [x] Skeleton загрузки (заглушки карточек).

### [FE-04] Пагинация и Infinite Scroll
**Описание**: Навигация по страницам.
**Детали**:
- [x] Компонент `ProductPagination.jsx`:
    - Классическая пагинация (номера страниц).
- [x] Логика Infinite Scroll:
    - Кнопка "Загрузить еще" или автоподгрузка.
    - Мердж новых товаров к существующему списку.

### [FE-05] Хуки управления состоянием
**Описание**: Логика работы с фильтрами и API.
**Детали**:
- [x] Утилиты Compact URL (`utils/compactFilters.js`): `encode` / `decode` параметров.
- [x] Хук `useCatalogFilters`: чтение из URL, обновление URL, debouncing.
- [x] Хук `useCatalogProducts`: запрос к API `/products` с поддержкой `AbortController`.

---

## Спринт 3: Фильтры (Frontend)

### [FE-06] Базовые фильтры
**Описание**: Реализация простых фильтров.
**Детали**:
- [x] `SearchFilter`: input, debounce 300ms.
- [x] `PriceFilter`: Range slider (min-max) + inputs + гистограмма.
- [x] `StockFilter`: Radio buttons (В наличии / Все).
- [x] `SelectedFilters`: Панель выбранных чипсов (сброс по одному и всех сразу).

### [FE-07] Сложные фильтры (Фасеты)
**Описание**: Фильтры, зависящие от данных (Бренды, Категории, Атрибуты).
**Детали**:
- [x] Хук `useCatalogFacets`: загрузка данных для фильтров.
- [x] `CategoryFilter`: Дерево категорий (recursive component).
- [x] `BrandFilter`: Список с чекбоксами и поиском (если > 10).
- [x] `AttributeFilters`: Динамические блоки (Цвет, Размер и т.д.).
- [x] Отображение счетчиков (counts) рядом с чекбоксами.

### [FE-08] Мобильная фильтрация
**Описание**: Адаптация фильтров для телефонов.
**Детали**:
- [x] Компонент `ProductFiltersSheet` (Drawer/Offcanvas).
- [x] Кнопка "Фильтры" в мобильной версии (Floating или в хедере списка).
- [x] Синхронизация состояния (Применить / Сбросить).

---

## Спринт 4: SEO и Полировка

### [FE-09] SEO Оптимизация
**Описание**: Мета-теги для всех страниц каталога.
**Детали**:
- [x] Компонент `SeoHead`: Title, Description, H1.
- [x] Уникальные заголовки:
    - "{Категория} купить..."
    - "{Бренд} в каталоге..."
- [x] Canonical URL (учет пагинации и сортировки).

### [QA-01] Тестирование и Оптимизация
**Описание**: Проверка работоспособности.
**Детали**:
- [x] Проверка Compact URL (корректность ссылок).
- [x] Проверка "Назад" в браузере (history state).
- [x] Проверка сброса пагинации при фильтрации.
- [x] Скорость загрузки (Lighthouse).
- [x] Проверка Empty State (когда товаров нет).

---

## 20. На потом (Backlog)
Задачи, исключенные из MPV:
1. Фильтр «Просмотренные» (Backend + Frontend).
2. Фильтр «В корзине» (Backend + Frontend).
3. Сохранение фильтров пользоватлея как в референсе (LocalStorage).
4. Сортировка «По популярности».
6. Скрыть фильтр по наличию для неавторизованных пользователей (scopeInStock требует region_id).
7. Скрыть фильтр по цене для неавторизованных пользователей (scopeByPrice фильтрует по base_price без учёта персональных цен и валют).
