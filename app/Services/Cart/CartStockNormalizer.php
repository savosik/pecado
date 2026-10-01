<?php

namespace App\Services\Cart;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Привести количества в корзине к доступному остатку.
 *
 * Обычный товар переразбивается так же, как при вводе количества
 * (`CartService::setProductQuantity`): всего = min(в корзине, наличие + предзаказ),
 * «в наличии» = min(всего, наличие), остальное — в предзаказ. Трогаются только
 * товары, у которых строка какого-то вида превышает свой остаток: строка «в наличии»
 * уходит заказом на основной склад региона, «предзаказ» — на склад предзаказа, и
 * сверять их с суммой складов нельзя (топик №17, 29УТ-014795). Клиенту с
 * выключенными предзаказами склад предзаказа не виден вовсе
 * (`StockService::regionWarehouseIds`), и недостающее снимается, а не переносится.
 *
 * Уценка ограничена свободным остатком партии; закрытая или снятая партия считается
 * недоступной. Общая для кабинета и API v1.
 */
class CartStockNormalizer
{
    public function __construct(
        private readonly StockServiceInterface $stocks,
        private readonly DefectStockServiceInterface $defectStocks,
    ) {}

    /**
     * adjusted — товаров (строк уценки), у которых изменилось количество или разбивка;
     * removed — убранных целиком; moved_to_preorder — штук, переведённых из
     * «в наличии» в предзаказ.
     *
     * @return array{adjusted: int, removed: int, moved_to_preorder: int, remaining_lines: int}
     */
    public function normalize(User $user, Cart $cart): array
    {
        $adjusted = 0;
        $removed = 0;
        $movedToPreorder = 0;

        $cart->load('items.product', 'items.productDefect');

        /** @var array<int, list<CartItem>> $regular */
        $regular = [];

        foreach ($cart->items as $item) {
            if (! $item->product) {
                continue;
            }

            if (! $item->isDefect()) {
                $regular[$item->product_id][] = $item;

                continue;
            }

            $defect = $item->productDefect;
            $available = ($defect && $defect->is_published && $defect->price !== null && ! $defect->isClosed())
                ? $this->defectStocks->available($defect)
                : 0;

            if ($item->quantity <= $available) {
                continue;
            }

            if ($available <= 0) {
                $item->delete();
                $removed++;

                continue;
            }

            $item->quantity = $available;
            $item->save();
            $adjusted++;
        }

        foreach ($regular as $items) {
            $result = $this->resplit($user, $items);

            if ($result === null) {
                continue;
            }

            $result['removed'] ? $removed++ : $adjusted++;
            $movedToPreorder += $result['moved_to_preorder'];
        }

        return [
            'adjusted' => $adjusted,
            'removed' => $removed,
            'moved_to_preorder' => $movedToPreorder,
            'remaining_lines' => $cart->items()->count(),
        ];
    }

    /**
     * Переразбить строки одного товара по остаткам своего вида.
     *
     * @param  list<CartItem>  $items  обычные строки одного товара
     * @return array{removed: bool, moved_to_preorder: int}|null null — товар в порядке
     */
    private function resplit(User $user, array $items): ?array
    {
        $stock = $this->stocks->getStock($items[0]->product, $user);
        $available = max(0, (int) $stock['available']);
        $preorderStock = max(0, (int) $stock['preorder']);

        $wantInstock = 0;
        $wantPreorder = 0;

        foreach ($items as $item) {
            if ($item->item_type === 'preorder') {
                $wantPreorder += $item->quantity;
            } else {
                $wantInstock += $item->quantity;
            }
        }

        if ($wantInstock <= $available && $wantPreorder <= $preorderStock) {
            return null;
        }

        $total = min($wantInstock + $wantPreorder, $available + $preorderStock);
        $instock = min($total, $available);
        $preorder = $total - $instock;

        // Цена строки уже назначена корзиной — переразбивка её не пересчитывает.
        $price = $items[0]->price;

        DB::transaction(function () use ($items, $instock, $preorder, $price) {
            $instockItem = null;
            $preorderItem = null;

            foreach ($items as $item) {
                if ($item->item_type === 'preorder' && $preorderItem === null) {
                    $preorderItem = $item;
                } elseif ($item->item_type !== 'preorder' && $instockItem === null) {
                    $instockItem = $item;
                } else {
                    $item->delete();
                }
            }

            $this->putLine($items[0], $instockItem, 'instock', $instock, $price);
            $this->putLine($items[0], $preorderItem, 'preorder', $preorder, $price);
        });

        return [
            'removed' => $total === 0,
            'moved_to_preorder' => max(0, $preorder - $wantPreorder),
        ];
    }

    /** Записать строку нужного вида: обновить, создать или удалить при нуле. */
    private function putLine(CartItem $template, ?CartItem $line, string $type, int $quantity, mixed $price): void
    {
        if ($quantity <= 0) {
            $line?->delete();

            return;
        }

        if ($line !== null) {
            if ($line->quantity !== $quantity) {
                $line->quantity = $quantity;
                $line->save();
            }

            return;
        }

        CartItem::create([
            'cart_id' => $template->cart_id,
            'product_id' => $template->product_id,
            'quantity' => $quantity,
            'price' => $price,
            'item_type' => $type,
            'warehouse_id' => null,
        ]);
    }
}
