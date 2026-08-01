<?php

namespace App\Services\Promotion;

use App\Enums\PromoKind;
use App\Models\Product;
use App\Services\Promotion\DTO\AppliedReward;
use Illuminate\Support\Collection;

/**
 * Формирует человекочитаемый список отбора промо-позиций для комментария складу.
 *
 * Мотивация та же, что у `DefectPickListFormatter`: кладовщики собирают заказ
 * по печатному документу из 1С и в WMS заходят редко, поэтому конкретика
 * дописывается в `warehouse_comment` заказа — он уходит в 1С и попадает
 * в печатную форму.
 *
 * Промо-позиция внешне ничем не отличается от обычного товара, и без пометки
 * кладовщик не поймёт, почему в документе Lush 4 за 0 ₽. Пробники вдобавок
 * лежат на отдельном складе, куда за обычным заказом никто не пойдёт.
 *
 * Строка на позицию:
 *   арт. {sku} — {название} — {N} шт. — по акции «{название акции}»
 */
class PromoPickListFormatter
{
    /** Заголовок блока для пробников — они лежат на отдельном складе. */
    public const HEADING_SAMPLE = 'Рекламные образцы — отобрать со склада «Москва подарки»:';

    /** Заголовок блока для подотчётных позиций — они отбираются вместе с заказом. */
    public const HEADING_ACCOUNTABLE = 'Промо-позиции — отобрать с обычного склада вместе с заказом:';

    /**
     * @param  list<AppliedReward>  $rewards  награды одного вида
     * @param  Collection<int, Product>  $products  товары наград, ключ — id
     */
    public function format(array $rewards, Collection $products, PromoKind $kind): string
    {
        if ($rewards === []) {
            return '';
        }

        $lines = [];

        foreach ($rewards as $reward) {
            $product = $products->get($reward->productId);

            // Товара нет — строки в заказе тоже не будет, значит и в листе она лишняя
            if ($product === null) {
                continue;
            }

            $sku = $product->sku ?: '—';
            $quantity = (int) $reward->quantity;

            $lines[] = "арт. {$sku} — {$product->name} — {$quantity} шт. — по акции «{$reward->ruleName}»";
        }

        if ($lines === []) {
            return '';
        }

        return $this->heading($kind)."\n".implode("\n", $lines);
    }

    private function heading(PromoKind $kind): string
    {
        return match ($kind) {
            PromoKind::SAMPLE => self::HEADING_SAMPLE,
            PromoKind::ACCOUNTABLE => self::HEADING_ACCOUNTABLE,
        };
    }
}
