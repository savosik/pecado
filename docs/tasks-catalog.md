# Задачи по реализации Каталога (Pecado)

Документ содержит декомпозицию задач на основе [Технического задания](tz-catalog.md).
**Стек**: Laravel 12, Inertia 2, React 19, Tailwind 4.

---

## Спринт 1: Ядро Backend

### [BE-01] Подготовка БД и Моделей
**Описание**: Создать необходимые индексы и трейты.
**Детали**:
- [ ] Добавить индексы в таблицу `products`: `category_id`, `brand_id`, `base_price`, `created_at`, `slug`.
- [ ] Создать трейт `App\Models\Traits\ProductQueryScopes`.
- [ ] Реализовать в трейте заготовки скоупов: `scopeActive`, `scopeSearch`, `scopeInCategory`, `scopeInBrands`, `scopeByPrice`, `scopeInStock`, `scopeInSale`.
- [ ] Подключить трейт к модели `Product`.

### [BE-02] Enum сортировки и Request валидации
**Описание**: Создать структуры для управления параметрами каталога.
**Детали**:
- [ ] Создать Enum `App\Enums\CatalogSort`:
    - Значения: `newest`, `price_asc`, `price_desc`, `name_asc`, `name_desc`.
    - Метод `apply(Builder $query)`.
    - Метод `label()` (русские названия).
- [ ] Создать `App\Http\Requests\User\ProductFilterRequest`:
    - Правила валидации для всех фильтров (см. п. 6.4 ТЗ).
    - Метод `prepareForValidation` для разворота Compact URL параметров (`fv` -> `attribute_value_ids`, `b` -> `brand_ids` и т.д.).

### [BE-03] Сервис фасетов (CatalogFacetService)
**Описание**: Реализовать логику агрегации данных для фильтров.
**Детали**:
- [ ] Создать класс `App\Services\Product\CatalogFacetService`.
- [ ] Метод `getAttributeFacets`: агрегация по `attribute_values` (связь через `product_attribute_values`).
- [ ] Метод `getBrandFacets`: агрегация по `brand_id`.
- [ ] Метод `getCategoryFacets`: агрегация по `category_id`.
- [ ] Метод `getPriceIntervals`: min/max цены + гистограмма (buckets).
- **Важно**: Использовать `GROUP BY` для оптимизации, избегать N+1.

### [BE-04] Контроллер API (CatalogApiController)
**Описание**: Эндпоинты для получения JSON данных каталога.
**Детали**:
- [ ] Создать `App\Http\Controllers\User\CatalogApiController`.
- [ ] Метод `products(ProductFilterRequest $request)`: возвращает список товаров с пагинацией (Resource Collection).
- [ ] Метод `facets(ProductFilterRequest $request)`: возвращает доступные бренды, категории, атрибуты.
- [ ] Метод `priceIntervals(...)`: возвращает min/max и бакеты.
- [ ] Зарегистрировать роуты: `GET /api/catalog/products`, `/facets`, `/price-intervals`.

### [BE-05] Web-контроллер и Роутинг
**Описание**: Настройка точек входа для Inertia страниц.
**Детали**:
- [ ] Обновить `User\ProductController`:
    - Метод `index`: рендер `User/Products/Index`.
    - Метод `byBrand($slug)`: рендер `Index` с пресетом бренда.
    - Метод `byCategory($slug)`: рендер `Index` с пресетом категории.
    - Метод `bySelection($slug)`: рендер `Index` с пресетом подборки.
    - Метод `favorites()`: рендер `Index` с пресетом `in_favourites=1`.
- [ ] Зарегистрировать роуты в `web.php`.
- [ ] Обеспечить передачу базовых props (SEO, начальные фильтры) в Inertia.

---

## Спринт 2: Frontend Каталога (UI Ядро)

### [FE-01] Макет страницы каталога и Хедер
**Описание**: Создать общую структуру страницы.
**Детали**:
- [ ] Компонент `User/Products/Index.jsx`:
    - Layout с сайдбаром (desktop) / без (mobile).
    - Интеграция `CatalogHeader` (Заголовок H1, кол-во товаров).
- [ ] Компонент `Breadcrumbs.jsx`:
    - Динамическая генерация на основе текущей категории/бренда.

