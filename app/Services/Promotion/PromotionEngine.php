<?php

namespace App\Services\Promotion;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Contracts\Promotion\PromoUsageCounterInterface;
use App\Enums\PromoBlockReason;
use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Scopes\HiddenScope;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\DTO\BlockedReward;
use App\Services\Promotion\DTO\NearMiss;
use App\Services\Promotion\DTO\PromoContext;
use App\Services\Promotion\DTO\PromoContextLine;
use App\Services\Promotion\DTO\PromotionEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт акций по снапшоту позиций.
 *
 * Один движок обслуживает корзину, чекаут и клиентское API: на вход — PromoContext,
 * на выход — что выдаётся, до чего не хватило и что помешало выдать.
 *
 * Свойства, на которые опирается всё остальное:
 *  - **детерминированность** — один контекст всегда даёт один результат (правила
 *    упорядочены по priority DESC, id ASC; награды — по индексу в массиве);
 *  - **промо-строки не участвуют в агрегатах** — иначе платная промо-позиция за 40 ₽
 *    попадёт в сумму корзины и потянет следующее правило;
 *  - **число запросов не зависит от размера корзины** — правила из кэша, участники
 *    одним запросом, цены батчем.
 */
class PromotionEngine
{
    /**
     * С какой доли выполнения порога показываем подсказку «доберите на …».
     * Ниже — правило клиенту не показываем: он к нему и не шёл.
     */
    public const NEAR_MISS_MIN_PROGRESS = 0.25;

    private ?Currency $baseCurrency = null;

    public function __construct(
        private readonly ActivePromotionRuleCache $ruleCache,
        private readonly PriceServiceInterface $prices,
        private readonly PromotionRuleProductResolver $resolver,
        private readonly PromoStockCheckerInterface $stock,
        private readonly PromoUsageCounterInterface $usage,
        private readonly CurrencyConversionServiceInterface $conversion,
    ) {}

    public function evaluate(PromoContext $context): PromotionEvaluation
    {
        $lines = $context->countableLines();

        // Пустая корзина — быстрый выход, правила даже не грузим
        if ($lines === []) {
            return PromotionEvaluation::empty();
        }

        $rules = $this->ruleCache->activeAt()
            ->filter(fn (PromotionRule $rule) => $this->matchesAudience($rule, $context))
            ->values();

        if ($rules->isEmpty()) {
            return PromotionEvaluation::empty();
        }

        $totals = $this->lineTotals($lines, $context);
        $conditionSets = $this->conditionProductSets($rules, array_keys($totals));

        $fired = [];
        $nearMiss = [];
        $blocked = [];

        foreach ($rules as $rule) {
            $outcome = $this->evaluateConditions($rule, $totals, $conditionSets);

            if (! $outcome['fired']) {
                if ($miss = $this->nearMissFor($rule, $outcome, $context)) {
                    $nearMiss[] = $miss;
                }

                continue;
            }

            $reason = $this->ruleBlockReason($rule, $context);

            if ($reason !== null) {
                $blocked[] = new BlockedReward($rule->id, $rule->name, null, null, $reason);

                continue;
            }

            if ($context->mode !== null && $rule->mode !== $context->mode) {
                // Обратный случай (правило issue при запросе info) — просто не наш выбор
                continue;
            }

            $fired[] = ['rule' => $rule, 'outcome' => $outcome];
        }

        $applied = [];

        foreach ($this->resolveConflicts($fired) as $entry) {
            [$rewards, $rewardBlocks] = $this->buildRewards($entry['rule'], $entry['outcome'], $context);

            $applied = array_merge($applied, $rewards);
            $blocked = array_merge($blocked, $rewardBlocks);
        }

        return new PromotionEvaluation($applied, $nearMiss, $blocked);
    }

    // ────────────────────────────────────────────
    // Позиции и цены
    // ────────────────────────────────────────────

    /**
     * Свернуть строки в агрегаты по товарам: штуки и сумма в рублях.
     *
     * Цену берём из строки, если она задана (уценка, клиентское API), иначе считаем
     * финальную цену клиента одним батчем: индивидуальные цены живут в отдельной БД,
     * и построчный getUserPrice() дал бы кросс-БД запрос на каждую позицию.
     *
     * @param  PromoContextLine[]  $lines
     * @return array<int, array{quantity: int, amount: float}>
     */
    private function lineTotals(array $lines, PromoContext $context): array
    {
        $needPrice = [];

        foreach ($lines as $line) {
            if ($line->unitPrice === null) {
                $needPrice[$line->productId] = true;
            }
        }

        $priceMap = [];

        if ($needPrice !== []) {
            $products = Product::withoutGlobalScope(HiddenScope::class)
                ->whereIn('id', array_keys($needPrice))
                ->get();

            foreach ($this->prices->getPriceMapForProducts($products, $context->user) as $productId => $priceResult) {
                $priceMap[(int) $productId] = $priceResult->getDisplayPrice();
            }
        }

        $totals = [];

        foreach ($lines as $line) {
            $unitPrice = $line->unitPrice !== null
                ? $this->toRubles($line->unitPrice, $context->currency)
                : ($priceMap[$line->productId] ?? 0.0);

            $totals[$line->productId] ??= ['quantity' => 0, 'amount' => 0.0];
            $totals[$line->productId]['quantity'] += $line->quantity;
            $totals[$line->productId]['amount'] += $line->quantity * $unitPrice;
        }

        return $totals;
    }

