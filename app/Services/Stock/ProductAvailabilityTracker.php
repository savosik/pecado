<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Отслеживание переходов доступности товара.
 *
 * Живёт в горячем пути шины: `stock.updated` идёт потоком, поэтому дешевизна
 * важнее универсальности. Отсюда три решения:
 *
 * 1. **Один лишний агрегат на сообщение.** Остаток «после» считается запросом,
 *    остаток «до» — арифметикой по дельте того склада, который прислала 1С.
 *    Второй агрегат не нужен.
 * 2. **К таблице событий обращаемся, только когда флаг доступности сменился.**
 *    Переходы редки, а сообщений много.
 * 3. **Двойной порог против шума.** Появление одной штуки — не возврат
 *    в продажу, а отсутствие на два часа между выгрузками — не дефицит.
 *    Оба порога в конфиге: подбирать их надо по живым данным, а не в коде.
 */
class ProductAvailabilityTracker
{
    /**
     * Зафиксировать переход, если он произошёл.
     *
     * @param  int|null  $oldQuantity  остаток на этом складе до сообщения
     * @param  int  $newQuantity  остаток на этом складе после сообщения
     */
    public function track(Product $product, Warehouse $warehouse, ?int $oldQuantity, int $newQuantity): ?ProductAvailabilityEvent
    {
        if (! $this->countsTowardsAvailability($warehouse)) {
            return null;
        }

        $after = $this->availableQuantity($product);
        $delta = $newQuantity - (int) $oldQuantity;
        $before = $after - $delta;

        $threshold = $this->minQuantity();
        $wasAvailable = $before >= $threshold;
        $isAvailable = $after >= $threshold;

        if ($wasAvailable === $isAvailable) {
            return null;
        }

        return $isAvailable
            ? $this->recordBackInStock($product, $after)
            : $this->recordOutOfStock($product, $after);
    }

    /**
     * Доступный остаток по складам, с которых реально продают.
     *
     * Склады брака и промо-образцов исключены: «товар вернулся в продажу»
     * не должно срабатывать на партии некондиции.
     */
    public function availableQuantity(Product $product): int
    {
        return (int) DB::table('product_warehouse')
            ->join('warehouses', 'warehouses.id', '=', 'product_warehouse.warehouse_id')
            ->whereIn('warehouses.id', $this->sellableWarehouseIds())
            ->where('product_warehouse.product_id', $product->getKey())
            ->sum('product_warehouse.quantity');
    }

    /**
     * Товар считается доступным.
     */
    public function isAvailable(Product $product): bool
    {
        return $this->availableQuantity($product) >= $this->minQuantity();
    }

    private function recordOutOfStock(Product $product, int $quantity): ProductAvailabilityEvent
    {
        return ProductAvailabilityEvent::create([
            'product_id' => $product->getKey(),
            'event' => ProductAvailabilityEvent::OUT_OF_STOCK,
            'quantity' => max(0, $quantity),
            'happened_at' => Carbon::now(),
        ]);
    }

    /**
     * Появление товара в продаже.
     *
     * Мелькание нуля событием не считается: если товара не было меньше
     * заданного срока, это пересортица или разрыв между выгрузками, а не
     * дефицит, и звать за ним клиентов не за чем. В этом случае парная запись
     * об исчезновении удаляется — иначе в истории остался бы «вечный дефицит».
     */
    private function recordBackInStock(Product $product, int $quantity): ?ProductAvailabilityEvent
    {
        $lastOut = ProductAvailabilityEvent::query()
            ->where('product_id', $product->getKey())
            ->where('event', ProductAvailabilityEvent::OUT_OF_STOCK)
            ->latest('happened_at')
            ->first();

        if ($lastOut === null) {
            // Товара не было в истории вовсе — первое появление. Записываем
            // без срока отсутствия: считать его не от чего.
            return ProductAvailabilityEvent::create([
                'product_id' => $product->getKey(),
                'event' => ProductAvailabilityEvent::IN_STOCK,
                'quantity' => $quantity,
                'happened_at' => Carbon::now(),
            ]);
        }

        $missingHours = $lastOut->happened_at->diffInHours(Carbon::now());

        if ($missingHours < $this->minAbsenceHours()) {
            $lastOut->delete();

            return null;
        }

        return ProductAvailabilityEvent::create([
            'product_id' => $product->getKey(),
            'event' => ProductAvailabilityEvent::IN_STOCK,
            'quantity' => $quantity,
            'happened_at' => Carbon::now(),
            'missing_days' => (int) floor($missingHours / 24),
        ]);
    }

    /**
     * Склад, с которого продают: не брак и не промо-образцы.
     */
    private function countsTowardsAvailability(Warehouse $warehouse): bool
    {
        return in_array((int) $warehouse->getKey(), $this->sellableWarehouseIds(), true);
    }

    /**
     * @return list<int>
     */
    private function sellableWarehouseIds(): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('region_warehouse')
            ->join('warehouses', 'warehouses.id', '=', 'region_warehouse.warehouse_id')
            ->where('region_warehouse.region_id', Region::defaultId())
            ->where('region_warehouse.type', 'primary')
            ->where('warehouses.is_defect', false)
            ->where('warehouses.is_promo_sample', false)
            ->pluck('warehouses.id')
            ->map('intval')
            ->all();

        return $ids;
    }

    private function minQuantity(): int
    {
        return max(1, (int) config('stock.availability.min_quantity', 3));
    }

    private function minAbsenceHours(): int
    {
        return max(1, (int) config('stock.availability.min_absence_hours', 48));
    }
}