### [FE-02] Контролы каталога
**Описание**: Панель управления видом и сортировкой.
**Детали**:
- [ ] Компонент `CatalogControls.jsx`:
    - Сортировка (Select): по новизне, цене, имени.
    - Вид (Icon Buttons): Сетка / Список.
    - Кол-во на страницу (Select): 10, 20, 60...
- [ ] Синхронизация контролов с URL (через хук фильтров).

### [FE-03] Сетка товаров (ProductGrid)
**Описание**: Отображение списка товаров.
**Детали**:
- [ ] Компонент `ProductGrid.jsx`:
    - Адаптивная сетка: 2 колонки (mobile), 3 (lg), 4-5 (xl).
- [ ] Компонент `ProductGridItem.jsx`:
    - Фото, цена (старая/новая), лейблы (скидка/новинка), кнопка "В корзину", "Избранное".
- [ ] Компонент `ProductListItem.jsx`:
    - Горизонтальная верстка карточки.
- [ ] Skeleton загрузки (заглушки карточек).

### [FE-04] Пагинация и Infinite Scroll
**Описание**: Навигация по страницам.
**Детали**:
- [ ] Компонент `ProductPagination.jsx`:
    - Классическая пагинация (номера страниц).
- [ ] Логика Infinite Scroll:
    - Кнопка "Загрузить еще" или автоподгрузка.
    - Мердж новых товаров к существующему списку.

### [FE-05] Хуки управления состоянием
**Описание**: Логика работы с фильтрами и API.
**Детали**:
- [ ] Утилиты Compact URL (`utils/compactFilters.js`): `encode` / `decode` параметров.
- [ ] Хук `useCatalogFilters`: чтение из URL, обновление URL, debouncing.
- [ ] Хук `useCatalogProducts`: запрос к API `/products` с поддержкой `AbortController`.

---

## Спринт 3: Фильтры (Frontend)

### [FE-06] Базовые фильтры
**Описание**: Реализация простых фильтров.
**Детали**:
- [ ] `SearchFilter`: input, debounce 300ms.
- [ ] `PriceFilter`: Range slider (min-max) + inputs + гистограмма.
- [ ] `StockFilter`: Radio buttons (В наличии / Все).
- [ ] `SelectedFilters`: Панель выбранных чипсов (сброс по одному и всех сразу).

### [FE-07] Сложные фильтры (Фасеты)
**Описание**: Фильтры, зависящие от данных (Бренды, Категории, Атрибуты).
**Детали**:
- [ ] Хук `useCatalogFacets`: загрузка данных для фильтров.
- [ ] `CategoryFilter`: Дерево категорий (recursive component).
- [ ] `BrandFilter`: Список с чекбоксами и поиском (если > 10).
- [ ] `AttributeFilters`: Динамические блоки (Цвет, Размер и т.д.).
- [ ] Отображение счетчиков (counts) рядом с чекбоксами.

### [FE-08] Мобильная фильтрация
**Описание**: Адаптация фильтров для телефонов.
**Детали**:
- [ ] Компонент `ProductFiltersSheet` (Drawer/Offcanvas).
- [ ] Кнопка "Фильтры" в мобильной версии (Floating или в хедере списка).
- [ ] Синхронизация состояния (Применить / Сбросить).

---

## Спринт 4: SEO и Полировка

### [FE-09] SEO Оптимизация
**Описание**: Мета-теги для всех страниц каталога.
**Детали**:
- [ ] Компонент `SeoHead`: Title, Description, H1.
- [ ] Уникальные заголовки:
    - "{Категория} купить..."
    - "{Бренд} в каталоге..."
- [ ] Canonical URL (учет пагинации и сортировки).

### [QA-01] Тестирование и Оптимизация
**Описание**: Проверка работоспособности.
**Детали**:
- [ ] Проверка Compact URL (корректность ссылок).
- [ ] Проверка "Назад" в браузере (history state).
- [ ] Проверка сброса пагинации при фильтрации.
- [ ] Скорость загрузки (Lighthouse).
- [ ] Проверка Empty State (когда товаров нет).

---

## 20. На потом (Backlog)
Задачи, исключенные из MPV:
1. Фильтр «Просмотренные» (Backend + Frontend).
2. Фильтр «В корзине» (Backend + Frontend).
3. Сохранение фильтров (Личный кабинет + LocalStorage).
4. Сортировка «По популярности».
5. Маршрут промо-акций.