    /**
     * Пороги правил заданы в рублях, поэтому в рубли переводим сумму корзины,
     * а не порог: иначе порог поплывёт вместе с курсом.
     */
    private function toRubles(float $amount, ?Currency $currency): float
    {
        if ($currency === null || $currency->is_base) {
            return $amount;
        }

        $base = $this->baseCurrency ??= Currency::query()->where('is_base', true)->first();

        return $base
            ? $this->conversion->convert($amount, $currency, $base)
            : $amount * (float) $currency->exchange_rate;
    }

    /**
     * Материализованные участники условий — одним запросом на все правила,
     * сразу ограниченные товарами из корзины.
     *
     * @param  Collection<int, PromotionRule>  $rules
     * @param  int[]  $productIds
     * @return array<int, int[]> ruleId → product_id[]
     */
    private function conditionProductSets(Collection $rules, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $sets = [];

        $rows = DB::table('promotion_rule_product')
            ->whereIn('promotion_rule_id', $rules->pluck('id')->all())
            ->where('role', PromotionRule::ROLE_CONDITION)
            ->whereIn('product_id', $productIds)
            ->get(['promotion_rule_id', 'product_id']);

        foreach ($rows as $row) {
            $sets[(int) $row->promotion_rule_id][] = (int) $row->product_id;
        }

        return $sets;
    }

    // ────────────────────────────────────────────
    // Условия
    // ────────────────────────────────────────────

    /**
     * @param  array<int, array{quantity: int, amount: float}>  $totals
     * @param  array<int, int[]>  $conditionSets
     * @return array{fired: bool, items: array<int, array{value: float, target: float, aggregate: string, operator: string, satisfied: bool}>}
     */
    private function evaluateConditions(PromotionRule $rule, array $totals, array $conditionSets): array
    {
        $conditions = $rule->conditions ?? [];
        $items = array_values($conditions['items'] ?? []);
        $mode = ($conditions['mode'] ?? 'all') === 'any' ? 'any' : 'all';

        if ($items === []) {
            return ['fired' => false, 'items' => []];
        }

        $evaluated = [];

        foreach ($items as $index => $item) {
            $selector = (array) ($item['selector'] ?? []);
            $aggregate = ($item['aggregate'] ?? PromotionRule::AGGREGATE_QUANTITY) === PromotionRule::AGGREGATE_AMOUNT
                ? PromotionRule::AGGREGATE_AMOUNT
                : PromotionRule::AGGREGATE_QUANTITY;
            $operator = (string) ($item['operator'] ?? '>=');
            $target = (float) ($item['value'] ?? 0);

            $productIds = $this->productsForItem($rule, $selector, count($items), $conditionSets, array_keys($totals));

            $value = 0.0;
            foreach ($productIds as $productId) {
                if (! isset($totals[$productId])) {
                    continue;
                }

                $value += $aggregate === PromotionRule::AGGREGATE_AMOUNT
                    ? $totals[$productId]['amount']
                    : $totals[$productId]['quantity'];
            }

            $value = round($value, 2);

            $evaluated[$index] = [
                'value' => $value,
                'target' => $target,
                'aggregate' => $aggregate,
                'operator' => $operator,
                'satisfied' => $this->compare($value, $operator, $target),
            ];
        }

        $satisfied = array_column($evaluated, 'satisfied');

        $fired = $mode === 'any'
            ? in_array(true, $satisfied, true)
            : ! in_array(false, $satisfied, true);

        return ['fired' => $fired, 'items' => $evaluated];
    }

