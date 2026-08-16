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
     *
     * @var array<int, array{primary: list<int>, preorder: list<int>}>
     */
    private array $regionWarehouses = [];

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

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;

            if (isset($primaryIds[$row->warehouse_id])) {
                $available[$productId] += (int) $row->quantity;
            }

            if (isset($preorderIds[$row->warehouse_id])) {
                $preorder[$productId] += (int) $row->quantity;
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
            if ($this->buffersApplyTo($user)) {
                // Страховой буфер (buf-04): сортировка «в наличии первыми»
                // не должна поднимать товар, который карточка уже показывает
                // нулевым. GREATEST есть только в MySQL, в SQLite (тесты)
                // двухаргументный MAX — скалярный аналог.
                $stockSql = DB::table('product_warehouse')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('product_warehouse.warehouse_id', $warehouses['primary']);

                $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
                $bufferSql = 'SELECT '.$greatest.'(COALESCE(manual_qty, buffer_qty), 0)'
                    .' FROM product_stock_buffers WHERE product_stock_buffers.product_id = products.id';

                $query->selectRaw(
                    $greatest.'(('.$stockSql->toSql().') - COALESCE(('.$bufferSql.'), 0), 0) as primary_stock',
                    $stockSql->getBindings(),
                );
            } else {
                $query->addSelect([
                    'primary_stock' => DB::table('product_warehouse')
                        ->selectRaw('COALESCE(SUM(quantity), 0)')
                        ->whereColumn('product_warehouse.product_id', 'products.id')
                        ->whereIn('product_warehouse.warehouse_id', $warehouses['primary']),
                ]);
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
     * Склады региона пользователя (primary и preorder) с мемоизацией
     * на время запроса. Гость и пользователь без региона — регион по
     * умолчанию, как в каталоге.
     *
     * @return array{primary: list<int>, preorder: list<int>}
     */
    public function regionWarehouseIds(?User $user = null): array
    {
        $regionId = $this->resolveRegionId($user);

        if ($regionId === null) {
            return ['primary' => [], 'preorder' => []];
        }

        if (isset($this->regionWarehouses[$regionId])) {
            return $this->regionWarehouses[$regionId];
        }

        $rows = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->select('warehouse_id', 'type')
            ->get();

        return $this->regionWarehouses[$regionId] = [
            'primary' => $rows->where('type', 'primary')
                ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->values()->all(),
            'preorder' => $rows->where('type', 'preorder')
                ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
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
