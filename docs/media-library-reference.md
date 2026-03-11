# Медиа-тека (Media Library) — описание раздела из референса

## Общее описание

Медиа-тека — это отдельный раздел сайта (`/media`), дающий пользователям единую точку доступа ко всем медиа-файлам (изображения, видео, документы), привязанным к сущностям системы (товары, статьи, баннеры и т.д.).

Основные возможности:
- 🔍 **Поиск** по картинкам и другим медиа через текстовый запрос
- 🏷 **Фильтрация** по категории, тегам, типу файла, размеру, дате, типу сущности
- 📥 **Скачивание** — одиночное и пакетное (ZIP-архив)
- 🖼 **Lightbox** — просмотр изображений с зумом и навигацией
- ✅ **Выделение** — мультивыбор файлов для пакетных операций
- 📄 **Пагинация** — постраничная навигация

---

## Архитектура

### Схема БД

#### Таблица `media`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | Идентификатор |
| `media_category_id` | FK nullable | Ссылка на категорию медиа |
| `model_type` | string | Тип полиморфной связи (Product, Article, Banner...) |
| `model_id` | bigint | ID связанной сущности |
| `collection_name` | string | Коллекция (main, gallery, cover, logo...) |
| `file_name` | string | Имя файла |
| `sha1` | string(40) nullable | Хэш для дедупликации |
| `disk` | string (default: public) | Диск хранения (public / s3-media) |
| `mime_type` | string nullable | MIME-тип файла |
| `size` | bigint (default: 0) | Размер в байтах |
| `width` | int nullable | Ширина изображения |
| `height` | int nullable | Высота изображения |
| `sort_order` | int (default: 0) | Порядок сортировки |
| `is_adult` | boolean (default: true) | Контент 18+ |
| `custom_properties` | json nullable | Произвольные свойства |
| `generated_conversions` | json nullable | Сгенерированные конверсии |
| `created_at`, `updated_at` | timestamps | — |

**Индексы:**
- `(model_type, model_id, collection_name)` — быстрая выборка медиа сущности
- `UNIQUE(model_type, model_id, collection_name, sha1)` — дедупликация

#### Таблица `media_categories`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | Идентификатор |
| `name` | string | Название категории |
| `slug` | string (unique) | Слаг |
| `description` | string nullable | Описание |
| `is_active` | boolean (default: true) | Активна ли |
| `created_at`, `updated_at` | timestamps | — |

---

### Модели

#### `Media` (`app/Models/Media.php`)
- **Полиморфная связь** `model()` — MorphTo (привязка к любой сущности: Product, Article, Banner и т.д.)
- **Категория** `category()` — BelongsTo к `MediaCategory`
- **Теги** — через трейт `Taggable` (полиморфная many-to-many через `taggables`)
- **Поиск** — трейт `Laravel\Scout\Searchable` для полнотекстового поиска
- **URL-методы:**
  - `getUrl(?conversion)` — URL файла (оригинал или конверсия), автоматически определяет S3 или локальный диск
  - `getSafeUrl(?conversion)` — URL с проверкой 18+ контента (возвращает заблюренное превью при необходимости)
  - `buildBasePath()` — путь `media/{type}/{model_id}/{collection_name}`
- **Searchable payload** наследует поля родительской сущности (name, title, sku, code, barcodes), что позволяет искать медиа через данные товара

#### `MediaCategory` (`app/Models/MediaCategory.php`)
- Простая справочная модель с `name`, `slug`, `description`, `is_active`
- Метод `getOrCreateBySlug()` для удобного создания

---

### Маршруты (routes)

```
# Страница медиа-каталога (Inertia)
GET  /media                      → MediaCatalogController@page

# API медиа-каталога
GET  /api/media                  → MediaCatalogController@index       (список с фильтрацией)
GET  /api/media/facets           → MediaCatalogController@facets      (фасеты/счётчики для фильтров)
GET  /api/media/{media}          → MediaCatalogController@show        (детали одного медиа)
GET  /api/media/{media}/download → MediaCatalogController@download    (скачивание файла)
POST /api/media/download-batch   → MediaCatalogController@downloadBatch (пакетное скачивание ZIP)

# API загрузки/удаления медиа (CRUD)
GET    /api/media-upload         → MediaController@index   (список для управления)
POST   /media                    → MediaController@store   (загрузка файла)
DELETE /media/{media}            → MediaController@destroy (удаление файла)
```

