<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Jobs\RecalculatePromotionRuleProductsJob;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Currency;
use App\Models\ErpPromotion;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\Scopes\HiddenScope;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\DTO\PromoContext;
use App\Services\Promotion\DTO\PromoContextLine;
use App\Services\Promotion\PromotionEngine;
use App\Services\Promotion\PromotionRuleDescriber;
use App\Services\Promotion\PromotionRuleProductResolver;
use App\Services\Promotion\PromotionRuleSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Конструктор правил акций.
 *
 * Правила живут отдельно от контентной страницы акции (`PromotionController`):
 * форма акции отправляется как FormData из-за картинок, и ошибка валидации
 * механики роняла бы загрузку изображений.
 */
class PromotionRuleController extends AdminController
{
    use RedirectsAfterSave;

    public function __construct(
        private readonly PromotionRuleSchemaValidator $validator,
        private readonly PromotionRuleDescriber $describer,
        private readonly PromotionRuleProductResolver $resolver,
    ) {}

    public function index(Request $request): Response
    {
        $query = PromotionRule::query()->with('promotion:id,name,slug');

        if ($search = trim((string) $request->input('search'))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($mode = $request->input('mode')) {
            if (in_array($mode, array_column(PromotionRuleMode::cases(), 'value'), true)) {
                $query->forMode($mode);
            }
        }

        if ($promotionId = $request->input('promotion_id')) {
            $query->where('promotion_id', (int) $promotionId);
        }

        $this->applyStatusFilter($query, (string) $request->input('status', ''));

        $sortBy = (string) $request->input('sort_by', 'priority');
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'name', 'priority', 'starts_at', 'ends_at', 'created_at'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $rules = $query->paginate($perPage)->withQueryString();

        $this->describer->warmUp($rules->getCollection());

        $rules->through(fn (PromotionRule $rule) => $this->listRow($rule));

        return Inertia::render('Admin/Pages/PromotionRules/Index', [
            'rules' => $rules,
            'promotions' => Promotion::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'status' => $request->input('status', ''),
                'mode' => $request->input('mode', ''),
                'promotion_id' => $promotionId ? (int) $promotionId : null,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Pages/PromotionRules/Create', [
            'rule' => [
                'promotion_id' => $request->filled('promotion_id') ? (int) $request->input('promotion_id') : null,
                'name' => '',
                'is_active' => false,
                'mode' => PromotionRuleMode::INFO->value,
                'starts_at' => null,
                'ends_at' => null,
                'priority' => 0,
                'stackable' => true,
                'conditions' => ['mode' => 'all', 'items' => []],
                'rewards' => [],
                'audience' => ['region_ids' => [], 'user_ids' => [], 'manager_ids' => [], 'channels' => []],
                'limits' => ['per_client_total' => null, 'total' => null],
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request);

        $rule = PromotionRule::create($data);

        return $this->redirectAfterSave(
            $request,
            'admin.promotion-rules.index',
            'admin.promotion-rules.edit',
            $rule,
            'Правило акции создано',
        );
    }

    public function edit(PromotionRule $promotionRule): Response
    {
        $promotionRule->loadCount(['conditionProducts', 'rewardProducts']);

        return Inertia::render('Admin/Pages/PromotionRules/Edit', [
            'rule' => $this->formPayload($promotionRule),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, PromotionRule $promotionRule): RedirectResponse
    {
        $data = $this->validateRule($request);

        $promotionRule->update($data);

        return $this->redirectAfterSave(
            $request,
            'admin.promotion-rules.index',
            'admin.promotion-rules.edit',
            $promotionRule,
            'Правило акции обновлено',
        );
    }

    public function destroy(PromotionRule $promotionRule): RedirectResponse
    {
        $promotionRule->delete();

        return redirect()
            ->route('admin.promotion-rules.index')
            ->with('success', 'Правило акции удалено');
    }

    /**
     * Пересобрать материализованный список участников.
     *
     * Синхронно: админ нажал кнопку ради результата здесь и сейчас, а не ради
     * записи в очередь. Ночной батч делает то же самое командой promo:rebuild-rule-products.
     */
    public function rebuild(PromotionRule $promotionRule): RedirectResponse
    {
        RecalculatePromotionRuleProductsJob::dispatchSync($promotionRule->id);

        $count = $promotionRule->conditionProducts()->count();

        return redirect()
            ->back()
            ->with('success', "Список участников пересчитан: товаров в условиях — {$count}");
    }

    /**
     * Прогон правила по реальной корзине или заказу.
     *
     * Единственное место, где видно, почему правило не сработало: клиенту
     * причины не показываются никогда (решение п. 5 дорожной карты).
     */
    public function preview(Request $request, PromotionRule $promotionRule, PromotionEngine $engine): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in(['cart', 'order'])],
            'id' => ['required', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(PromotionRule::CHANNELS)],
        ]);

        $channel = $validated['channel'] ?? PromotionRule::CHANNEL_SITE;

        $context = $validated['source'] === 'cart'
            ? $this->contextFromCart((int) $validated['id'], $channel)
            : $this->contextFromOrder((int) $validated['id'], $channel);

        if ($context === null) {
            return response()->json([
                'message' => $validated['source'] === 'cart'
                    ? 'Корзина с таким ID не найдена'
                    : 'Заказ с таким ID не найден',
            ], 404);
        }

        [$promoContext, $subject] = $context;

        $preview = $engine->explain($promotionRule, $promoContext);

        $productIds = array_values(array_unique(array_merge(
            array_map(fn ($reward) => $reward->productId, $preview->applied),
            array_values(array_filter(array_map(fn ($blocked) => $blocked->productId, $preview->blocked))),
        )));

        return response()->json([
            'subject' => $subject,
            'preview' => $preview->toArray(),
            'condition_lines' => $this->describer->conditionLines($promotionRule),
            'reward_lines' => $this->describer->rewardLines($promotionRule),
            'product_names' => $this->productNames($productIds),
            'warehouse_names' => Warehouse::query()->pluck('name', 'id'),
        ]);
    }

    /**
     * Живая подсказка «Сейчас под условие подходит N товаров».
     */
    public function matchCount(Request $request): JsonResponse
    {
        $selector = (array) $request->input('selector', []);

        if (! empty($selector['whole_cart'])) {
            return response()->json([
                'count' => Product::withoutGlobalScope(HiddenScope::class)->count(),
                'whole_cart' => true,
            ]);
        }

        $query = $this->resolver->selectorQuery($selector);

        return response()->json([
            'count' => $query === null ? 0 : $query->count(),
            'whole_cart' => false,
        ]);
    }

    /**
     * Разбор таблицы «артикул → кратность», вставленной из Excel.
     *
     * Пятнадцать позиций маркетолог наберёт руками, сотню — нет. Каждая строка
     * становится отдельным условием со своим шагом кратности, поэтому здесь
     * же возвращаем нераспознанное: молча терять строки из вставки нельзя.
     */
    public function parseSkuTable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
        ], [], ['text' => 'таблица']);

        $rows = [];

        foreach (preg_split('~\R~u', $validated['text']) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            // Разделитель — табуляция (Excel), точка с запятой, запятая или пробелы
            $parts = array_values(array_filter(
                array_map('trim', (array) preg_split('~[\t;,]|\s{2,}|\s+~u', $line)),
                fn (string $part) => $part !== '',
            ));

            $sku = $parts[0] ?? '';

            if ($sku === '') {
                continue;
            }

            // Кратность — последнее число в строке; её отсутствие означает «за каждую штуку»
            $step = null;
            for ($i = count($parts) - 1; $i >= 1; $i--) {
                $candidate = str_replace(',', '.', $parts[$i]);

                if (is_numeric($candidate) && (float) $candidate > 0) {
                    $step = (float) $candidate;
                    break;
                }
            }

            if (isset($rows[mb_strtolower($sku)])) {
                continue;
            }

            $rows[mb_strtolower($sku)] = ['sku' => $sku, 'per_value' => $step ?? 1.0];
        }

        if ($rows === []) {
            return response()->json(['matched' => [], 'unknown' => []]);
        }

        $products = Product::withoutGlobalScope(HiddenScope::class)
            ->whereIn('sku', array_column($rows, 'sku'))
            ->get(['id', 'sku', 'name'])
            ->keyBy(fn (Product $product) => mb_strtolower((string) $product->sku));

        $matched = [];
        $unknown = [];

        foreach ($rows as $key => $row) {
            $product = $products->get($key);

            if (! $product) {
                $unknown[] = $row['sku'];

                continue;
            }

            $matched[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'per_value' => $row['per_value'],
            ];
        }

        return response()->json([
            'matched' => $matched,
            'unknown' => $unknown,
        ]);
    }

    // ────────────────────────────────────────────
    // Валидация и подготовка данных
    // ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'mode' => ['required', Rule::in(array_column(PromotionRuleMode::cases(), 'value'))],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'stackable' => ['boolean'],
            'conditions' => ['required', 'array'],
            'rewards' => ['required', 'array', 'min:1'],
            'audience' => ['nullable', 'array'],
            'limits' => ['nullable', 'array'],
        ], [], [
            'name' => 'название',
            'promotion_id' => 'акция',
            'mode' => 'режим',
            'starts_at' => 'начало периода',
            'ends_at' => 'конец периода',
            'priority' => 'приоритет',
            'rewards' => 'награды',
        ]);

        if ($validated['mode'] === PromotionRuleMode::ISSUE->value && ! PromotionRuleMode::issueAvailable()) {
            throw ValidationException::withMessages([
                'mode' => 'Выдача промо-позиций появится после подключения складского учёта акций. Пока доступен только режим показа.',
            ]);
        }

        $rule = [
            'name' => $validated['name'],
            'promotion_id' => $validated['promotion_id'] ?? null,
            'mode' => $validated['mode'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'priority' => (int) ($validated['priority'] ?? 0),
            'stackable' => (bool) ($validated['stackable'] ?? true),
            'conditions' => $this->normalizeConditions((array) $validated['conditions']),
            'rewards' => $this->normalizeRewards((array) $validated['rewards']),
            'audience' => $this->normalizeAudience((array) ($validated['audience'] ?? [])),
            'limits' => $this->normalizeLimits((array) ($validated['limits'] ?? [])),
        ];

        // Структуру и смысл конфигурации проверяет валидатор из карточки 01;
        // его сообщения уже по-русски и садятся на поля conditions/rewards/audience/limits
        $this->validator->assertValid($rule);

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditions(array $conditions): array
    {
        $items = [];

        foreach (array_values((array) ($conditions['items'] ?? [])) as $item) {
            $item = (array) $item;
            $selector = (array) ($item['selector'] ?? []);

            $items[] = [
                'selector' => [
                    'products' => $this->intList($selector['products'] ?? []),
                    'categories' => $this->intList($selector['categories'] ?? []),
                    'with_descendants' => (bool) ($selector['with_descendants'] ?? false),
                    'brands' => $this->intList($selector['brands'] ?? []),
                    'tags' => $this->stringList($selector['tags'] ?? []),
                    'erp_promotions' => $this->stringList($selector['erp_promotions'] ?? []),
                    'whole_cart' => (bool) ($selector['whole_cart'] ?? false),
                ],
                'aggregate' => ($item['aggregate'] ?? null) === PromotionRule::AGGREGATE_AMOUNT
                    ? PromotionRule::AGGREGATE_AMOUNT
                    : PromotionRule::AGGREGATE_QUANTITY,
                'price_basis' => PromotionRule::PRICE_BASIS_CLIENT_FINAL,
                'operator' => (string) ($item['operator'] ?? '>='),
                'value' => (float) ($item['value'] ?? 0),
                // Своя кратность у позиции — необязательна; пустую храним как null,
                // иначе движок посчитает её заданной и обнулит вклад награды
                'per_value' => isset($item['per_value']) && is_numeric($item['per_value']) && (float) $item['per_value'] > 0
                    ? (float) $item['per_value']
                    : null,
            ];
        }

        return [
            'mode' => ($conditions['mode'] ?? 'all') === 'any' ? 'any' : 'all',
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, mixed>  $rewards
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRewards(array $rewards): array
    {
        $normalized = [];

        foreach (array_values($rewards) as $reward) {
            $reward = (array) $reward;
            $type = ($reward['type'] ?? null) === PromotionRule::REWARD_TYPE_CHOICE
                ? PromotionRule::REWARD_TYPE_CHOICE
                : PromotionRule::REWARD_TYPE_FIXED;
            $price = round((float) ($reward['price'] ?? 0), 2);
            $perThreshold = ($reward['multiply'] ?? null) === PromotionRule::MULTIPLY_PER_THRESHOLD;

            $normalized[] = [
                'type' => $type,
                'product_id' => $type === PromotionRule::REWARD_TYPE_FIXED && ! empty($reward['product_id'])
                    ? (int) $reward['product_id']
                    : null,
                'choices' => $type === PromotionRule::REWARD_TYPE_CHOICE
                    ? $this->intList($reward['choices'] ?? [])
                    : null,
                'quantity' => max(1, (int) ($reward['quantity'] ?? 1)),
                'price' => $price,
                'promo_kind' => ($reward['promo_kind'] ?? null) === PromoKind::SAMPLE->value
                    ? PromoKind::SAMPLE->value
                    : PromoKind::ACCOUNTABLE->value,
                'warehouse_id' => ! empty($reward['warehouse_id']) ? (int) $reward['warehouse_id'] : null,
                'multiply' => $perThreshold ? PromotionRule::MULTIPLY_PER_THRESHOLD : PromotionRule::MULTIPLY_ONCE,
                // Ноль и пустая строка — это «шага нет», его задают позиции условия.
                // Схема допускает только null либо число больше нуля
                'per_value' => $perThreshold && is_numeric($reward['per_value'] ?? null) && (float) $reward['per_value'] > 0
                    ? (float) $reward['per_value']
                    : null,
                'max_multiplier' => $perThreshold ? (int) ($reward['max_multiplier'] ?? 0) : 1,
                // Бесплатную промо-позицию не отклоняют — флаг для неё смысла не имеет
                'optional' => $price > 0 && (bool) ($reward['optional'] ?? true),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<string, mixed>
     */
    private function normalizeAudience(array $audience): array
    {
        return [
            'region_ids' => $this->intList($audience['region_ids'] ?? []),
            'user_ids' => $this->intList($audience['user_ids'] ?? []),
            'manager_ids' => $this->intList($audience['manager_ids'] ?? []),
            'channels' => array_values(array_intersect(
                $this->stringList($audience['channels'] ?? []),
                PromotionRule::CHANNELS,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    private function normalizeLimits(array $limits): array
    {
        $perClient = $limits['per_client_total'] ?? null;
        $total = $limits['total'] ?? null;

        return [
            'per_client_total' => is_numeric($perClient) && (int) $perClient > 0 ? (int) $perClient : null,
            'total' => is_numeric($total) && (int) $total > 0 ? (int) $total : null,
        ];
    }

    // ────────────────────────────────────────────
    // Данные для интерфейса
    // ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'promotions' => Promotion::query()->orderBy('name')->get(['id', 'name']),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_defect'])
                ->map(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'is_defect' => (bool) $warehouse->is_defect,
                    // Флаг склада пробников появится в волне 3 вместе с «Москва реклама»
                    'is_promo_sample' => (bool) $warehouse->getAttribute('is_promo_sample'),
                ]),
            'erp_promotion_types' => [
                ['value' => ErpPromotion::TYPE_NEW, 'label' => 'Новинки'],
                ['value' => ErpPromotion::TYPE_BESTSELLER, 'label' => 'Хиты'],
                ['value' => ErpPromotion::TYPE_LIQUIDATION, 'label' => 'Ликвидация'],
            ],
            'issue_mode_available' => PromotionRuleMode::issueAvailable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(PromotionRule $rule): array
    {
        $conditions = $rule->conditions ?? ['mode' => 'all', 'items' => []];
        $audience = $rule->audience ?? [];

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'promotion_id' => $rule->promotion_id,
            'is_active' => (bool) $rule->is_active,
            'mode' => $rule->mode->value,
            'starts_at' => $rule->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $rule->ends_at?->format('Y-m-d\TH:i'),
            'priority' => (int) $rule->priority,
            'stackable' => (bool) $rule->stackable,
            'conditions' => [
                'mode' => $conditions['mode'] ?? 'all',
                'items' => array_values($conditions['items'] ?? []),
            ],
            'rewards' => array_values($rule->rewards ?? []),
            'audience' => [
                'region_ids' => array_values((array) ($audience['region_ids'] ?? [])),
                'user_ids' => array_values((array) ($audience['user_ids'] ?? [])),
                'manager_ids' => array_values((array) ($audience['manager_ids'] ?? [])),
                'channels' => array_values((array) ($audience['channels'] ?? [])),
            ],
            'limits' => [
                'per_client_total' => $rule->limits['per_client_total'] ?? null,
                'total' => $rule->limits['total'] ?? null,
            ],
            'condition_products_count' => $rule->condition_products_count ?? null,
            'reward_products_count' => $rule->reward_products_count ?? null,
            // Названия товаров, чтобы селекторы показали выбранное без доп. запросов с фронта
            'product_names' => $this->productNames($this->ruleProductIds($rule)),
            'entity_names' => $this->ruleEntityNames($rule),
        ];
    }

    /**
     * Строка списка правил.
     *
     * @return array<string, mixed>
     */
    private function listRow(PromotionRule $rule): array
    {
        $status = $this->describer->status($rule);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'promotion' => $rule->promotion ? ['id' => $rule->promotion->id, 'name' => $rule->promotion->name] : null,
            'mode' => $rule->mode->value,
            'mode_label' => $rule->mode->label(),
            'status' => $status['value'],
            'status_label' => $status['label'],
            'period' => $this->describer->period($rule),
            'condition_summary' => $this->describer->conditionSummary($rule),
            'reward_summary' => $this->describer->rewardSummary($rule),
            'priority' => (int) $rule->priority,
            // Счётчик выданных промо-позиций появится в волне 2
            'issued_count' => null,
        ];
    }

    /**
     * @return int[]
     */
    private function ruleProductIds(PromotionRule $rule): array
    {
        $ids = [];

        foreach ((array) ($rule->conditions['items'] ?? []) as $item) {
            $ids = array_merge($ids, $this->intList(((array) $item)['selector']['products'] ?? []));
        }

        foreach ((array) ($rule->rewards ?? []) as $reward) {
            $reward = (array) $reward;
            $ids = array_merge($ids, $this->intList($reward['product_id'] ?? []), $this->intList($reward['choices'] ?? []));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Названия категорий, брендов, клиентов и менеджеров, выбранных в правиле.
     *
     * @return array<string, array<int, string>>
     */
    private function ruleEntityNames(PromotionRule $rule): array
    {
        $categories = [];
        $brands = [];

        foreach ((array) ($rule->conditions['items'] ?? []) as $item) {
            $selector = (array) (((array) $item)['selector'] ?? []);
            $categories = array_merge($categories, $this->intList($selector['categories'] ?? []));
            $brands = array_merge($brands, $this->intList($selector['brands'] ?? []));
        }

        $audience = $rule->audience ?? [];
        $users = $this->intList($audience['user_ids'] ?? []);
        $managers = $this->intList($audience['manager_ids'] ?? []);

        return [
            'categories' => $this->namesFor(Category::query(), $categories),
            'brands' => $this->namesFor(Brand::query(), $brands),
            'users' => $this->namesFor(User::query(), $users),
            'managers' => $this->namesFor(User::query(), $managers),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function namesFor($query, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $query->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function productNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::withoutGlobalScope(HiddenScope::class)
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    // ────────────────────────────────────────────
    // Контекст предпросмотра
    // ────────────────────────────────────────────

    /**
     * @return array{0: PromoContext, 1: array<string, mixed>}|null
     */
    private function contextFromCart(int $cartId, string $channel): ?array
    {
        $cart = Cart::query()->with(['items', 'user', 'promotionSelections'])->find($cartId);

        if (! $cart) {
            return null;
        }

        return [
            PromoContext::fromCart($cart, $cart->user, null, $channel),
            [
                'type' => 'cart',
                'id' => $cart->id,
                'client' => $cart->user->name,
                'items_count' => $cart->items->count(),
            ],
        ];
    }

    /**
     * Заказ — исторический снимок: берём цены из строк, а не текущие цены клиента.
     *
     * @return array{0: PromoContext, 1: array<string, mixed>}|null
     */
    private function contextFromOrder(int $orderId, string $channel): ?array
    {
        $order = Order::query()->with(['items', 'user'])->find($orderId);

        if (! $order) {
            return null;
        }

        $lines = $order->items
            ->filter(fn ($item) => $item->product_id !== null)
            ->map(fn ($item) => new PromoContextLine(
                productId: (int) $item->product_id,
                quantity: (int) $item->quantity,
                unitPrice: (float) ($item->final_price ?? $item->price),
                isPromo: false,
                itemType: $item->product_defect_id ? 'defect' : 'instock',
            ))
            ->values()
            ->all();

        $currency = $order->currency_code
            ? Currency::query()->where('code', $order->currency_code)->first()
            : null;

        return [
            PromoContext::fromLines($lines, $order->user, $channel, [], null, $currency),
            [
                'type' => 'order',
                'id' => $order->id,
                'client' => $order->user?->name,
                'items_count' => count($lines),
            ],
        ];
    }

    // ────────────────────────────────────────────
    // Мелочи
    // ────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<PromotionRule>  $query
     */
    private function applyStatusFilter($query, string $status): void
    {
        $now = now();

        match ($status) {
            'active' => $query->active($now),
            'disabled' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'finished' => $query->where('is_active', true)->where('ends_at', '<', $now),
            default => null,
        };
    }

    /**
     * @return int[]
     */
    private function intList(mixed $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $values),
            fn (int $id) => $id > 0,
        )));
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $values): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            (array) $values,
        )));
    }
}
