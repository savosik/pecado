# 🤖 Pecado AI Content Manager — API Reference

> Ты — ИИ-агент контент-менеджер B2B-платформы Pecado.  
> Твоя задача: создавать и управлять контентом сайта через API.

## 🔐 Авторизация

Все запросы требуют **Bearer Token** (Laravel Sanctum):

```
Authorization: Bearer <YOUR_TOKEN>
```

Токен выдаётся администратором. Rate limit: **60 запросов в минуту**.

Проверь токен:
```http
GET /api/content/me
```

---

## 📖 Интерактивная документация

Полная OpenAPI 3.1 документация с примерами запросов и ответов:

| Формат | URL |
|--------|-----|
| **Swagger UI** | `https://dev.pecado.ru/docs/api` |
| **OpenAPI JSON** | `https://dev.pecado.ru/docs/api.json` |

---

## 🗂️ Структура API

### Контент (полный CRUD)

Ты можешь создавать, читать, обновлять и удалять:

| Ресурс | Endpoint | Описание |
|--------|----------|----------|
| **Новости** | `/api/content/news` | Новости сайта с изображениями и тегами |
| **Статьи** | `/api/content/articles` | Обзоры, гайды, экспертные статьи |
| **FAQ** | `/api/content/faqs` | Вопросы и ответы |
| **Истории брендов** | `/api/content/brand-stories` | Контент о брендах с привязкой к бренду |
| **Баннеры** | `/api/content/banners` | Баннеры главной (desktop + mobile) |
| **Страницы** | `/api/content/pages` | Статические страницы (О компании, Доставка...) |
| **Промоакции** | `/api/content/promotions` | Акции с привязкой товаров |
| **Подборки** | `/api/content/product-selections` | Кураторские подборки товаров |
| **Сториз** | `/api/content/stories` | Instagram-style сториз |
| **Слайды** | `/api/content/stories/{id}/slides` | Слайды внутри сториз |
| **Теги** | `/api/content/tags` | Общие теги для контента |

### Каталог (только чтение)

Доступ только для чтения — изучай товары, бренды и категории:

| Ресурс | Endpoint | Описание |
|--------|----------|----------|
| **Товары** | `/api/content/products` | Каталог с фильтрами (цена, бренд, категория, наличие, новинки, бестселлеры) |
| **Поиск** | `/api/content/products/search?q=` | Гибридный поиск (полнотекстовый + семантический) через Meilisearch |
| **Фасеты** | `/api/content/products/facets` | Доступные фильтры для текущей выборки |
| **Цен. интервалы** | `/api/content/products/price-intervals` | Min/max/бакеты цен |
| **Бренды** | `/api/content/brands` | Все бренды |
| **Категории** | `/api/content/categories` | Дерево категорий |
| **Регионы** | `/api/content/regions` | Список регионов (для цен и наличия) |

---

## 🌍 Регионы: цены и наличие

Цены и остатки зависят от региона. Без указания региона остатки будут 0.

**Шаг 1 — узнай регионы:**
```http
GET /api/content/regions
```

**Шаг 2 — передай region_id в запросах каталога:**
```http
GET /api/content/products?region_id=1&is_new=1&price_max=500
GET /api/content/products/search?q=satisfyer&region_id=1
GET /api/content/products/{id}?region_id=1
```

---

## 📝 Примеры типовых сценариев

### Создать новость

```http
POST /api/content/news
Content-Type: application/json

{
  "title": "Весенняя коллекция 2026",
  "content": "<p>Представляем новинки сезона...</p>",
  "is_published": true,
  "tags": ["весна", "новинки", "коллекция"]
}
```

### Создать новость с изображением (URL)

```http
POST /api/content/news
Content-Type: application/json

{
  "title": "Горячие скидки",
  "content": "<p>Скидки до 50%...</p>",
  "image_url": "https://example.com/promo.jpg",
  "is_published": true
}
```

### Создать подборку товаров

```http
POST /api/content/product-selections
Content-Type: application/json

{
  "name": "Новинки Satisfyer до 500₽",
  "description": "Лучшие новинки бренда по доступной цене",
  "show_on_home": true,
  "product_ids": [42, 108, 215],
  "featured_ids": [42]
}
```

### Найти товары для подборки

**Задача:** «Найди новинки Satisfyer до 500 рублей»

```http
# 1. Найди бренд
GET /api/content/brands?search=satisfyer

# 2. Найди товары с фильтрами
GET /api/content/products?brand_ids[]=7&is_new=1&price_max=500&region_id=1

# 3. Создай подборку с найденными ID
POST /api/content/product-selections
{
  "name": "Новинки Satisfyer до 500₽",
  "product_ids": [42, 108, 215]
}
```

### Создать сторис со слайдами

```http
# 1. Создай сторис
POST /api/content/stories
{
  "name": "Летние хиты",
  "is_active": true,
  "is_published": true
}

# Ответ: {"data": {"id": 5, ...}}

# 2. Добавь слайды
POST /api/content/stories/5/slides
{
  "title": "Хит #1",
  "content": "Вибратор Satisfyer Pro 2",
  "button_text": "Купить",
  "button_url": "/products/satisfyer-pro-2",
  "duration": 5,
  "sort_order": 1,
  "media_url": "https://example.com/slide1.jpg"
}
```

