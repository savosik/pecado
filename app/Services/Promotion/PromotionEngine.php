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
use App\Services\Promotion\DTO\RulePreview;
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

    /**
     * Прогон одного правила по контексту — для админского предпросмотра.
     *
     * В отличие от evaluate(), правило берётся напрямую, а не из кэша активных:
     * маркетологу нужно проверить настройку до включения. Гейты (выключено,
     * не начато, не тот канал, аудитория) не отбрасывают правило, а возвращаются
     * рядом с результатом — это и есть ответ на вопрос «почему не сработало».
     */
    public function explain(PromotionRule $rule, PromoContext $context): RulePreview
    {
        $lines = $context->countableLines();
        $totals = $lines === [] ? [] : $this->lineTotals($lines, $context);
        $conditionSets = $this->conditionProductSets(collect([$rule]), array_keys($totals));

        $outcome = $this->evaluateConditions($rule, $totals, $conditionSets);

        $conditions = [];
        foreach ($outcome['items'] as $index => $item) {
            $conditions[] = [
                'index' => (int) $index,
                'aggregate' => $item['aggregate'],
                'operator' => $item['operator'],
                'value' => $item['value'],
                'target' => $item['target'],
                'satisfied' => $item['satisfied'],
                'remaining' => round(max(0, $item['target'] - $item['value']), 2),
                'per_value' => $item['per_value'],
                // Вклад позиции в кратность — видно, откуда взялось число наград
                'multiplier' => $item['satisfied'] && $item['per_value'] !== null
                    ? (int) floor($item['value'] / $item['per_value'])
                    : null,
            ];
        }

        $applied = [];
        $blocked = [];

        if ($outcome['fired']) {
            [$applied, $blocked] = $this->buildRewards($rule, $outcome, $context);
        }

        return new RulePreview(
            ruleId: $rule->id,
            ruleName: $rule->name,
            fired: $outcome['fired'],
            conditionsMode: ($rule->conditions['mode'] ?? 'all') === 'any' ? 'any' : 'all',
            conditions: $conditions,
            applied: $applied,
            blocked: $blocked,
            ruleBlock: $this->ruleBlockReason($rule, $context),
            audienceMatches: $this->matchesAudience($rule, $context),
            isActive: (bool) $rule->is_active,
            inPeriod: $this->inPeriod($rule),
            appliesToChannel: $rule->appliesToChannel($context->channel),
            lineCount: count($lines),
        );
    }

    /**
     * Попадает ли текущий момент в период действия правила (без учёта is_active).
     */
    private function inPeriod(PromotionRule $rule): bool
    {
        $now = now();

        if ($rule->starts_at && $rule->starts_at->greaterThan($now)) {
            return false;
        }

        return ! ($rule->ends_at && $rule->ends_at->lessThan($now));
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
     * @return array{fired: bool, items: array<int, array{value: float, target: float, aggregate: string, operator: string, satisfied: bool, per_value: float|null}>}
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

            // Шаг кратности прямо в позиции условия: у каждого SKU он свой
            $perValue = isset($item['per_value']) && (float) $item['per_value'] > 0
                ? (float) $item['per_value']
                : null;

            $evaluated[$index] = [
                'value' => $value,
                'target' => $target,
                'aggregate' => $aggregate,
                'operator' => $operator,
                'satisfied' => $this->compare($value, $operator, $target),
                'per_value' => $perValue,
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
     * Исключение — селектор из одних товаров: раскрывать нечего, достаточно
     * пересечь его с корзиной. Это основной случай правил с десятком позиций
     * (таблица «артикул → кратность»), и без него они стоили бы запрос на позицию.
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

        if ($products = $this->productsOnlySelector($selector)) {
            return array_values(array_intersect($products, $cartProductIds));
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

    /**
     * Список товаров, если селектор состоит только из них. Иначе — пустой массив.
     *
     * @param  array<string, mixed>  $selector
     * @return int[]
     */
    private function productsOnlySelector(array $selector): array
    {
        foreach (['categories', 'brands', 'tags', 'erp_promotions'] as $key) {
            if (! empty($selector[$key])) {
                return [];
            }
        }

        return array_values(array_unique(array_filter(
            array_map('intval', (array) ($selector['products'] ?? [])),
            fn (int $id) => $id > 0,
        )));
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

        foreach (array_values($rule->rewards ?? []) as $index => $reward) {
            $reward = (array) $reward;
            $selection = $context->selectionFor($rule->id, $index);
            $productId = $this->rewardProductId($reward, $selection);

            if ($productId === null) {
                continue;
            }

            $multiplier = $this->multiplier($reward, $outcome['items']);

            // Условие выполнено, а выдать нечего — молчать нельзя, иначе правило
            // выглядит сломанным: сработало, награды нет, причины нет
            if ($multiplier < 1) {
                $blocked[] = new BlockedReward(
                    $rule->id,
                    $rule->name,
                    $index,
                    $productId,
                    PromoBlockReason::MULTIPLIER_NOT_REACHED,
                );

                continue;
            }

            $price = round((float) ($reward['price'] ?? 0), 2);
            // Бесплатную позицию не отклоняем — флаг optional для неё не имеет смысла
            $optional = $price > 0 && (bool) ($reward['optional'] ?? true);
            $declined = $optional && (bool) ($selection['declined'] ?? false);
            $quantity = max(1, (int) ($reward['quantity'] ?? 1)) * $multiplier;
            $warehouseId = ! empty($reward['warehouse_id']) ? (int) $reward['warehouse_id'] : null;

            if (! $declined) {
                $available = $this->stock->availableFor($productId, $warehouseId, $context->user?->id);

                if ($available < 1) {
                    $blocked[] = new BlockedReward($rule->id, $rule->name, $index, $productId, PromoBlockReason::OUT_OF_STOCK);

                    continue;
                }

                // Остатка не хватает на всю кратность — выдаём сколько есть.
                // «Положено 5, на складе 2» превращается в 2, а не в ноль:
                // урезанная награда клиенту полезнее отсутствующей
                $quantity = min($quantity, $available);
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
     * Шаг кратности живёт в позиции условия: у каждой он свой, и вклады
     * складываются — «кратности разных артикулов считаются отдельно». Так акция
     * вида «за каждые 4 шт. одного SKU и каждые 6 шт. другого» укладывается
     * в одно правило.
     *
     * Считаем только по выполненным условиям: невыполненное могло набрать
     * половину порога, и засчитывать её как кратность нельзя.
     *
     * Ноль здесь — не ошибка расчёта, а сигнал: награда уходит в blocked
     * с причиной MULTIPLIER_NOT_REACHED, чтобы правило не молчало.
     *
     * @param  array<string, mixed>  $reward
     * @param  array<int, array{value: float, satisfied: bool, per_value: float|null}>  $items
     */
    private function multiplier(array $reward, array $items): int
    {
        if (($reward['multiply'] ?? PromotionRule::MULTIPLY_ONCE) !== PromotionRule::MULTIPLY_PER_THRESHOLD) {
            return 1;
        }

        $max = (int) ($reward['max_multiplier'] ?? 1);

        if ($max < 1) {
            return 0;
        }

        $total = 0;

        foreach ($items as $item) {
            if (! $item['satisfied'] || ($item['per_value'] ?? null) === null) {
                continue;
            }

            $total += (int) floor($item['value'] / $item['per_value']);
        }

        return min($total, $max);
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
