<?php

namespace App\Services\Motivation;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ведение Фокус-перечня руководителем (карточка mot-36; п. 6.4 Положения).
 *
 * Перечень ведётся правилами — бренд, категория, позиция — а не списком товаров.
 * Две даты ограничены нормой (п. 6.4.3): включение не раньше поступления первой
 * партии, исключение не раньше первого числа следующего периода. Утверждённый
 * период читается по снимку состава, поэтому правка правил его не меняет.
 */
class FocusRuleService
{
    private const RETURNS_MONTHS = 6;

    public function __construct(
        private readonly FocusRangeResolver $resolver,
        private readonly ParameterOrderService $orders,
        private readonly PartnerAttributionResolver $attribution,
        private readonly ShipmentAnalyticsService $analytics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $today = CarbonImmutable::today();
        $rateP3 = $this->rateP3($period);

        $rules = MotivationFocusRule::query()->with('author:id,name')->orderByDesc('starts_on')->orderByDesc('id')->get();
        $targets = $this->targetNames($rules);

        $ruleRows = $rules->map(function (MotivationFocusRule $rule) use ($targets, $today): array {
            $status = $rule->ends_on !== null && $rule->ends_on->lessThan($today) ? 'expired'
                : ($rule->starts_on->greaterThan($today) ? 'scheduled' : 'active');

            return [
                'id' => (int) $rule->getKey(),
                'scope' => $rule->scope,
                'scope_label' => self::scopeLabel($rule->scope),
                'target_id' => (int) $rule->target_id,
                'target_name' => $targets[$rule->scope][(int) $rule->target_id] ?? ('#'.$rule->target_id),
                'rate' => $rule->rate === null ? null : (float) $rule->rate,
                'starts_on' => $rule->starts_on->toDateString(),
                'ends_on' => $rule->ends_on?->toDateString(),
                'order_number' => $rule->order_number,
                'order_date' => $rule->order_date?->toDateString(),
                'comment' => $rule->comment,
                'author' => $rule->author === null ? null : (string) $rule->author->name,
                'status' => $status,
                'status_label' => ['active' => 'действует', 'scheduled' => 'с '.$rule->starts_on->format('d.m.Y'), 'expired' => 'закрыто'][$status],
                'min_end_on' => $today->endOfMonth()->toDateString(),
            ];
        })->all();

        return [
            'month' => $period->toDateString(),
            'rate_p3' => $rateP3,
            'rules' => $ruleRows,
            'composition' => $this->composition($period, $rateP3),
            'frozen_months' => MotivationFocusSnapshotItem::query()->selectRaw('period_month')->distinct()->orderByDesc('period_month')->pluck('period_month')
                ->map(fn ($d): string => CarbonImmutable::parse((string) $d)->format('Y-m-01'))->all(),
            'returns' => $this->returns($period),
            'note' => 'При нынешнем объёме продаж собственных марок показатель П3 обходится компании в сотни рублей в месяц на отдел. Блок «Отдача» показывает это прямо.',
        ];
    }