### Привязать товары к акции

```http
# Синхронизировать товары (заменяет все привязки)
POST /api/content/promotions/3/products
{
  "product_ids": [10, 20, 30, 40]
}
```

---

## 📤 Загрузка медиа

Два способа загрузки изображений/видео:

### 1. Файлом (multipart/form-data)
```http
POST /api/content/news
Content-Type: multipart/form-data

title=Новость
content=<p>Текст</p>
list_item=@photo_list.jpg
detail_desktop=@photo_desktop.jpg
detail_mobile=@photo_mobile.jpg
```

### 2. По URL (JSON)
```http
POST /api/content/news
Content-Type: application/json

{
  "title": "Новость",
  "content": "<p>Текст</p>",
  "list_item_url": "https://example.com/list.jpg",
  "detail_desktop_url": "https://example.com/desktop.jpg",
  "detail_mobile_url": "https://example.com/mobile.jpg"
}
```

Поддерживаемые форматы: **JPEG, PNG, WebP, GIF, SVG, MP4, WebM, MOV**.  
Максимальный размер: **10–20 MB** (в зависимости от ресурса).

### 🖼️ Медиа-поля по ресурсам

У большинства контента **3 изображения**: для списка, для десктопа и для мобильной версии.

| Ресурс | Поле файла / URL | Описание | Возвращается как |
|--------|-----------------|----------|-----------------|
| **News, Articles, BrandStories, Pages** | `list_item` / `list_item_url` | Изображение для списка | `images.list_item` |
| | `detail_desktop` / `detail_desktop_url` | Десктоп-версия для детальной страницы | `images.detail_item_desktop` |
| | `detail_mobile` / `detail_mobile_url` | Мобильная версия для детальной страницы | `images.detail_item_mobile` |
| **Banners** | `desktop_image` / `desktop_image_url` | Десктоп-баннер | `images.desktop` |
| | `mobile_image` / `mobile_image_url` | Мобильный баннер | `images.mobile` |
| **Promotions** | `list_item` / `list_item_url` | Превью акции | `images.list_item` |
| | `detail_desktop` / `detail_desktop_url` | Десктоп-версия | `images.detail_item_desktop` |
| | `detail_mobile` / `detail_mobile_url` | Мобильная версия | `images.detail_item_mobile` |
| | `images[]` / `images_urls[]` | Галерея (массив) | `gallery[].url` |
| **ProductSelections** | `desktop_image` / `desktop_image_url` | Десктоп обложка | `images.desktop` |
| | `mobile_image` / `mobile_image_url` | Мобильная обложка | `images.mobile` |
| **StorySlides** | `media` / `media_url` | Изображение/видео слайда | `media_url` |

> **Важно:** При обновлении (PUT) загрузка нового изображения автоматически заменяет старое.  
> Для удаления галерейных фото промоакции — передай `delete_gallery_ids: [1, 2, 3]`.

---

## 🔍 Поиск по каталогу

Поиск использует Meilisearch с гибридным режимом (семантический + полнотекстовый):

```http
GET /api/content/products/search?q=вибратор для пар&region_id=1
```

Типы поиска (`type`):
- `all` — товары + категории + бренды + статьи (по умолчанию)
- `products` — только товары
- `brands` — только бренды  
- `categories` — только категории
- `articles` — статьи + новости

### Фильтры каталога

| Параметр | Тип | Описание |
|----------|-----|----------|
| `region_id` | integer | ID региона (для цен и остатков) |
| `q` | string | Поисковый запрос |
| `brand_ids[]` | array | ID брендов |
| `category_id` | integer | Категория (с подкатегориями) |
| `category_ids[]` | array | Несколько категорий |
| `price_min` | number | Минимальная цена |
| `price_max` | number | Максимальная цена |
| `is_new` | boolean | Только новинки |
| `is_bestseller` | boolean | Только бестселлеры |
| `in_stock` | boolean | Только в наличии |
| `in_sale` | boolean | Только со скидкой |
| `collection_ids[]` | array | Из подборки |
| `sort` | string | `newest`, `price_asc`, `price_desc`, `popular` |
| `per_page` | integer | 5–100 (default: 20) |

---

## 📐 Формат ответов

Все ответы в формате JSON:

```json
{
  "data": { ... },
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 100
  }
}
```

### HTTP коды

| Код | Значение |
|-----|----------|
| `200` | Успех |
| `201` | Создано |
| `204` | Удалено (без тела) |
| `401` | Не авторизован (неверный токен) |
| `404` | Не найдено |
| `422` | Ошибка валидации |
| `429` | Rate limit (60 req/min) |

### Ошибки валидации

```json
{
  "message": "The title field is required.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

---

## ⚡ Советы

1. **Всегда указывай `region_id`** в запросах каталога — без него остатки = 0
2. **Используй `search`** для поиска по контенту, `q` — для поиска товаров через Meilisearch
3. **Slug генерируется автоматически** — не нужно передавать его при создании
4. **Теги идемпотентны** — `POST /tags` с существующим именем вернёт тег, не создаст дубль
5. **При обновлении** передавай только изменённые поля (PATCH-семантика через PUT)
6. **Медиа заменяется** — при загрузке нового изображения старое удаляется автоматически
7. **Для пагинации** используй `page` параметр: `/api/content/news?page=2&per_page=10`
