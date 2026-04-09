---
description: Подготовка dev-сервера к ручному тестированию интеграции 1С ↔ Сайт. Создаёт справочники, проверяет инфраструктуру (RabbitMQ, MinIO, воркеры).
---

// turbo-all

# Подготовка к интеграционному тестированию 1С ↔ Сайт

> Этот workflow подготавливает dev-сервер к ручному тестированию интеграции по плану из `docs/INTEGRATION_TEST_PLAN.md`.
> На сервере предполагается чистая БД с единственным пользователем — админом.

---

## Шаг 0.1 — Проверка RabbitMQ топологии

Проверь что RabbitMQ exchanges и очереди созданы. Выполни команду setup:

```bash
docker exec pecado-app php artisan rabbitmq:setup
```

Затем проверь что топология создана — выведи список очередей:

```bash
docker exec pecado-rabbitmq rabbitmqctl list_queues name messages consumers
```

**Ожидаемый результат:** Должны существовать очереди:
- Входящие: `erp_in.partners`, `erp_in.prices`, `erp_in.stock`, `erp_in.orders`, `erp_in.returns`, `erp_in.documents`, `erp_in.balance`, `erp_in.catalog`
- Исходящие: `erp_out.orders`, `erp_out.returns`, `erp_out.partners`
- DLQ: `erp_dlq.partners`, `erp_dlq.prices`, `erp_dlq.stock`, `erp_dlq.orders`, `erp_dlq.returns`, `erp_dlq.balance`

Если каких-то очередей нет — зафиксируй проблему.

---

## Шаг 0.2 — Проверка exchanges

```bash
docker exec pecado-rabbitmq rabbitmqctl list_exchanges name type
```

**Ожидаемый результат:** Должны существовать exchanges:
- `erp.events` (type: topic)
- `site.events` (type: topic)

---

## Шаг 0.3 — Проверка Supervisor воркеров

```bash
docker exec pecado-worker supervisorctl status
```

**Ожидаемый результат:** Все `erp-*-consumer` процессы в статусе `RUNNING`.

Если не запущены:

```bash
docker exec pecado-worker supervisorctl restart all
```

---

## Шаг 0.4 — Проверка MinIO (бакет для индивидуальных цен)

С помощью tinker проверь, что S3-соединение работает:

```php
docker exec pecado-app php artisan tinker --execute="
try {
    \$disk = Storage::disk('s3');
    \$dirs = \$disk->directories('/');
    echo 'S3 подключение: OK' . PHP_EOL;
    echo 'Директории: ' . implode(', ', \$dirs) . PHP_EOL;
} catch (\Throwable \$e) {
    echo 'S3 ОШИБКА: ' . \$e->getMessage() . PHP_EOL;
}
"
```

---

## Шаг 0.5 — Создание регионов

Создай 2 тестовых региона через tinker:

```php
docker exec pecado-app php artisan tinker --execute="
use App\Models\Region;

\$regions = [
    ['name' => 'Москва'],
    ['name' => 'Минск'],
    ['name' => 'Астана'],
];

foreach (\$regions as \$data) {
    \$region = Region::firstOrCreate(['name' => \$data['name']]);
    echo \"Регион: {\$region->name} (id={\$region->id})\" . PHP_EOL;
}
"
```

---

## Шаг 0.6 — Создание складов

Создай 2 склада с фиксированными UUID (external_id), которые потом передаются 1С-нику:

```php
docker exec pecado-app php artisan tinker --execute="
use App\Models\Warehouse;

\$warehouses = [
    ['name' => 'Москва основной', 'external_id' => '40301d16-3847-11e1-8034-001e6711ed1d'],
    ['name' => 'Москва предзаказы',            'external_id' => '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce'],
];

foreach (\$warehouses as \$data) {
    \$wh = Warehouse::firstOrCreate(
        ['external_id' => \$data['external_id']],
        ['name' => \$data['name']]
    );
    echo \"Склад: {\$wh->name} | external_id={\$wh->external_id} (id={\$wh->id})\" . PHP_EOL;
}
"
```

---

## Шаг 0.7 — Привязка складов к регионам

Привяжи Москва основной склад как `primary`, склад Москва предзаказы как `preorder` к региону «Москва»:

