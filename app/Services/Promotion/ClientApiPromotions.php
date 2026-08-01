<?php

namespace App\Services\Promotion;

use App\Enums\OrderType;
use App\Enums\PromoKind;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Scopes\HiddenScope;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\DTO\ClientApiPromoResult;
use App\Services\Promotion\DTO\NearMiss;
use App\Services\Promotion\DTO\PromoContext;
use App\Services\Promotion\DTO\PromoContextLine;

/**
 * Расчёт акций для клиентского API (карточка promo-12).
 *
 * Отличия от сайта, продиктованные отсутствием корзины:
 *
 * - **Считаем по принятым позициям, а не по запрошенным.** Контроллер урезает
 *   количества по остатку, и промо обязано опираться на то, что реально
 *   отгружается: иначе подарок уедет за товар, которого не было.
 * - **Награды с выбором (`choice`) не выдаём.** Выбор клиента живёт
 *   в `cart_promotion_selections`, а корзины у API нет. Движок в такой ситуации
 *   берёт первый вариант — за клиента решать нельзя, поэтому такие награды
 *   отфильтровываются и уходят в `near_miss` с пояснением.
 * - **Причины блокировки наружу не отдаём** — то же правило, что для сайта.
 *   Нет промо-позиции — её просто нет в `applied`.
 */
