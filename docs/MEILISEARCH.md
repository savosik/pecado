# Meilisearch — поиск товаров

## Что это и зачем

Meilisearch — поисковый движок, используется для полнотекстового (и опционально семантического) поиска товаров, категорий и брендов на сайте. Интегрирован через Laravel Scout.

Индексы:

| Индекс | Модель | Searchable-поля |
|--------|--------|-----------------|
| `products` | `Product` | name, brand, category, sku, code, barcodes, description + транслиты/раскладки |
| `categories` | `Category` | name + транслиты/раскладки |
| `brands` | `Brand` | name + транслиты/раскладки |

Товар попадает в индекс только если `hidden = false` (`shouldBeSearchable()`).

---

## Семантический (гибридный) поиск

Meilisearch поддерживает гибридный поиск: полнотекст + векторный по смыслу. Управляется через `.env`:

```env
SEARCH_HYBRID_ENABLED=true          # включить гибридный поиск
SEARCH_SEMANTIC_RATIO=0.3           # 30% семантика, 70% полнотекст
MEILISEARCH_EMBEDDER_URL=...        # URL OpenRouter API
MEILISEARCH_EMBEDDER_MODEL=openai/text-embedding-3-large
MEILISEARCH_EMBEDDER_DIMENSIONS=3072
OPENROUTER_API_KEY=sk-or-...
```

При `SEARCH_HYBRID_ENABLED=false` работает только полнотекстовый поиск.

### Как включить обратно после пополнения кредитов OpenRouter

1. Восстановить embedder в настройках индекса Meilisearch:
   ```bash
   docker exec pecado-app php artisan meilisearch:configure-embedders
   ```
2. Включить в `.env` на сервере:
   ```bash
   docker exec pecado-app sed -i 's/SEARCH_HYBRID_ENABLED=false/SEARCH_HYBRID_ENABLED=true/' /var/www/.env
   docker exec pecado-app php artisan config:clear
   docker exec pecado-worker php artisan config:clear
   ```
3. Переиндексировать — Meilisearch сгенерирует эмбеддинги:
   ```bash
   docker exec pecado-app php artisan search:sync
   ```
   Это займёт значительно дольше обычного — каждый батч обращается к OpenRouter API.

> **Важно:** кредиты OpenRouter списываются при каждой индексации товара (добавлении/обновлении). При ~9500 товаров с моделью `text-embedding-3-large` (3072 dim) один полный переиндекс стоит ощутимо. Следи за балансом на https://openrouter.ai/settings/credits

---

## Синхронизация индексов — команда search:sync

Команда `search:sync` выполняет upsert всех текущих записей в индекс и удаляет устаревшие документы (от удалённых товаров) без downtime.

```bash
# Полная синхронизация всех индексов
docker exec pecado-app php artisan search:sync

# Только одна модель
docker exec pecado-app php artisan search:sync --model=products

# Только stale cleanup, без reimport
docker exec pecado-app php artisan search:sync --skip-import

# Превью без изменений
docker exec pecado-app php artisan search:sync --dry-run
```

**Расписание:** автоматически каждые 3 дня в 03:00 (планировщик Laravel, `routes/console.php`).

---

## Известные проблемы

### Поиск работает, но возвращает 0 результатов

**Симптом:** Meilisearch находит хиты напрямую через API, но Scout возвращает пустую коллекцию.

**Причина:** В индексе накопились устаревшие ID (от ранее удалённых товаров). Meilisearch возвращает эти ID в топе результатов, Scout ищет их в БД — не находит.

**Лечение:**
```bash
docker exec pecado-app php artisan search:sync --model=products
```

### Поиск не работает, в логах — ошибка embedder 402

**Симптом:** `SearchController` ловит исключение, возвращает пустой массив.

**Причина:** `SEARCH_HYBRID_ENABLED=true`, но у OpenRouter кончились кредиты. Meilisearch отвечает: `"Insufficient credits"`.

**Лечение (быстрое):** выключить гибридный поиск до пополнения кредитов:
```bash
docker exec pecado-app sed -i 's/SEARCH_HYBRID_ENABLED=true/SEARCH_HYBRID_ENABLED=false/' /var/www/.env
docker exec pecado-app php artisan config:clear
docker exec pecado-worker php artisan config:clear
```
Если embedder уже успел сломать индекс (все новые документы упали при индексации):
```bash
# Удалить embedder из настроек индекса
docker exec pecado-app curl -s -X DELETE \
  -H 'Authorization: Bearer masterKey123' \
  'http://meilisearch:7700/indexes/products/settings/embedders'

# Переиндексировать без embeddings
docker exec pecado-app php artisan search:sync --model=products
```

---

## Диагностика

```bash
# Статистика индексов
docker exec pecado-app curl -s -H 'Authorization: Bearer masterKey123' \
  'http://meilisearch:7700/indexes/products/stats'

# Последние задачи (проверить статус failed/succeeded)
docker exec pecado-app curl -s -H 'Authorization: Bearer masterKey123' \
  'http://meilisearch:7700/tasks?limit=10&indexUids=products'

# Проверить количество документов vs БД
docker exec pecado-app php artisan search:sync --dry-run --skip-import

# Прямой поиск через Meilisearch API (минуя Scout)
docker exec pecado-app curl -s -X POST \
  -H 'Authorization: Bearer masterKey123' \
  -H 'Content-Type: application/json' \
  -d '{"q": "сатисфаер", "limit": 5}' \
  'http://meilisearch:7700/indexes/products/search'
```