```php
docker exec pecado-app php artisan tinker --execute="
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

\$region = Region::where('name', 'Москва')->first();
\$whPrimary = Warehouse::where('external_id', '40301d16-3847-11e1-8034-001e6711ed1d')->first();
\$whPreorder = Warehouse::where('external_id', '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce')->first();

if (!\$region || !\$whPrimary || !\$whPreorder) {
    echo 'ОШИБКА: регион или склады не найдены!' . PHP_EOL;
    return;
}

// primary
DB::table('region_warehouse')->updateOrInsert(
    ['region_id' => \$region->id, 'warehouse_id' => \$whPrimary->id, 'type' => 'primary'],
    ['created_at' => now(), 'updated_at' => now()]
);
echo \"Привязка: {\$region->name} <-> {\$whPrimary->name} (primary)\" . PHP_EOL;

// preorder
DB::table('region_warehouse')->updateOrInsert(
    ['region_id' => \$region->id, 'warehouse_id' => \$whPreorder->id, 'type' => 'preorder'],
    ['created_at' => now(), 'updated_at' => now()]
);
echo \"Привязка: {\$region->name} <-> {\$whPreorder->name} (preorder)\" . PHP_EOL;
"
```

---

## Шаг 0.8 — Создание статусов клиентов

Создай 4 клиентских статуса с `external_id`, которые используются в `partner.created` → `client_status`:

```php
docker exec pecado-app php artisan tinker --execute="
use App\Models\ClientStatus;

\$statuses = [
    ['name' => 'Silver',         'external_id' => 'silver',     'color' => '#C0C0C0', 'description' => 'Скидка 10%', 'amount_from' => 0],
    ['name' => 'Gold',           'external_id' => 'gold',       'color' => '#FFD700', 'description' => 'Скидка 15%', 'amount_from' => 0],
    ['name' => 'Diamond',        'external_id' => 'diamond',    'color' => '#B9F2FF', 'description' => 'Скидка 20%', 'amount_from' => 0],
    ['name' => 'Индивидуальный', 'external_id' => 'individual', 'color' => '#9B59B6', 'description' => 'Индивидуальные условия', 'amount_from' => 0],
];

foreach (\$statuses as \$data) {
    \$status = ClientStatus::firstOrCreate(
        ['external_id' => \$data['external_id']],
        \$data
    );
    echo \"Статус: {\$status->name} | external_id={\$status->external_id} (id={\$status->id})\" . PHP_EOL;
}
"
```

---

## Шаг 0.9 — Итоговая проверка данных

Выведи сводную таблицу всех созданных справочников:

```php
docker exec pecado-app php artisan tinker --execute="
use App\Models\Region;
use App\Models\Warehouse;
use App\Models\ClientStatus;
use Illuminate\Support\Facades\DB;

echo '=== РЕГИОНЫ ===' . PHP_EOL;
Region::all()->each(fn(\$r) => print(\"  id={\$r->id} | {\$r->name}\" . PHP_EOL));

echo PHP_EOL . '=== СКЛАДЫ ===' . PHP_EOL;
Warehouse::all()->each(fn(\$w) => print(\"  id={\$w->id} | {\$w->name} | external_id={\$w->external_id}\" . PHP_EOL));

echo PHP_EOL . '=== ПРИВЯЗКИ СКЛАД-РЕГИОН ===' . PHP_EOL;
DB::table('region_warehouse')
    ->join('regions', 'regions.id', '=', 'region_warehouse.region_id')
    ->join('warehouses', 'warehouses.id', '=', 'region_warehouse.warehouse_id')
    ->select('regions.name as region', 'warehouses.name as warehouse', 'region_warehouse.type')
    ->get()
    ->each(fn(\$rw) => print(\"  {\$rw->region} <-> {\$rw->warehouse} ({\$rw->type})\" . PHP_EOL));

echo PHP_EOL . '=== СТАТУСЫ КЛИЕНТОВ ===' . PHP_EOL;
ClientStatus::all()->each(fn(\$s) => print(\"  id={\$s->id} | {\$s->name} | external_id={\$s->external_id} | color={\$s->color}\" . PHP_EOL));

echo PHP_EOL . '=== UUID СКЛАДОВ ДЛЯ 1С-НИКА ===' . PHP_EOL;
echo 'Передайте 1С-нику следующие UUID для payload:' . PHP_EOL;
Warehouse::all()->each(fn(\$w) => print(\"  {\$w->name}: {\$w->external_id}\" . PHP_EOL));
"
```

---

## Шаг 0.10 — Финальный отчёт

После выполнения всех шагов выведи пользователю:

1. **Статус инфраструктуры:** RabbitMQ (exchanges, очереди, DLQ), воркеры, MinIO
2. **Созданные справочники:** регионы, склады, привязки, статусы клиентов
3. **UUID складов для 1С-ника** — эти значения нужно передать 1С-разработчику для формирования тестовых payload
4. **Готовность к тестированию** — всё ли прошло успешно или есть проблемы

Ориентируйся на план тестирования: `docs/INTEGRATION_TEST_PLAN.md`