class ClientApiPromotions
{
    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly PromotionRuleDescriber $describer,
        private readonly PromoPickListFormatter $pickListFormatter,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: int}>  $acceptedItems  позиции, принятые к отгрузке
     */
    public function resolve(array $acceptedItems, User $user): ClientApiPromoResult
    {
        if ($acceptedItems === []) {
            return ClientApiPromoResult::empty();
        }

        // unitPrice не передаём: движок сам возьмёт финальную цену клиента,
        // включая индивидуальные прайсы, — так порог в API и на сайте считается
        // по одним и тем же деньгам
        $lines = array_map(
            static fn (array $item) => new PromoContextLine(
                productId: (int) $item['product']->id,
                quantity: (int) $item['quantity'],
            ),
            array_values($acceptedItems),
        );

        $evaluation = $this->engine->evaluate(PromoContext::fromLines(
            lines: $lines,
            user: $user,
            channel: PromotionRule::CHANNEL_API,
        ));

        $issuable = array_values(array_filter(
            $evaluation->applied,
            static fn (AppliedReward $reward) => $reward->isIssuable(),
        ));

        $rules = $this->rules($issuable, $evaluation->nearMiss);

        $issued = [];
        $skippedChoices = [];

        foreach ($issuable as $reward) {
            if ($this->isChoiceReward($rules[$reward->ruleId] ?? null, $reward->rewardIndex)) {
                $skippedChoices[] = $reward;

                continue;
            }

            $issued[] = $reward;
        }

        $products = $this->products($issued);

        $groups = [
            OrderType::PROMO->value => [],
            OrderType::PROMO_SAMPLE->value => [],
        ];

        $withProduct = [];

        /** @var array<string, list<AppliedReward>> $rewardsByKind */
        $rewardsByKind = [
            PromoKind::ACCOUNTABLE->value => [],
            PromoKind::SAMPLE->value => [],
        ];

        foreach ($issued as $reward) {
            $product = $products->get($reward->productId);

            // Товара нет — строки в заказе не будет, значит и в ответе ей не место
            if ($product === null) {
                continue;
            }

            $groups[$this->orderTypeFor($reward->promoKind)][] = OrderLine::promo(
                product: $product,
                quantity: $reward->quantity,
                price: $reward->price,
                promotionRuleId: $reward->ruleId,
                promoKind: $reward->promoKind->value,
            );

            $withProduct[] = ['reward' => $reward, 'product' => $product];
            $rewardsByKind[$reward->promoKind->value][] = $reward;
        }

        return new ClientApiPromoResult(
            groups: $groups,
            issued: $withProduct,
            nearMiss: array_merge(
                $this->nearMissBlock($evaluation->nearMiss, $rules),
                $this->choiceBlock($skippedChoices, $rules),
            ),
            warehouseComments: $this->pickLists($rewardsByKind, $products),
        );
    }

    /**
     * Правила, упомянутые в результате расчёта, одним запросом.
     *
     * @param  list<AppliedReward>  $applied
     * @param  list<NearMiss>  $nearMiss
     * @return array<int, PromotionRule>
     */
    private function rules(array $applied, array $nearMiss): array
    {
        $ids = array_unique(array_merge(
            array_map(static fn (AppliedReward $reward) => $reward->ruleId, $applied),
            array_map(static fn (NearMiss $miss) => $miss->ruleId, $nearMiss),
        ));

        if ($ids === []) {
            return [];
        }

        return PromotionRule::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    private function isChoiceReward(?PromotionRule $rule, int $rewardIndex): bool
    {
        if ($rule === null) {
            return false;
        }

        $reward = ((array) $rule->rewards)[$rewardIndex] ?? null;

        return is_array($reward)
            && ($reward['type'] ?? PromotionRule::REWARD_TYPE_FIXED) === PromotionRule::REWARD_TYPE_CHOICE;
    }

    /**
     * @param  list<AppliedReward>  $rewards
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function products(array $rewards): \Illuminate\Support\Collection
    {
        if ($rewards === []) {
            return collect();
        }

        return Product::withoutGlobalScope(HiddenScope::class)
            ->whereIn('id', array_map(static fn (AppliedReward $reward) => $reward->productId, $rewards))
            ->get()
            ->keyBy('id');
    }

    /**
     * Листы отбора по типу заказа — та же конкретика для склада, что и в чекауте:
     * канал оформления кладовщика не касается.
     *
     * @param  array<string, list<AppliedReward>>  $rewardsByKind
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<string, string>
     */
    private function pickLists(array $rewardsByKind, \Illuminate\Support\Collection $products): array
    {
        $pickLists = [];

        foreach ($rewardsByKind as $kind => $rewards) {
            $promoKind = PromoKind::from($kind);
            $text = $this->pickListFormatter->format($rewards, $products, $promoKind);

            if ($text !== '') {
                $pickLists[$this->orderTypeFor($promoKind)] = $text;
            }
        }

        return $pickLists;
    }

    private function orderTypeFor(PromoKind $kind): string
    {
        return match ($kind) {
            PromoKind::ACCOUNTABLE => OrderType::PROMO->value,
            PromoKind::SAMPLE => OrderType::PROMO_SAMPLE->value,
        };
    }

    /**
     * «Доберите на 11 600 ₽» — прямая польза партнёру: он покажет это в своей
     * системе. Отдаётся даже когда ничего не начислено.
     *
     * @param  list<NearMiss>  $nearMiss
     * @param  array<int, PromotionRule>  $rules
     * @return list<array<string, mixed>>
     */
    private function nearMissBlock(array $nearMiss, array $rules): array
    {
        return array_map(function (NearMiss $miss) use ($rules) {
            $missing = [
                'type' => $miss->aggregate,
                'value' => $miss->remaining(),
            ];

            // Пороги правил заданы в рублях независимо от валюты клиента
            if ($miss->aggregate === PromotionRule::AGGREGATE_AMOUNT) {
                $missing['currency'] = 'RUB';
            }

            return [
                'rule_id' => $miss->ruleId,
                'promotion' => $miss->ruleName,
                'missing' => $missing,
                'reward' => $this->rewardText($rules[$miss->ruleId] ?? null),
                'message' => $this->describer->nearMissMessage($miss->remaining(), $miss->aggregate),
            ];
        }, $nearMiss);
    }

    /**
     * Награды с выбором: условие выполнено, но выбрать вариант за клиента нельзя.
     *
     * @param  list<AppliedReward>  $rewards
     * @param  array<int, PromotionRule>  $rules
     * @return list<array<string, mixed>>
     */
    private function choiceBlock(array $rewards, array $rules): array
    {
        return array_map(fn (AppliedReward $reward) => [
            'rule_id' => $reward->ruleId,
            'promotion' => $reward->ruleName,
            'missing' => ['type' => 'choice'],
            'reward' => $this->rewardText($rules[$reward->ruleId] ?? null),
            'message' => 'Выбор промо-позиции доступен только на сайте',
        ], $rewards);
    }

    private function rewardText(?PromotionRule $rule): ?string
    {
        return $rule !== null ? $this->describer->rewardSummary($rule) : null;
    }
}
