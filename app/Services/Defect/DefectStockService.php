<?php

namespace App\Services\Defect;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Enums\OrderType;
use App\Models\Product;
use App\Models\ProductDefect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Остаток и резерв партий некондиции.
 *
 * Резерв — производная величина, а не поле: считается по order_items заказов
 * type = defect, у которых заказ не удалён. Так удаление заказа само возвращает
 * партию в продажу, и нет риска рассинхрона денормализованного счётчика.
 *
 * Партий в системе десятки, не миллионы, поэтому подзапрос дешевле поддержки
 * счётчика. Все методы батч-безопасны: количество запросов не зависит от размера
 * коллекции.
 */
class DefectStockService implements DefectStockServiceInterface
{
    public function available(ProductDefect $defect): int
    {
        return max(0, (int) $defect->quantity - $this->reserved($defect));
    }

    /**
     * @param  iterable<ProductDefect>  $defects
     * @return array<int, int>
     */
    public function availableMap(iterable $defects): array
    {
        $quantities = [];
        foreach ($defects as $defect) {
            $quantities[(int) $defect->id] = (int) $defect->quantity;
        }

        if ($quantities === []) {
            return [];
        }

        $reserved = $this->reservedMap(array_keys($quantities));

        $result = [];
        foreach ($quantities as $defectId => $quantity) {
            $result[$defectId] = max(0, $quantity - ($reserved[$defectId] ?? 0));
        }

        return $result;
    }

    public function reserved(ProductDefect $defect): int
    {
        return $this->reservedMap([(int) $defect->id])[(int) $defect->id] ?? 0;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, bool>
     */
    public function hasSellableDefectsMap(array $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) {
            $result[(int) $id] = false;
        }

        if ($result === []) {
            return $result;
        }

        $productIds = array_keys($result);

        $defects = ProductDefect::query()
            ->sellable()
            ->whereIn('product_id', $productIds)
            ->get(['id', 'product_id', 'quantity']);

        if ($defects->isEmpty()) {
            return $result;
        }

        $available = $this->availableMap($defects);

        foreach ($defects as $defect) {
            if (($available[(int) $defect->id] ?? 0) > 0) {
                $result[(int) $defect->product_id] = true;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function minSellablePriceMap(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $defects = ProductDefect::query()
            ->sellable()
            ->whereIn('product_id', $productIds)
            ->get(['id', 'product_id', 'quantity', 'price']);

        if ($defects->isEmpty()) {
            return [];
        }

        $available = $this->availableMap($defects);

        $result = [];
        foreach ($defects as $defect) {
            if (($available[(int) $defect->id] ?? 0) <= 0) {
                continue;
            }

            $productId = (int) $defect->product_id;
            $price = (float) $defect->price;

            if (! isset($result[$productId]) || $price < $result[$productId]) {
                $result[$productId] = $price;
            }
        }

        return $result;
    }

    /**
     * @return Collection<int, ProductDefect>
     */
    public function sellableForProduct(Product $product): Collection
    {
        $defects = ProductDefect::query()
            ->sellable()
            ->where('product_id', $product->id)
            ->with('media')
            ->orderBy('price')
            ->get();

        if ($defects->isEmpty()) {
            return $defects;
        }

        $available = $this->availableMap($defects);

        return $defects
            ->filter(fn (ProductDefect $defect) => ($available[(int) $defect->id] ?? 0) > 0)
            ->each(function (ProductDefect $defect) use ($available) {
                // Кладём остаток в атрибут, чтобы вызывающий код не бегал за ним повторно.
                $defect->setAttribute('available_quantity', $available[(int) $defect->id] ?? 0);
            })
            ->values();
    }

    /**
     * Зарезервированные количества по списку партий одним запросом.
     *
     * @param  array<int, int>  $defectIds
     * @return array<int, int>
     */
    private function reservedMap(array $defectIds): array
    {
        if ($defectIds === []) {
            return [];
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_defect_id', $defectIds)
            ->where('orders.type', OrderType::DEFECT->value)
            ->whereNull('orders.deleted_at')
            ->select('order_items.product_defect_id', DB::raw('SUM(order_items.quantity) as total'))
            ->groupBy('order_items.product_defect_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_defect_id] = (int) $row->total;
        }

        return $result;
    }
}
