<?php

namespace App\Services\Erp\Support;

use App\Models\Order;

/**
 * Сохранение привязки промо-позиций к акции при пересборке заказа из 1С.
 *
 * Полный аналог `PreservesDefectItemLinks` и написан по той же причине:
 * `promotion_rule_id` и `promo_kind` — внутренние поля сайта, 1С про них не знает
 * и в payload их не присылает. Обработчики `order.created` / `order.updated`
 * делают полную замену позиций (delete + create), поэтому без этого трейта
 * первый же roundtrip обнулил бы привязку — ровно то, на чём уже сгорели
 * с уценкой (коммит e89536f4).
 *
 * Написан **до** первого инцидента, а не после.
 *
 * Отдельно: менеджер может добавить промо-позицию вручную прямо в 1С. Такая
 * позиция приедет без `promotion_rule_id`, и это норма — сопоставлять её
 * с правилом акции не нужно. Если 1С прислала `promo_kind`, он сохраняется;
 * если нет — берётся из снимка по товару.
 */
trait PreservesPromoItemLinks
{
    /**
     * Снимок промо-привязок текущих позиций заказа (до их удаления).
     *
     * @return array<int, list<array{promotion_rule_id: int|null, promo_kind: string|null}>>
     */
    protected function capturePromoLinks(Order $order): array
    {
        $map = [];

        $items = $order->items()
            ->whereNotNull('product_id')
            ->whereNotNull('promo_kind')
            ->get(['product_id', 'promotion_rule_id', 'promo_kind']);

        foreach ($items as $item) {
            $map[(int) $item->product_id][] = [
                'promotion_rule_id' => $item->promotion_rule_id,
                'promo_kind' => $item->promo_kind,
            ];
        }

        return $map;
    }

    /**
     * Достаёт (FIFO) промо-привязку для товара из снимка.
     *
     * `$incoming` — то, что прислала 1С по этой позиции. Приоритет у неё:
     * позиция, добавленная менеджером вручную, придёт с `promo_kind`, но без
     * привязки к правилу, и затирать её снимком нельзя.
     *
     * @param  array<int, list<array{promotion_rule_id: int|null, promo_kind: string|null}>>  $map
     * @param  array<string, mixed>  $incoming
     * @return array{promotion_rule_id: int|null, promo_kind: string|null}
     */
    protected function pullPromoLink(array &$map, ?int $productId, array $incoming = []): array
    {
        $link = ($productId !== null && ! empty($map[$productId]))
            ? array_shift($map[$productId])
            : ['promotion_rule_id' => null, 'promo_kind' => null];

        // Вид позиции 1С прислать может, привязку к правилу — нет
        if (! empty($incoming['promo_kind'])) {
            $link['promo_kind'] = (string) $incoming['promo_kind'];
        } elseif (! empty($incoming['is_promo']) && $link['promo_kind'] === null) {
            // Флаг без вида: считаем позицию подотчётной — это безопаснее,
            // чем потерять признак промо совсем
            $link['promo_kind'] = \App\Enums\PromoKind::ACCOUNTABLE->value;
        }

        return $link;
    }
}
