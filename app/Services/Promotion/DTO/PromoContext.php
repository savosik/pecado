<?php

namespace App\Services\Promotion\DTO;

use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromotionSelection;
use App\Models\Currency;
use App\Models\PromotionRule;
use App\Models\User;

/**
 * Снапшот, по которому считаются акции: позиции + клиент + канал + его выбор.
 *
 * Один контекст обслуживает корзину, чекаут и клиентское API — поэтому здесь нет
 * ни Cart, ни Order, только плоский список строк.
 */
final readonly class PromoContext
{
    /**
     * @param  PromoContextLine[]  $lines  Позиции снапшота
     * @param  User|null  $user  Клиент; NULL — гость (индивидуальных цен нет)
     * @param  string  $channel  Канал: site | api
     * @param  array<string, array{product_id?: int|null, declined?: bool}>  $selections
     *                                                                                    Выбор клиента, ключ «{rule_id}:{reward_index}»
     * @param  PromotionRuleMode|null  $mode  Какие правила применять; NULL — любые
     * @param  Currency|null  $currency  Валюта переданных unitPrice; NULL — рубли
     */
    public function __construct(
        public array $lines,
        public ?User $user = null,
        public string $channel = PromotionRule::CHANNEL_SITE,
        public array $selections = [],
        public ?PromotionRuleMode $mode = null,
        public ?Currency $currency = null,
    ) {}

    /**
     * Контекст из корзины сайта.
     *
     * Цены строк намеренно не переносятся (кроме уценки, где цена зафиксирована
     * в партии): движок считает финальную цену клиента сам, одним батчем, — иначе
     * корзина и API разойдутся на устаревшем cart_items.price.
     */
    public static function fromCart(
        Cart $cart,
        ?User $user = null,
        ?PromotionRuleMode $mode = null,
        string $channel = PromotionRule::CHANNEL_SITE,
        ?Currency $currency = null,
    ): self {
        $cart->loadMissing('items', 'promotionSelections');

        $lines = $cart->items
            ->map(fn (CartItem $item) => new PromoContextLine(
                productId: (int) $item->product_id,
                quantity: (int) $item->quantity,
                unitPrice: $item->isDefect() ? (float) ($item->price ?? 0) : null,
                isPromo: false,
                itemType: (string) $item->item_type,
            ))
            ->values()
            ->all();

        $selections = [];
        foreach ($cart->promotionSelections as $selection) {
            /** @var CartPromotionSelection $selection */
            $selections[self::selectionKey($selection->promotion_rule_id, $selection->reward_index)] = [
                'product_id' => $selection->product_id,
                'declined' => $selection->is_declined,
            ];
        }

        return new self(
            lines: $lines,
            user: $user ?? $cart->user,
            channel: $channel,
            selections: $selections,
            mode: $mode,
            currency: $currency,
        );
    }

    /**
     * Контекст из произвольного списка позиций — чекаут и клиентское API.
     *
     * @param  PromoContextLine[]  $lines
     * @param  array<string, array{product_id?: int|null, declined?: bool}>  $selections
     */
    public static function fromLines(
        array $lines,
        ?User $user = null,
        string $channel = PromotionRule::CHANNEL_SITE,
        array $selections = [],
        ?PromotionRuleMode $mode = null,
        ?Currency $currency = null,
    ): self {
        return new self(
            lines: array_values($lines),
            user: $user,
            channel: $channel,
            selections: $selections,
            mode: $mode,
            currency: $currency,
        );
    }

    public static function selectionKey(int $ruleId, int $rewardIndex): string
    {
        return $ruleId.':'.$rewardIndex;
    }

    /**
     * Позиции, участвующие в расчёте условий: промо-строки исключены,
     * иначе платная промо-позиция за 40 ₽ потянет следующее правило.
     *
     * @return PromoContextLine[]
     */
    public function countableLines(): array
    {
        return array_values(array_filter($this->lines, fn (PromoContextLine $line) => ! $line->isPromo && $line->quantity > 0));
    }

    public function isEmpty(): bool
    {
        return $this->countableLines() === [];
    }

    /**
     * @return array{product_id?: int|null, declined?: bool}|null
     */
    public function selectionFor(int $ruleId, int $rewardIndex): ?array
    {
        return $this->selections[self::selectionKey($ruleId, $rewardIndex)] ?? null;
    }
}