---

### Контроллеры

#### `MediaCatalogController` — публичный каталог медиа

**Методы:**

1. **`page()`** — рендерит Inertia-страницу `Media/Index`, передаёт категории, теги, SEO-данные
2. **`index(MediaFilterRequest)`** — возвращает JSON пагинированного списка медиа с загруженными связями `category` и `tags`
3. **`facets(MediaFilterRequest)`** — возвращает JSON со счётчиками для динамических фильтров:
   - Фасеты по категориям (id, name, slug, count)
   - Фасеты по тегам (id, name, slug, color, count)
   - Фасеты по MIME-типам (mime_type, category, count)
   - Фасеты по типам сущностей (type, full_type, count)
4. **`show(Media)`** — детали одного медиа с `category`, `tags`, `model`
5. **`download(Media)`** — скачивание одного файла через `Storage::download()`
6. **`downloadBatch(MediaFilterRequest)`** — принимает массив `ids`, формирует ZIP-архив через `ZipArchive`, возвращает `BinaryFileResponse`, удаляет файл после отправки

**Фильтрация (`applyFilters`):**

| Параметр | Описание |
|----------|----------|
| `q` | Текстовый поиск по `file_name`, `collection_name`, и данным связанной модели (name, code, sku, barcode, title) |
| `media_category_id` / `media_category_ids` | Фильтр по категории медиа |
| `model_type` | Тип связанной сущности (Product, Article, News...) |
| `model_id` / `model_ids` | ID связанной сущности |
| `collection_name` | Фильтр по коллекции |
| `mime_type` / `mime_types` | Фильтр по MIME-типу (prefix match) |
| `size_min` / `size_max` | Диапазон размера файла |
| `width_min` / `width_max` | Диапазон ширины |
| `height_min` / `height_max` | Диапазон высоты |
| `tag_ids` | Фильтр по тегам |
| `created_from` / `created_to` | Диапазон дат создания |

**Сортировка (`applySorting`):**
- `newest` (по умолчанию), `oldest`, `size_asc`, `size_desc`, `name_asc`, `name_desc`

#### `MediaController` — CRUD для управления медиа

1. **`index(MediaSearchRequest)`** — список медиа с фильтрацией по keyword, model_type, model_id, product_id, brand_id, collection, mime
2. **`store(MediaUploadRequest)`** — загрузка файла с привязкой к модели, автоматическая генерация конверсий (thumbnail)
3. **`destroy(Media)`** — удаление файла и записи, очистка директории на диске

---

### Request-классы

#### `MediaFilterRequest` — валидация фильтров каталога

```php
'q'                    => 'sometimes|string|max:255'
'media_category_id'    => 'sometimes|integer|exists:media_categories,id'
'media_category_ids'   => 'sometimes|array' (элементы: integer, exists)
'model_type'           => 'sometimes|string|in:Product,Article,News,Banner,Brand,Category'
'model_id'             => 'sometimes|integer'
'collection_name'      => 'sometimes|string|max:255'
'mime_type'            => 'sometimes|string|max:100'
'mime_types'           => 'sometimes|array'
'size_min' / 'size_max'   => 'sometimes|integer|min:0'
'width_min' / 'width_max' => 'sometimes|integer|min:0'
'height_min' / 'height_max' => 'sometimes|integer|min:0'
'tag_ids'              => 'sometimes|array' (элементы: integer, exists)
'created_from' / 'created_to' => 'sometimes|date'
'sort'                 => 'sometimes|in:newest,oldest,size_asc,size_desc,name_asc,name_desc'
'per_page'             => 'sometimes|integer|min:1|max:100'
'page'                 => 'sometimes|integer|min:1'
```

