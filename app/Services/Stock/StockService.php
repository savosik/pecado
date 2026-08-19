<?php

namespace App\Services\Stock;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка SQL остатков витрины (buf-03).
 *
 * До рефакторинга остатки считались двумя независимо выросшими ветками:
 * батч-карты здесь (корзина, чекаут, экспорт, client-api) и подзапросы
 * `ProductQueryService::withRegionStockSums` (каталог, поиск, избранное,
 * похожие, главная). Теперь и карты, и подзапросы строятся здесь —
 * страховой буфер (buf-04) врежется в одно место, а не в пять.
 *
 * Экземпляр скоуплен на запрос (`AppServiceProvider`): карта складов региона
 * мемоизируется, поэтому карточка товара с вариантами и похожими резолвит
 * регион один раз, а не три.
 */
class StockService implements StockServiceInterface
{
    /**
     * Мемо складов по региону на время запроса: region_id → списки складов.
     * primary отсортирован по позиции в стопке (priority, NULL — в конце);
     * stack — включён ли у региона режим стопки (строгое замещение).
     *
     * @var array<int, array{primary: list<int>, preorder: list<int>, stack: bool}>
     */
    private array $regionWarehouses = [];

    /**
     * Мемо выигравших складов в режиме стопки: region_id → product_id → warehouse_id.
     * null — товара нет в наличии ни на одном складе стопки.
     *
     * @var array<int, array<int, int|null>>
     */
    private array $winnerCache = [];

    /**
     * Мемо региона по умолчанию: Region::defaultId() дёргается для каждого
     * гостя, а меняется — никогда в пределах запроса.
     */
    private ?int $defaultRegionId = null;

    private bool $defaultRegionResolved = false;

    /**
     * Get the stock information for a product for a specific user.
     * Returns array with 'available' (from primary warehouses) and 'preorder' (from preorder warehouses) quantities.
     *
     * @return array{available: int, preorder: int}
     */
    public function getStock(Product $product, ?User $user = null): array
    {
        $maps = $this->getStockMaps([$product], $user);

        return [
            'available' => $maps['available'][$product->id] ?? 0,
            'preorder' => $maps['preorder'][$product->id] ?? 0,
        ];
    }

    /**
     * Get the available stock quantity for a product for a specific user.
     * This is the sum of stock from all primary warehouses in the user's region.
     */
    public function getAvailableStock(Product $product, ?User $user = null): int
    {
        return $this->getStock($product, $user)['available'];
    }

    /**
     * Get the preorder stock quantity for a product for a specific user.
     * This is the sum of stock from all preorder warehouses in the user's region.
     */
    public function getPreorderStock(Product $product, ?User $user = null): int
    {
        return $this->getStock($product, $user)['preorder'];
    }

