<?php

namespace App\Services\Promotion;

use App\Models\Cart;
use App\Models\CartPromotionSelection;
use App\Models\PromotionRule;
use InvalidArgumentException;

/**
 * Выбор клиента по награде акции в корзине: вариант товара и отказ от платной позиции.
 *
 * Единственная реализация для кабинета (`CartController::selectPromo/declinePromo`)
 * и клиентского API v1: правило «от бесплатного не отказываются» живёт здесь,
 * а не в контроллере.
 */
class CartPromoChoice
{
    /**
     * Выбрать товар из вариантов награды.
     */
    public function select(Cart $cart, int $ruleId, int $rewardIndex, int $productId): void
    {
        CartPromotionSelection::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'promotion_rule_id' => $ruleId,
                'reward_index' => $rewardIndex,
            ],
            ['product_id' => $productId],
        );
    }

    /**
     * Отказаться от платной промо-позиции или вернуть её.
     *
     * @throws InvalidArgumentException награда не отклоняемая (бесплатная или не optional)
     */
    public function decline(Cart $cart, int $ruleId, int $rewardIndex, bool $declined): void
    {
        // От бесплатного не отказываются: подарок ничего не стоит, а кнопка
        // «отказаться» рядом с ним выглядит как ошибка интерфейса
        if ($declined && ! $this->rewardIsOptional($ruleId, $rewardIndex)) {
            throw new InvalidArgumentException('От этой промо-позиции нельзя отказаться');
        }

        CartPromotionSelection::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'promotion_rule_id' => $ruleId,
                'reward_index' => $rewardIndex,
            ],
            ['is_declined' => $declined],
        );
    }

    /**
     * Отклоняемая ли награда: платная и помеченная `optional` в правиле.
     */
    public function rewardIsOptional(int $ruleId, int $rewardIndex): bool
    {
        $rule = PromotionRule::find($ruleId);
        $reward = (array) (array_values((array) ($rule?->rewards ?? []))[$rewardIndex] ?? []);

        return (float) ($reward['price'] ?? 0) > 0 && (bool) ($reward['optional'] ?? true);
    }
}