### Resource-классы

#### `MediaResource` — формат ответа одного медиа

```json
{
  "id": 1,
  "file_name": "photo.jpg",
  "collection_name": "gallery",
  "disk": "s3-media",
  "mime_type": "image/jpeg",
  "size": 245760,
  "width": 1920,
  "height": 1080,
  "sort_order": 0,
  "created_at": "2025-10-22T18:47:21.000000Z",
  "updated_at": "...",
  "url": "https://s3.../media/product/123/gallery/photo.jpg",
  "thumbnail_url": "https://s3.../media/product/123/gallery/conversions/photo-thumb.jpg",
  "download_url": "/api/media/1/download",
  "category": { "id": 1, "name": "Фото товаров", "slug": "product-photos" },
  "tags": [{ "id": 5, "name": "Новинка", "slug": "new", "color": "#ff0000" }],
  "model": {
    "type": "Product",
    "id": 123,
    "name": "Красные туфли",
    "slug": "krasnye-tufli",
    "code": "P-001",
    "sku": "SKU123",
    "barcodes": ["4607028765432"]
  }
}
```

---

## Frontend (React / Inertia)

### Структура компонентов

```
resources/js/pages/Media/
├── Index.jsx              # Главная страница медиа-каталога
├── EntityMediaBlock.jsx   # Блок медиа одной сущности (товар/статья)
├── MediaCard.jsx          # Карточка отдельного медиа-файла (grid / list)
├── MediaGrid.jsx          # Сетка карточек
├── MediaLightbox.jsx      # Полноэкранный просмотр изображений
├── MediaFilters.jsx       # Панель фильтров (обёртка)
├── MediaPagination.jsx    # Пагинация
├── CatalogControl.jsx     # Контрол сортировки / вида / позиций
├── SelectedFilters.jsx    # Чипсы выбранных фильтров с крестиками
└── filters/
    ├── SearchFilter.jsx       # Поле текстового поиска
    ├── CategoryFilter.jsx     # Фильтр по категории
    ├── TagFilter.jsx          # Фильтр по тегам
    ├── FileTypeFilter.jsx     # Фильтр по типу файла (image/video/document)
    ├── DateFilter.jsx         # Фильтр по дате
    ├── SizeFilter.jsx         # Фильтр по размеру файла
    └── ModelTypeFilter.jsx    # Фильтр по типу сущности
```

### Главная страница `Index.jsx`

**Основной функционал:**
- Строка поиска с debounce (300мс) и иконкой очистки
- Чекбоксы фильтрации по типу сущности (Товары / Статьи), как минимум 1 выбран
- Синхронизация URL-параметров (`q`, `types`, `page`) через `history.replaceState`
- API-запрос к `/search` с параметрами `q`, `type=media`, `per_page=12`, `page`, `entity_types[]`
- Результаты группируются по сущностям — каждая сущность = отдельный `EntityMediaBlock`

**Пакетное скачивание:**
- `selectedMedia` — Set с id выбранных файлов
- Кнопки «Выделить все» / «Снять выделение» / «Скачать выделенные»
- POST-запрос к `/api/media/download-batch` с `{ ids: [...] }` → получает blob → создаёт ссылку → автоскачивание ZIP

**Lightbox:**
- Все медиа собираются в плоский массив `allMedia`
- При клике на изображение открывается `MediaLightbox` с правильным индексом

### `EntityMediaBlock.jsx` — блок сущности

- Заголовок: бейджи (Товар / Статья / Бренд / Категория), название как ссылка на сущность
- Для товаров: артикул (SKU), код товара, штрихкоды
- Кнопка «Скачать все медиа» в правом верхнем углу
- **Табы по категориям**: если у медиа файлов разные `category`, группировка по табам + таб «Без категории»
- Сетка: адаптивная от 2 до 6 колонок (`grid-cols-2 sm:3 md:4 lg:5 xl:6`)

### `MediaCard.jsx` — карточка медиа

