# Roadmap оптимизации individual_prices (v7.0 → v7.1)

**Дата:** 2026-03-27  
**Контекст:** Нагрузочный тест 24M строк (600 × 10K × 4) показал:

| Метрика | v7.0 (текущая) | Цель v7.1 |
|---|---|---|
| INSERT скорость | ~3-4K r/s | ~20-40K r/s |
| JSONL размер (24M строк) | 3.3 GB | ~1.2 GB (CSV) |
| Размер таблицы на диске | ~6 GB | ~1 GB |
| Время полного импорта 24M | ~100 мин | ~10-15 мин |

---

## Фаза 1: Удаление избыточных индексов

**Усилие:** минимальное | **Эффект:** −40% overhead на INSERT

Текущий запрос `loadIndividualPriceMap()`:
```sql
SELECT product_uuid, price
FROM individual_prices
WHERE partner_uuid = ? AND product_uuid IN (...)
```

Composite PK `(partner_uuid, product_uuid, warehouse_uuid)` уже покрывает этот запрос (leftmost prefix). Два secondary индекса **избыточны**:

| Индекс | Статус | Причина |
|---|---|---|
| `idx_individual_prices_partner` | ❌ Удалить | Дублирует prefix PK |
| `idx_individual_prices_product` | ❌ Удалить | Нет запросов WHERE product_uuid без partner_uuid |

### Миграция
```php
Schema::table('individual_prices', function (Blueprint $table) {
    $table->dropIndex('idx_individual_prices_partner');
    $table->dropIndex('idx_individual_prices_product');
});
```

---

## Фаза 2: UUID → INT (lookup при импорте)

**Усилие:** среднее | **Эффект:** 7-10x INSERT, 3-5x SELECT, 6x меньше диск

### Что меняется

**Таблица `individual_prices`:**

```diff
- partner_uuid   CHAR(36)
- product_uuid   CHAR(36)
- warehouse_uuid CHAR(36)
+ partner_id     INT UNSIGNED    -- FK → users.id
+ product_id     INT UNSIGNED    -- FK → products.id
+ warehouse_id   INT UNSIGNED    -- FK → warehouses.id
  price          DECIMAL(15,2)
- PRIMARY KEY (partner_uuid, product_uuid, warehouse_uuid)
+ PRIMARY KEY (partner_id, product_id, warehouse_id)
```

**ProcessIndividualPricesFile (handler):**

```php
// Загружаем маппинг UUID → INT один раз (в память)
$partnerMap   = User::whereNotNull('erp_id')->pluck('id', 'erp_id');      // 600 строк
$productMap   = Product::whereNotNull('external_id')->pluck('id', 'external_id'); // 10K строк
$warehouseMap = Warehouse::whereNotNull('external_id')->pluck('id', 'external_id'); // 4 строки

// При чтении каждой строки CSV/JSONL
$partnerId   = $partnerMap[$row['partner_uuid']] ?? null;
$productId   = $productMap[$row['product_uuid']] ?? null;
$warehouseId = $warehouseMap[$row['warehouse_uuid']] ?? null;

if (!$partnerId || !$productId || !$warehouseId) {
    // Логируем и пропускаем — UUID не найден в наших таблицах
    continue;
}

// INSERT с числовыми ID
$batch[] = "({$partnerId},{$productId},{$warehouseId},{$row['price']})";
```

**ProductQueryService::loadIndividualPriceMap():**

```diff
- $prices = DB::table('individual_prices')
-     ->where('partner_uuid', $user->erp_id)
-     ->whereIn('product_uuid', array_keys($externalIdMap))
-     ->select('product_uuid', 'price')
-     ->get();
+ // Получаем product IDs напрямую (уже числовые)
+ $productIds = array_values($externalIdMap);
+ $prices = DB::table('individual_prices')
+     ->where('partner_id', $user->id)
+     ->whereIn('product_id', $productIds)
+     ->select('product_id', 'price')
+     ->get();
```

### Влияние на 1С

**Никакого.** 1С по-прежнему шлёт UUID в файле. Резолвинг UUID → INT происходит на стороне Laravel при импорте.

### Миграция данных