    /**
     * Поиск цели правила: бренд, категория или позиция — с датой первой партии.
     *
     * @return list<array{id: int, name: string, hint: string|null, first_arrival_on: string|null}>
     */
    public function search(string $scope, string $query, int $limit = 20): array
    {
        $query = trim($query);

        return match ($scope) {
            MotivationFocusRule::SCOPE_BRAND => Brand::query()
                ->when($query !== '', fn ($q) => $q->where('name', 'like', "%{$query}%"))
                ->orderBy('name')->limit($limit)->get(['id', 'name'])
                ->map(fn (Brand $b): array => [
                    'id' => (int) $b->getKey(),
                    'name' => (string) $b->name,
                    'hint' => sprintf('%d поз.', Product::query()->where('brand_id', $b->getKey())->count()),
                    'first_arrival_on' => $this->firstArrival(MotivationFocusRule::SCOPE_BRAND, (int) $b->getKey())?->toDateString(),
                ])->all(),
            MotivationFocusRule::SCOPE_CATEGORY => Category::query()
                ->when($query !== '', fn ($q) => $q->where('name', 'like', "%{$query}%"))
                ->orderBy('name')->limit($limit)->get(['id', 'name', 'parent_id'])
                ->map(fn (Category $c): array => [
                    'id' => (int) $c->getKey(),
                    'name' => (string) $c->name,
                    'hint' => $c->parent_id === null ? 'корневая' : null,
                    'first_arrival_on' => $this->firstArrival(MotivationFocusRule::SCOPE_CATEGORY, (int) $c->getKey())?->toDateString(),
                ])->all(),
            MotivationFocusRule::SCOPE_PRODUCT => Product::query()
                ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$query}%")->orWhere('sku', 'like', "%{$query}%")))
                ->orderBy('name')->limit($limit)->get(['id', 'name', 'sku'])
                ->map(fn (Product $p): array => [
                    'id' => (int) $p->getKey(),
                    'name' => (string) $p->name,
                    'hint' => $p->sku === null ? null : (string) $p->sku,
                    'first_arrival_on' => $this->firstArrival(MotivationFocusRule::SCOPE_PRODUCT, (int) $p->getKey())?->toDateString(),
                ])->all(),
            default => [],
        };
    }

    /**
     * Завести правило.
     *
     * @param  array<string, mixed>  $data  scope, target_id, rate?, starts_on, ends_on?, order_number?, order_date?, comment?
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $data, User $actor): MotivationFocusRule
    {
        $scope = (string) $data['scope'];
        $targetId = (int) $data['target_id'];
        $startsOn = CarbonImmutable::parse((string) $data['starts_on'])->startOfDay();
        $endsOn = empty($data['ends_on']) ? null : CarbonImmutable::parse((string) $data['ends_on'])->startOfDay();

        if (! in_array($scope, [MotivationFocusRule::SCOPE_BRAND, MotivationFocusRule::SCOPE_CATEGORY, MotivationFocusRule::SCOPE_PRODUCT], true)) {
            throw new \InvalidArgumentException('Неизвестный вид правила.');
        }

        if (! $this->targetExists($scope, $targetId)) {
            throw new \InvalidArgumentException('Цель правила не найдена: '.self::scopeLabel($scope).' #'.$targetId.'.');
        }

        $arrival = $this->firstArrival($scope, $targetId);
        if ($arrival !== null && $startsOn->lessThan($arrival)) {
            throw new \InvalidArgumentException(sprintf('Включить в перечень можно не раньше поступления первой партии — %s (п. 6.4.3).', $arrival->format('d.m.Y')));
        }

        $this->assertPeriodOpen($startsOn, 'Включение');

        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw new \InvalidArgumentException('Дата исключения раньше даты включения.');
        }

        if ($endsOn !== null) {
            $this->assertEndAllowed($endsOn);
        }

        $rate = array_key_exists('rate', $data) && $data['rate'] !== null && $data['rate'] !== '' ? (float) $data['rate'] : null;
        if ($rate !== null && ($rate <= 0 || $rate > 1)) {
            throw new \InvalidArgumentException('Ставка задаётся долей от 1: например 0,03 для 3 %.');
        }

        return MotivationFocusRule::query()->create([
            'scope' => $scope,
            'target_id' => $targetId,
            'rate' => $rate,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'order_number' => $data['order_number'] ?? null,
            'order_date' => empty($data['order_date']) ? null : CarbonImmutable::parse((string) $data['order_date'])->toDateString(),
            'comment' => $data['comment'] ?? null,
            'author_id' => $actor->getKey(),
        ]);
    }

    /**
     * Исключить из перечня: закрыть правило датой (п. 6.4.3 — не раньше первого числа
     * следующего периода, то есть по конец текущего месяца включительно).
     *
     * @throws \InvalidArgumentException
     */
    public function close(MotivationFocusRule $rule, CarbonInterface $endsOn): MotivationFocusRule
    {
        $endsOn = CarbonImmutable::instance($endsOn)->startOfDay();

        if ($endsOn->lessThan($rule->starts_on)) {
            throw new \InvalidArgumentException('Дата исключения раньше даты включения.');
        }

        $this->assertEndAllowed($endsOn);

        $rule->forceFill(['ends_on' => $endsOn->toDateString()])->save();

        return $rule;
    }

    /**
     * Снимок состава на период: пишется один раз, повторный вызов ничего не меняет.
     *
     * @return int сколько позиций записано
     */
    public function freeze(CarbonInterface $month): int
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();

        if (MotivationFocusSnapshotItem::query()->forPeriod($period)->exists()) {
            return 0;
        }

        $rateP3 = $this->rateP3($period);
        $rows = [];
        $now = now();

        foreach ($this->resolver->rulesByProduct($period) as $productId => $rule) {
            $rows[] = [
                'period_month' => $period->toDateString(),
                'product_id' => $productId,
                'rule_id' => $rule->getKey(),
                'rate' => $rule->rate === null ? $rateP3 : (float) $rule->rate,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MotivationFocusSnapshotItem::query()->insert($chunk);
        }

        return count($rows);
    }

    public static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            MotivationFocusRule::SCOPE_BRAND => 'бренд',
            MotivationFocusRule::SCOPE_CATEGORY => 'категория',
            MotivationFocusRule::SCOPE_PRODUCT => 'позиция',
            default => $scope,
        };
    }

    /**
     * Состав перечня на период: из снимка, если он есть, иначе из правил.
     *
     * @return array{frozen: bool, items: list<array<string, mixed>>}
     */
    private function composition(CarbonImmutable $period, float $rateP3): array
    {
        $snapshot = MotivationFocusSnapshotItem::query()->forPeriod($period)->get(['product_id', 'rule_id', 'rate']);
        $frozen = $snapshot->isNotEmpty();

        if ($frozen) {
            $byProduct = [];
            foreach ($snapshot as $row) {
                $byProduct[(int) $row->product_id] = ['rule_id' => $row->rule_id === null ? null : (int) $row->rule_id, 'rate' => (float) $row->rate];
            }
        } else {
            $byProduct = [];
            foreach ($this->resolver->rulesByProduct($period) as $productId => $rule) {
                $byProduct[$productId] = ['rule_id' => (int) $rule->getKey(), 'rate' => $rule->rate === null ? $rateP3 : (float) $rule->rate];
            }
        }

        $productIds = array_keys($byProduct);
        $products = $productIds === [] ? [] : Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'sku'])->keyBy('id')->all();
        $stock = $productIds === [] ? [] : DB::table('product_warehouse')->whereIn('product_id', $productIds)->groupBy('product_id')
            ->pluck(DB::raw('SUM(quantity) AS total'), 'product_id')->map(fn ($q): int => (int) $q)->all();
        $ruleIds = array_values(array_unique(array_filter(array_map(fn (array $r): ?int => $r['rule_id'], $byProduct))));
        $rules = $ruleIds === [] ? collect() : MotivationFocusRule::query()->whereIn('id', $ruleIds)->get();
        $targets = $this->targetNames($rules);
        $ruleLabels = [];
        foreach ($rules as $rule) {
            $ruleLabels[(int) $rule->getKey()] = self::scopeLabel($rule->scope).' «'.($targets[$rule->scope][(int) $rule->target_id] ?? ('#'.$rule->target_id)).'»';
        }

        $items = [];
        foreach ($byProduct as $productId => $meta) {
            $product = $products[$productId] ?? null;
            $items[] = [
                'id' => $productId,
                'name' => $product === null ? ('#'.$productId) : (string) $product->name,
                'sku' => $product?->sku,
                'rate' => $meta['rate'],
                'own_rate' => abs($meta['rate'] - $rateP3) >= 0.000005,
                'rule_id' => $meta['rule_id'],
                'rule_label' => $meta['rule_id'] === null ? 'правило удалено' : ($ruleLabels[$meta['rule_id']] ?? 'правило #'.$meta['rule_id']),
                'stock' => $stock[$productId] ?? 0,
            ];
        }

        usort($items, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['frozen' => $frozen, 'items' => $items];
    }

    /**
     * Отдача: отгрузки перечня, начислено работникам, сколько партнёров берёт — по месяцам.
     *
     * @return list<array{month: string, shipped: float, accrued: float, partners: int, frozen: bool}>
     */
    private function returns(CarbonImmutable $period): array
    {
        $managers = PersonalManager::query()->active()->where('payroll_enabled', true)->pluck('id')->map('intval')->all();
        $rows = [];

        for ($i = self::RETURNS_MONTHS - 1; $i >= 0; $i--) {
            $month = $period->subMonths($i);
            $items = $this->resolver->itemsFor($month);
            $partnerIds = [];
            foreach ($managers as $managerId) {
                $partnerIds += $this->attribution->partnersOf($managerId, $month->endOfMonth());
            }

            $shipped = 0.0;
            $partners = 0;
            if ($items !== [] && $partnerIds !== []) {
                $ctx = AnalyticsContext::forScope(array_keys($partnerIds), AnalyticsContext::DATE_ERP, null);
                foreach ($this->analytics->byPartner($ctx, new AnalyticsFilters(
                    dateFrom: $month->startOfDay(),
                    dateTo: $month->endOfMonth()->endOfDay(),
                    productIds: array_keys($items),
                ), null) as $row) {
                    if ((float) $row['amount'] > 0) {
                        $shipped += (float) $row['amount'];
                        $partners++;
                    }
                }
            }

            $accrued = 0.0;
            foreach ($managers as $managerId) {
                $calculation = PayrollCalculation::latestFor($managerId, $month);
                if ($calculation === null) {
                    continue;
                }
                foreach ((array) data_get($calculation->breakdown, 'components', []) as $component) {
                    if (is_array($component) && ($component['key'] ?? null) === 'motivation_variable') {
                        foreach ((array) ($component['children'] ?? []) as $child) {
                            if (is_array($child) && ($child['key'] ?? null) === 'p3') {
                                $accrued += (float) ($child['amount'] ?? 0);
                            }
                        }
                    }
                }
            }

            $rows[] = [
                'month' => $month->toDateString(),
                'shipped' => Money::round($shipped),
                'accrued' => Money::round($accrued),
                'partners' => $partners,
                'items' => count($items),
                'frozen' => MotivationFocusSnapshotItem::query()->forPeriod($month)->exists(),
            ];
        }

        return $rows;
    }

    private function rateP3(CarbonImmutable $period): float
    {
        return (float) ($this->orders->effective($period)['values']['rate_p3'] ?? config('motivation.default_parameters.rate_p3', 0));
    }

    /**
     * Дата поступления первой партии: первое появление в продаже, иначе первая
     * запись остатка, иначе заведение карточки. Для бренда и категории — самая
     * ранняя по входящим позициям.
     */
    private function firstArrival(string $scope, int $targetId): ?CarbonImmutable
    {
        $productIds = match ($scope) {
            MotivationFocusRule::SCOPE_PRODUCT => [$targetId],
            MotivationFocusRule::SCOPE_BRAND => Product::query()->where('brand_id', $targetId)->pluck('id')->map('intval')->all(),
            MotivationFocusRule::SCOPE_CATEGORY => Product::query()->whereIn('category_id', Category::query()->descendantsAndSelf($targetId)->pluck('id')->all() ?: [$targetId])->pluck('id')->map('intval')->all(),
            default => [],
        };

        if ($productIds === []) {
            return null;
        }

        $candidates = array_filter([
            ProductAvailabilityEvent::query()->whereIn('product_id', $productIds)->where('event', ProductAvailabilityEvent::IN_STOCK)->min('happened_at'),
            DB::table('product_warehouse')->whereIn('product_id', $productIds)->where('quantity', '>', 0)->min('created_at'),
            Product::query()->whereIn('id', $productIds)->min('created_at'),
        ]);

        if ($candidates === []) {
            return null;
        }

        return CarbonImmutable::parse((string) min(array_map('strval', $candidates)))->startOfDay();
    }

    private function targetExists(string $scope, int $targetId): bool
    {
        return match ($scope) {
            MotivationFocusRule::SCOPE_BRAND => Brand::query()->whereKey($targetId)->exists(),
            MotivationFocusRule::SCOPE_CATEGORY => Category::query()->whereKey($targetId)->exists(),
            MotivationFocusRule::SCOPE_PRODUCT => Product::query()->whereKey($targetId)->exists(),
            default => false,
        };
    }

    /**
     * Утверждённый период менять нельзя: включение датой внутри него отклоняется.
     */
    private function assertPeriodOpen(CarbonImmutable $day, string $action): void
    {
        $month = $day->startOfMonth();
        $approved = PayrollCalculation::query()->forPeriod($month)->where('status', '<>', PayrollCalculation::STATUS_DRAFT)->exists()
            || MotivationFocusSnapshotItem::query()->forPeriod($month)->exists();

        if ($approved) {
            throw new \InvalidArgumentException(sprintf('%s датой внутри утверждённого периода невозможно: состав %s заморожен. Укажите дату с %s.', $action, $month->translatedFormat('F Y'), $month->addMonth()->format('d.m.Y')));
        }
    }

    /**
     * Исключение — не раньше первого числа следующего периода (п. 6.4.3).
     */
    private function assertEndAllowed(CarbonImmutable $endsOn): void
    {
        $minEnd = CarbonImmutable::today()->endOfMonth()->startOfDay();

        if ($endsOn->lessThan($minEnd)) {
            throw new \InvalidArgumentException(sprintf('Исключить из перечня можно не раньше первого числа следующего периода: дата окончания — не ранее %s (п. 6.4.3).', $minEnd->format('d.m.Y')));
        }
    }

    /**
     * Названия целей правил по видам.
     *
     * @param  \Illuminate\Support\Collection<int, MotivationFocusRule>  $rules
     * @return array<string, array<int, string>>
     */
    private function targetNames(\Illuminate\Support\Collection $rules): array
    {
        $ids = [];
        foreach ($rules as $rule) {
            $ids[$rule->scope][] = (int) $rule->target_id;
        }

        return [
            MotivationFocusRule::SCOPE_BRAND => empty($ids['brand']) ? [] : Brand::query()->whereIn('id', $ids['brand'])->pluck('name', 'id')->map('strval')->all(),
            MotivationFocusRule::SCOPE_CATEGORY => empty($ids['category']) ? [] : Category::query()->whereIn('id', $ids['category'])->pluck('name', 'id')->map('strval')->all(),
            MotivationFocusRule::SCOPE_PRODUCT => empty($ids['product']) ? [] : Product::query()->whereIn('id', $ids['product'])->pluck('name', 'id')->map('strval')->all(),
        ];
    }
}