    /**
     * Карта доступных остатков для коллекции товаров.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getAvailableStockMap(iterable $products, ?User $user = null): array
    {
        return $this->getStockMaps($products, $user)['available'];
    }

    /**
     * Симметричная getAvailableStockMap карта по preorder-складам.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getPreorderStockMap(iterable $products, ?User $user = null): array
    {
        return $this->getStockMaps($products, $user)['preorder'];
    }

    /**
     * Комбинированная карта остатков: оба типа складов за один запрос
     * к product_warehouse (+ мемоизированный резолв складов региона).
     *
     * Всегда предпочитайте её паре getAvailableStockMap + getPreorderStockMap:
     * порознь они дважды ходят за product_warehouse.
     *
     * @param  iterable<Product>  $products
     * @return array{available: array<int, int>, preorder: array<int, int>}
     */
    public function getStockMaps(iterable $products, ?User $user = null): array
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = (int) $product->id;
        }

        return $this->getStockMapsByIds($ids, $user);
    }

    /**
     * Вариант getStockMaps по голым ID — для товаров из кеша (главная,
     * подборки), где моделей нет.
     *
     * @param  list<int>  $productIds
     * @return array{available: array<int, int>, preorder: array<int, int>}
     */
    public function getStockMapsByIds(array $productIds, ?User $user = null): array
    {
        $available = [];
        $preorder = [];
        foreach ($productIds as $id) {
            $available[(int) $id] = 0;
            $preorder[(int) $id] = 0;
        }

        if ($available === []) {
            return ['available' => [], 'preorder' => []];
        }

        $warehouses = $this->regionWarehouseIds($user);
        $allWarehouseIds = array_merge($warehouses['primary'], $warehouses['preorder']);

        if ($allWarehouseIds === []) {
            return ['available' => $available, 'preorder' => $preorder];
        }

        $rows = DB::table('product_warehouse')
            ->whereIn('warehouse_id', $allWarehouseIds)
            ->whereIn('product_id', array_keys($available))
            ->select('product_id', 'warehouse_id', 'quantity')
            ->get();

        $primaryIds = array_flip($warehouses['primary']);
        $preorderIds = array_flip($warehouses['preorder']);

        $primaryByProduct = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $warehouseId = (int) $row->warehouse_id;

            if (isset($primaryIds[$warehouseId])) {
                if ($warehouses['stack']) {
                    $primaryByProduct[$productId][$warehouseId] = (int) $row->quantity;
                } else {
                    $available[$productId] += (int) $row->quantity;
                }
            }

            if (isset($preorderIds[$warehouseId])) {
                $preorder[$productId] += (int) $row->quantity;
            }
        }

        // Режим стопки: строгое замещение — действует остаток верхнего склада
        // с наличием, нижние — фолбэк по позициям, которых нет выше.
        // Победитель выбирается по сырому остатку (это склад, с которого
        // физически отгрузит 1С), буфер ниже вычитается уже из его количества.
        if ($warehouses['stack']) {
            $resolved = app(WarehouseStackResolver::class)
                ->resolve($warehouses['primary'], $primaryByProduct);

            $regionId = $this->resolveRegionId($user);

            foreach (array_keys($available) as $productId) {
                $winner = $resolved[$productId] ?? ['warehouse_id' => null, 'quantity' => 0];
                $available[$productId] = $winner['quantity'];

                if ($regionId !== null) {
                    $this->winnerCache[$regionId][$productId] = $winner['warehouse_id'];
                }
            }
        }

        // Страховой буфер (buf-04): клиентам сегмента available занижается,
        // preorder — никогда (предзаказ — обещание прихода, не полка).
        if ($this->buffersApplyTo($user)) {
            $buffers = app(StockBufferService::class)->bufferMap(array_keys($available));

            foreach ($available as $productId => $quantity) {
                $available[$productId] = max(0, $quantity - ($buffers[$productId] ?? 0));
            }
        }

        return ['available' => $available, 'preorder' => $preorder];
    }

    /**
     * Применять ли страховой буфер к этому пользователю (buf-04).
     *
     * Цена для горячего пути — ноль: гость и клиент без галочки отсекаются
     * двумя булевыми проверками, без единого SQL-запроса.
     */
    private function buffersApplyTo(?User $user): bool
    {
        return (bool) config('stock_buffer.enabled')
            && $user !== null
            && (bool) $user->stock_buffer_enabled;
    }

    /**
     * Добавить к запросу товаров подзапросы-суммы `primary_stock` и
     * `preorder_stock` по складам региона пользователя.
     *
     * Нужен потребителям, у которых остаток участвует в ORDER BY до пагинации
     * (поиск: «в наличии выше предзаказа», избранное: stock_desc). Остальным
     * дешевле батч-карта после paginate() — см. getStockMaps().
     *
     * ВАЖНО: вызывать после select('products.*') — ветка с пустыми складами
     * добавляет selectRaw-константы, и без явного select они затёрли бы `*`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Product>|\Illuminate\Database\Query\Builder  $query
     */
    public function applyStockSubselects($query, ?User $user = null): void
    {
        $warehouses = $this->regionWarehouseIds($user);

        if ($warehouses['primary'] !== []) {
            // Режим стопки: вместо суммы — остаток верхнего склада стопки
            // с наличием (строгое замещение), тем же правилом, что батч-карты.
            if ($warehouses['stack']) {
                $winnerSql = $this->stackWinnerSubquery($warehouses['primary']);
                $stockExpr = 'COALESCE(('.$winnerSql->toSql().'), 0)';
                $stockBindings = $winnerSql->getBindings();
            } else {
                $sumSql = DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $warehouses['primary']);
                $stockExpr = '('.$sumSql->toSql().')';
                $stockBindings = $sumSql->getBindings();
            }

            if ($this->buffersApplyTo($user)) {
                // Страховой буфер (buf-04): сортировка «в наличии первыми»
                // не должна поднимать товар, который карточка уже показывает
                // нулевым. GREATEST есть только в MySQL, в SQLite (тесты)
                // двухаргументный MAX — скалярный аналог.
                $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
                $bufferSql = 'SELECT '.$greatest.'(COALESCE(manual_qty, buffer_qty), 0)'
                    .' FROM product_stock_buffers WHERE product_stock_buffers.product_id = products.id';

                $query->selectRaw(
                    $greatest.'('.$stockExpr.' - COALESCE(('.$bufferSql.'), 0), 0) as primary_stock',
                    $stockBindings,
                );
            } else {
                $query->selectRaw($stockExpr.' as primary_stock', $stockBindings);
            }
        } else {
            $query->selectRaw('0 as primary_stock');
        }

        if ($warehouses['preorder'] !== []) {
            $query->addSelect([
                'preorder_stock' => DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $warehouses['preorder']),
            ]);
        } else {
            $query->selectRaw('0 as preorder_stock');
        }
    }

    /**
     * Коррелированный подзапрос «остаток выигравшего склада стопки» для
     * applyStockSubselects: первый по порядку стопки склад с quantity > 0.
     * Порядок задаётся CASE-выражением (портабельно между MySQL и SQLite;
     * ID складов — целые из БД, интерполяция безопасна).
     *
     * @param  list<int>  $orderedWarehouseIds  склады стопки сверху вниз
     */
    private function stackWinnerSubquery(array $orderedWarehouseIds): \Illuminate\Database\Query\Builder
    {
        $cases = [];
        foreach (array_values($orderedWarehouseIds) as $index => $warehouseId) {
            $cases[] = 'WHEN '.(int) $warehouseId.' THEN '.$index;
        }

        return DB::table('product_warehouse')
            ->select('quantity')
            ->whereColumn('product_warehouse.product_id', 'products.id')
            ->whereIn('product_warehouse.warehouse_id', $orderedWarehouseIds)
            ->where('quantity', '>', 0)
            ->orderByRaw('CASE product_warehouse.warehouse_id '.implode(' ', $cases).' END')
            ->limit(1);
    }

    /**
     * Склады региона пользователя (primary и preorder) с мемоизацией
     * на время запроса. Гость и пользователь без региона — регион по
     * умолчанию, как в каталоге.
     *
     * primary упорядочен по позиции в стопке (priority, NULL — в конце,
     * затем по id) — для регионов без стопки это тот же набор складов,
     * стабильно отсортированный. stack — режим стопки региона.
     *
     * @return array{primary: list<int>, preorder: list<int>, stack: bool}
     */
    public function regionWarehouseIds(?User $user = null): array
    {
        $regionId = $this->resolveRegionId($user);

        if ($regionId === null) {
            return ['primary' => [], 'preorder' => [], 'stack' => false];
        }

        if (isset($this->regionWarehouses[$regionId])) {
            return $this->regionWarehouses[$regionId];
        }

        // Join к regions ради флага стопки: тот же один запрос, что и раньше
        // (см. тест на константное число запросов). Регион без складов режима
        // стопки иметь не может — пустой результат означает stack=false.
        $rows = DB::table('region_warehouse')
            ->join('regions', 'regions.id', '=', 'region_warehouse.region_id')
            ->where('region_warehouse.region_id', $regionId)
            ->select('region_warehouse.warehouse_id', 'region_warehouse.type', 'regions.stock_stack_enabled')
            ->orderByRaw('region_warehouse.priority IS NULL, region_warehouse.priority, region_warehouse.warehouse_id')
            ->get();

        $stack = (bool) ($rows->first()->stock_stack_enabled ?? false);

        return $this->regionWarehouses[$regionId] = [
            'primary' => $rows->where('type', 'primary')
                ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->values()->all(),
            'preorder' => $rows->where('type', 'preorder')
                ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->values()->all(),
            'stack' => $stack,
        ];
    }

    /**
     * Карта выигравших складов в режиме стопки: product_id → warehouse_id
     * склада, чей остаток действует для товара (null — товара нет в наличии
     * ни на одном складе стопки). Для регионов без стопки все значения null —
     * складского разреза у цены/остатка нет.
     *
     * Результат мемоизируется на запрос: карточка, корзина и checkout в одном
     * запросе не пересчитывают победителей повторно.
     *
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    public function getWinningWarehouseMap(array $productIds, ?User $user = null): array
    {
        $map = [];
        foreach ($productIds as $id) {
            $map[(int) $id] = null;
        }

        if ($map === []) {
            return [];
        }

        $warehouses = $this->regionWarehouseIds($user);

        if (! $warehouses['stack']) {
            return $map;
        }

        $regionId = $this->resolveRegionId($user);
        $cached = $this->winnerCache[$regionId] ?? [];

        $missing = [];
        foreach (array_keys($map) as $productId) {
            if (array_key_exists($productId, $cached)) {
                $map[$productId] = $cached[$productId];
            } else {
                $missing[] = $productId;
            }
        }

        if ($missing !== []) {
            // getStockMapsByIds в режиме стопки заполняет winnerCache.
            $this->getStockMapsByIds($missing, $user);

            foreach ($missing as $productId) {
                $map[$productId] = $this->winnerCache[$regionId][$productId] ?? null;
            }
        }

        return $map;
    }

    /**
     * Резолвит ID региона пользователя с fallback на регион по умолчанию.
     * Если у пользователя не задан region_id (например, у админа), используется Region::defaultId() —
     * та же логика, что в каталоге (CatalogApiController), чтобы наличие в карточке и в корзине совпадало.
     */
    private function resolveRegionId(?User $user): ?int
    {
        if ($user !== null && $user->region_id !== null) {
            return (int) $user->region_id;
        }

        if (! $this->defaultRegionResolved) {
            $this->defaultRegionId = Region::defaultId();
            $this->defaultRegionResolved = true;
        }

        return $this->defaultRegionId;
    }
}