```php
// 1. Создать новую таблицу
Schema::create('individual_prices_v2', function (Blueprint $table) {
    $table->unsignedInteger('partner_id');
    $table->unsignedInteger('product_id');
    $table->unsignedInteger('warehouse_id');
    $table->decimal('price', 15, 2);
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    $table->primary(['partner_id', 'product_id', 'warehouse_id']);
});

// 2. Мигрировать данные (если есть)
DB::statement('INSERT INTO individual_prices_v2 (partner_id, product_id, warehouse_id, price)
    SELECT u.id, p.id, w.id, ip.price
    FROM individual_prices ip
    JOIN users u ON u.erp_id = ip.partner_uuid
    JOIN products p ON p.external_id = ip.product_uuid
    JOIN warehouses w ON w.external_id = ip.warehouse_uuid');

// 3. Переименовать
Schema::rename('individual_prices', 'individual_prices_old');
Schema::rename('individual_prices_v2', 'individual_prices');
Schema::dropIfExists('individual_prices_old');
```

---

## Фаза 3: JSONL → CSV

**Усилие:** минимальное (на стороне Laravel) + согласование с 1С | **Эффект:** −40% размер файла, быстрее парсинг

### Формат файла (согласовать с 1С)

```csv
partner_uuid,product_uuid,warehouse_uuid,price
a1b2c3d4-...,f9e8d7c6-...,11223344-...,1500.50
```

- Без заголовка (или опционально с заголовком)
- Разделитель: запятая
- Кодировка: UTF-8
- Без кавычек (UUID и числа не требуют экранирования)

### Изменения в ACCEPTANCE_CRITERIA_v7.md

Раздел **US-14 → Формат файла данных:**

```diff
- ### Формат файла данных (JSONL)
- Файл в формате JSON Lines (каждая строка — валидный JSON):
- {"partner_uuid":"...","warehouse_uuid":"...","product_uuid":"...","price":1250.00}
+ ### Формат файла данных (CSV)
+ Файл в формате CSV (без заголовка, разделитель — запятая):
+ a1b2c3d4-...,f9e8d7c6-...,11223344-...,1500.50
+ Порядок колонок: partner_uuid, product_uuid, warehouse_uuid, price
```

Раздел **US-14 → Обработка на стороне сайта:**

```diff
- 3. Job скачивает JSONL из MinIO, читает потоково (генераторы PHP), батч-вставка
+ 3. Job скачивает CSV из MinIO, читает потоково (`fgetcsv`), резолвит UUID→INT, батч-вставка
```

---

## Фаза 4: Партиционирование файлов (опционально)

**Усилие:** среднее | **Эффект:** параллелизм, устойчивость к ошибкам

Вместо одного файла 1-3 GB — файл на каждого партнёра:

```
prices-exchange/2026-03-27/partner_a1b2c3d4.csv   (~40K строк, ~5 MB)
prices-exchange/2026-03-27/partner_f9e8d7c6.csv
...
```

RabbitMQ уведомление:
```json
{
  "event": "individual_prices.ready",
  "upload_type": "delta",
  "file_url": "s3://prices-exchange/2026-03-27/partner_a1b2c3d4.csv",
  "partner_uuid": "a1b2c3d4-...",
  "records_count": 40000
}
```

Преимущества:
- Каждый файл ~5 MB → обработка < 5 сек
- Можно запускать параллельно несколько worker'ов
- Ошибка в одном файле не влияет на остальных
- `DELETE WHERE partner_id = ? → INSERT` вместо `TRUNCATE` всей таблицы

---

## Сводная таблица

| Фаза | Что | Усилие | Эффект | Зависит от 1С |
|---|---|---|---|---|
| 1 | Удалить 2 индекса | 5 мин | −40% INSERT overhead | Нет |
| 2 | UUID → INT | 1-2 дня | 7-10x INSERT, 3-5x SELECT | Нет |
| 3 | JSONL → CSV | 2 часа (Laravel) | −40% файл, быстрее парсинг | **Да** |
| 4 | Файлы по партнёрам | 4 часа | Параллелизм, устойчивость | **Да** |

### Рекомендуемый порядок

1. **Фаза 1** — сразу (без рисков, без зависимостей)
2. **Фаза 2** — следующий спринт (максимальный эффект, без 1С)
3. **Фазы 3-4** — когда 1С будет готова к изменению формата