    /**
     * Какие товары корзины попадают под селектор одного условия.
     *
     * Для правила с единственным условием берём материализованный список — он ровно
     * этому условию и соответствует. Для правил с несколькими условиями pivot не
     * различает, из какого условия пришёл товар, поэтому селектор раскрывается
     * запросом, ограниченным товарами корзины (стоимость зависит от числа условий,
     * а не от размера корзины).
     *
     * @param  array<string, mixed>  $selector
     * @param  array<int, int[]>  $conditionSets
     * @param  int[]  $cartProductIds
     * @return int[]
     */
    private function productsForItem(
        PromotionRule $rule,
        array $selector,
        int $itemCount,
        array $conditionSets,
        array $cartProductIds,
    ): array {
        if (! empty($selector['whole_cart'])) {
            return $cartProductIds;
        }

        if ($itemCount === 1) {
            return $conditionSets[$rule->id] ?? [];
        }

        $query = $this->resolver->selectorQuery($selector);

        if ($query === null) {
            return [];
        }

        return $query->whereIn('products.id', $cartProductIds)
            ->pluck('products.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function compare(float $value, string $operator, float $target): bool
    {
        return match ($operator) {
            '>' => $value > $target,
            '=' => abs($value - $target) < 0.001,
            '<' => $value < $target,
            '<=' => $value <= $target,
            default => $value >= $target,
        };
    }

    // ────────────────────────────────────────────
    // Гейты правила
    // ────────────────────────────────────────────

    private function matchesAudience(PromotionRule $rule, PromoContext $context): bool
    {
        $audience = $rule->audience ?? [];
        $user = $context->user;

        foreach ([
            'region_ids' => $user?->region_id,
            'user_ids' => $user?->id,
            'manager_ids' => $user?->personal_manager_id,
        ] as $key => $value) {
            $allowed = array_values(array_filter(array_map('intval', (array) ($audience[$key] ?? []))));

            if ($allowed === []) {
                continue;
            }

            // Ограничение задано, а у гостя такого признака нет — правило не для него
            if ($value === null || ! in_array((int) $value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Почему сработавшее правило не может выдать награду. NULL — может.
     */
    private function ruleBlockReason(PromotionRule $rule, PromoContext $context): ?PromoBlockReason
    {
        if (! $rule->appliesToChannel($context->channel)) {
            return PromoBlockReason::WRONG_CHANNEL;
        }

        $limits = $rule->limits ?? [];

        $total = $limits['total'] ?? null;
        if ($total !== null && $this->usage->totalUsage($rule->id) >= (int) $total) {
            return PromoBlockReason::TOTAL_LIMIT;
        }

        $perClient = $limits['per_client_total'] ?? null;
        if ($perClient !== null && $this->usage->clientUsage($rule->id, $context->user?->id) >= (int) $perClient) {
            return PromoBlockReason::CLIENT_LIMIT;
        }

        // Запросили выдачу, а правило ещё в режиме показа
        if ($context->mode === PromotionRuleMode::ISSUE && $rule->mode === PromotionRuleMode::INFO) {
            return PromoBlockReason::NOT_ACTIVE_YET;
        }

        return null;
    }

    /**
     * Правило с stackable = false не сочетается с другими: если оно выиграло по
     * приоритету — остаётся одно, если проиграло — не применяется вовсе.
     *
     * @param  array<int, array{rule: PromotionRule, outcome: array}>  $fired
     * @return array<int, array{rule: PromotionRule, outcome: array}>
     */
    private function resolveConflicts(array $fired): array
    {
        usort($fired, function (array $left, array $right) {
            return [$right['rule']->priority, $left['rule']->id] <=> [$left['rule']->priority, $right['rule']->id];
        });

        $selected = [];

        foreach ($fired as $entry) {
            if ($selected === []) {
                $selected[] = $entry;

                if (! $entry['rule']->stackable) {
                    break;
                }

                continue;
            }

            if (! $entry['rule']->stackable) {
                continue;
            }

            $selected[] = $entry;
        }

        return $selected;
    }

    // ────────────────────────────────────────────
    // Награды
    // ────────────────────────────────────────────

    /**
     * @param  array{fired: bool, items: array}  $outcome
     * @return array{0: AppliedReward[], 1: BlockedReward[]}
     */
    private function buildRewards(PromotionRule $rule, array $outcome, PromoContext $context): array
    {
        $applied = [];
        $blocked = [];

        // Кратность считается по первому условию правила — оно и задаёт «на каждые N»
        $primaryValue = (float) ($outcome['items'][0]['value'] ?? 0);

        foreach (array_values($rule->rewards ?? []) as $index => $reward) {
            $reward = (array) $reward;
            $selection = $context->selectionFor($rule->id, $index);
            $productId = $this->rewardProductId($reward, $selection);

            if ($productId === null) {
                continue;
            }

            $multiplier = $this->multiplier($reward, $primaryValue);

            if ($multiplier < 1) {
                continue;
            }

            $price = round((float) ($reward['price'] ?? 0), 2);
            // Бесплатную позицию не отклоняем — флаг optional для неё не имеет смысла
            $optional = $price > 0 && (bool) ($reward['optional'] ?? true);
            $declined = $optional && (bool) ($selection['declined'] ?? false);
            $quantity = max(1, (int) ($reward['quantity'] ?? 1)) * $multiplier;
            $warehouseId = ! empty($reward['warehouse_id']) ? (int) $reward['warehouse_id'] : null;

            if (! $declined && ! $this->stock->isAvailable($productId, $warehouseId, $quantity, $context->user?->id)) {
                $blocked[] = new BlockedReward($rule->id, $rule->name, $index, $productId, PromoBlockReason::OUT_OF_STOCK);

                continue;
            }

            $applied[] = new AppliedReward(
                ruleId: $rule->id,
                ruleName: $rule->name,
                ruleMode: $rule->mode,
                rewardIndex: $index,
                productId: $productId,
                quantity: $quantity,
                price: $price,
                promoKind: PromoKind::tryFrom((string) ($reward['promo_kind'] ?? '')) ?? PromoKind::ACCOUNTABLE,
                warehouseId: $warehouseId,
                optional: $optional,
                declined: $declined,
            );
        }

        return [$applied, $blocked];
    }

    /**
     * @param  array<string, mixed>  $reward
     * @param  array{product_id?: int|null, declined?: bool}|null  $selection
     */
    private function rewardProductId(array $reward, ?array $selection): ?int
    {
        if (($reward['type'] ?? PromotionRule::REWARD_TYPE_FIXED) !== PromotionRule::REWARD_TYPE_CHOICE) {
            return ! empty($reward['product_id']) ? (int) $reward['product_id'] : null;
        }

        $choices = array_values(array_filter(array_map('intval', (array) ($reward['choices'] ?? []))));

        if ($choices === []) {
            return null;
        }

        $chosen = ! empty($selection['product_id']) ? (int) $selection['product_id'] : null;

        // Выбор вне списка игнорируем: берём первый вариант, чтобы результат был предсказуем
        return in_array($chosen, $choices, true) ? $chosen : $choices[0];
    }

    /**
     * Сколько раз выдаётся награда.
     *
     * @param  array<string, mixed>  $reward
     */
    private function multiplier(array $reward, float $aggregateValue): int
    {
        if (($reward['multiply'] ?? PromotionRule::MULTIPLY_ONCE) !== PromotionRule::MULTIPLY_PER_THRESHOLD) {
            return 1;
        }

        $perValue = (float) ($reward['per_value'] ?? 0);
        $max = (int) ($reward['max_multiplier'] ?? 1);

        if ($perValue <= 0 || $max < 1) {
            return 0;
        }

        return min((int) floor($aggregateValue / $perValue), $max);
    }

    // ────────────────────────────────────────────
    // Подсказки «доберите на …»
    // ────────────────────────────────────────────

    /**
     * @param  array{fired: bool, items: array}  $outcome
     */
    private function nearMissFor(PromotionRule $rule, array $outcome, PromoContext $context): ?NearMiss
    {
        if (! $rule->appliesToChannel($context->channel)) {
            return null;
        }

        if ($context->mode !== null && $rule->mode !== $context->mode) {
            return null;
        }

        foreach ($outcome['items'] as $item) {
            if ($item['satisfied'] || ! in_array($item['operator'], ['>=', '>'], true)) {
                continue;
            }

            // Клиент вообще не трогал акционные товары — подсказку не показываем
            if ($item['value'] <= 0 || $item['target'] <= 0) {
                continue;
            }

            $miss = new NearMiss(
                ruleId: $rule->id,
                ruleName: $rule->name,
                aggregate: $item['aggregate'],
                current: $item['value'],
                target: $item['target'],
                rewardProductIds: $this->rewardProductIdsFromConfig($rule->rewards ?? []),
            );

            return $miss->progress() >= self::NEAR_MISS_MIN_PROGRESS ? $miss : null;
        }

        return null;
    }

    /**
     * Товары наград прямо из конфигурации — без запроса в БД:
     * подсказка «доберите на …» строится на каждый рендер корзины.
     *
     * @param  array<int, mixed>  $rewards
     * @return int[]
     */
    private function rewardProductIdsFromConfig(array $rewards): array
    {
        $ids = [];

        foreach ($rewards as $reward) {
            $reward = (array) $reward;

            if (! empty($reward['product_id'])) {
                $ids[] = (int) $reward['product_id'];
            }

            foreach ((array) ($reward['choices'] ?? []) as $choice) {
                if (! empty($choice)) {
                    $ids[] = (int) $choice;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