**Два вида:**
- **Grid** — квадратная карточка (`aspect-square`) с:
  - Чекбокс выделения (левый верхний угол)
  - Кнопка скачивания (правый верхний, появляется при hover)
  - Имя файла и размер внизу
  - Размеры (ширина × высота), если доступны
  - Клик по изображению → открытие lightbox
- **List** — строчный вид с превью 64×64, именем, размером, датой, тегами и кнопкой скачивания

**Особенности:**
- Иконки по MIME-типу: `FileImage` / `FileVideo` / `FileText` / `File`
- Обработка ошибок загрузки изображений (`onError → imageError`)
- Подсветка выделенных элементов (синяя рамка / ring)

### `MediaLightbox.jsx` — полноэкранный просмотр

- **Portal** через `createPortal` в `document.body`
- Оверлей с `backdrop-blur`, блокировка прокрутки body
- Хедер: имя файла, размер, размеры, счётчик `N / M`
- Изображения: зум (от 50% до 300%, шаг 25%)
- Видео: встроенный `<video>` с `controls`
- Другие файлы: иконка + кнопка скачивания
- Навигация: стрелки по бокам + кнопки внизу
- Клавиатура: `← →` навигация, `Escape` закрытие, `+/−` зум
- Нижняя панель: информация (категория, теги, модель), дата загрузки, кнопка скачивания

### `CatalogControl.jsx` — контроллер сортировки/вида

- **Сортировка**: новые, старые, размер↑↓, имя A-Z / Z-A
- **Позиции на странице**: 12, 20, 24, 36, 48, 60, 72, 96, 100
- **Вид**: grid / list
- **Фильтры** (мобильная версия): открываются в `Sheet` (боковая панель)

### `MediaFilters.jsx` — панель фильтров

Компоненты фильтров:
1. `SearchFilter` — текстовое поле поиска
2. `ModelTypeFilter` — по типу сущности + фасеты (счётчики)
3. `CategoryFilter` — по категории медиа + фасеты
4. `FileTypeFilter` — по MIME-типу (image / video / application) + фасеты
5. `TagFilter` — по тегам + фасеты
6. `SizeFilter` — диапазон по размеру (МБ)
7. `DateFilter` — дата «от» / «до»

### `SelectedFilters.jsx` — чипсы выбранных фильтров

- Отображает бейджи всех активных фильтров (поиск, категории, теги, MIME, размер, даты)
- Каждый чип с крестиком для быстрого удаления
- Кнопка «Очистить все фильтры»

---

## Ключевые паттерны для реализации

### 1. Полиморфная привязка медиа
Медиа привязывается к любой модели через `morphs('model')`. Это позволяет одну таблицу `media` использовать для товаров, статей, баннеров и любых других сущностей.

### 2. Фасетированный поиск
Endpoint `/api/media/facets` возвращает динамические счётчики по каждому типу фильтра, что позволяет показывать количество результатов в каждом пункте фильтра и прятать пустые.

### 3. «Наследование» поисковых данных от родительской сущности
Модель `Media` в `toSearchableArray()` включает поля родительской модели (name, title, sku, code, barcodes), что позволяет находить медиа через поиск по данным товара.

### 4. Пакетное скачивание через ZipArchive
Массив id → `Media::whereIn('id', $ids)` → цикл по файлам → формирование ZIP в `storage/app/temp/` → `response()->download()->deleteFileAfterSend(true)`.

### 5. Конверсии (миниатюры)
Файл сохраняется в `media/{type}/{model_id}/{collection}/`, конверсии — в подпапке `conversions/` с суффиксом `-{conversion}.{ext}`. Размеры thumbnail настраиваются через маппинг в `MediaController::conversionsFor()`.

### 6. Дедупликация через SHA1
Уникальный индекс `(model_type, model_id, collection_name, sha1)` предотвращает загрузку одинаковых файлов в одну коллекцию.

### 7. Защита 18+ контента
Метод `getSafeUrl()` проверяет верификацию возраста и возвращает заблюренное изображение через специальный маршрут `/media/{media}/blurred/{conversion?}`.
