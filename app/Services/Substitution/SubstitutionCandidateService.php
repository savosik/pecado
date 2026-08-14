<?php

namespace App\Services\Substitution;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Substitution\CandidateKind;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\ProductSubstitution;
use App\Models\User;
use App\Services\Defect\DefectStockService;
use App\Support\Search\HybridSearchOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Движок автоподбора кандидатов на замену: слои сверху вниз, каждый кандидат
 * несёт слой, причину для клиента, индивидуальную цену и остаток.
 *
 * Ключевое решение (проверено на живом товаре): паспортные атрибуты не ранжируют.
 * Функция товара живёт в головном слове названия и категории — «Стропа» не должна
 * получать «Ошейник» первым кандидатом только потому, что у них совпали
 * «Страна происхождения» и «Импортёр». Различающие атрибуты (материал, цвет,
 * объём, размер, питание) — вторичный сигнал внутри слоя.
 *
 * Сквозные условия всех слоёв: hidden = 0 (глобальный скоуп), остаток региона
 * клиента ≥ недостающего количества (частичный кандидат допустим только у уценки),
 * индивидуальная цена клиента в асимметричном коридоре [−25 %; +10 %].
 */
class SubstitutionCandidateService
{
    public function __construct(
        private readonly PriceServiceInterface $prices,
        private readonly StockServiceInterface $stock,
        private readonly DefectStockService $defectStock,
    ) {}

    public function forOrderItem(OrderItem $cancelled): CandidateSet
    {
        $cancelled->loadMissing(['product.category', 'product.model', 'product.brand', 'order.user']);

        $product = $cancelled->product;
        $client = $cancelled->order?->user;

        if ($product === null) {
            // Рассинхрон каталога: подбирать не по чему, это алерт, не замена.
            return new CandidateSet(false, null, []);
        }

        $needed = max(1, (int) $cancelled->quantity);
        $referencePrice = (float) $cancelled->final_price;

        [$wait, $waitReason] = $this->waitOption($product, $client, $needed);

        $limit = (int) config('substitutions.matching.max_candidates_per_line', 4);
        $candidates = collect();
        $seenProducts = [$product->id => true];

        // Слой 0.5 — уценка того же товара: вне ценового коридора намеренно,
        // «идентичный товар, вмята коробка, −30 %» — не замена вовсе.
        foreach ($this->defectCandidates($product, $needed) as $candidate) {
            $candidates->push($candidate);
        }

        $layers = [
            [CandidateKind::LINKED, fn () => $this->linkedPool($product)],
            [CandidateKind::VARIANT, fn () => $this->variantPool($product)],
            [CandidateKind::LINE, fn () => $this->linePool($product)],
            [CandidateKind::FUNCTIONAL, fn () => $this->functionalPool($product)],
            [CandidateKind::CATEGORY_PRICE, fn () => $this->categoryPool($product)],
            [CandidateKind::SEMANTIC, fn () => $this->semanticPool($product)],
        ];

        foreach ($layers as [$kind, $pool]) {
            if ($candidates->count() >= $limit) {
                break;
            }

            /** @var Collection<int, array{product: Product, reason: string, rank: float}> $entries */
            $entries = $pool();

            if ($entries->isEmpty()) {
                continue;
            }

            $entries = $entries->reject(fn (array $entry) => isset($seenProducts[$entry['product']->id]));

            foreach ($this->passBaseFilters($entries, $client, $needed, $referencePrice) as $entry) {
                if ($candidates->count() >= $limit) {
                    break;
                }

                $seenProducts[$entry['product']->id] = true;

                $candidates->push([
                    'product_id' => $entry['product']->id,
                    'product_defect_id' => null,
                    'kind' => $kind,
                    'reason' => $entry['reason'],
                    'price' => $entry['price'],
                    'available' => $entry['available'],
                    'suggested_quantity' => min($needed, $entry['available']),
                ]);
            }
        }

        return new CandidateSet($wait, $waitReason, $candidates->all());
    }

    /**
     * Слой 0: тот же товар вернётся — остаток появился или есть на
     * предзаказных складах региона. Отдаётся как исход «подождать», не замена.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function waitOption(Product $product, ?User $client, int $needed): array
    {
        if ($this->stock->getAvailableStock($product, $client) >= $needed) {
            return [true, 'Товар снова в наличии — зарезервируем за вами'];
        }

        if ($this->stock->getPreorderStock($product, $client) > 0) {
            return [true, 'Товар ожидается на складе — зарезервируем к приходу'];
        }

        return [false, null];
    }

    /**
     * Слой 0.5: открытые партии уценки того же товара. Частичное количество
     * допустимо — с честной пометкой «k из n».
     *
     * @return list<array<string, mixed>>
     */
    private function defectCandidates(Product $product, int $needed): array
    {
        $result = [];

        $defects = ProductDefect::query()
            ->sellable()
            ->where('product_id', $product->id)
            ->get();

        foreach ($defects as $defect) {
            $available = $this->defectStock->available($defect);

            if ($available < 1) {
                continue;
            }

            $result[] = [
                'product_id' => null,
                'product_defect_id' => $defect->id,
                'kind' => CandidateKind::DEFECT_SAME,
                'reason' => 'Идентичный товар, уценка: '.($defect->defect_description ?: 'незначительный дефект упаковки'),
                'price' => (float) $defect->price,
                'available' => $available,
                'suggested_quantity' => min($needed, $available),
            ];
        }

        return $result;
    }

    /**
     * Слой 1: подтверждённые связи справочника, по направлению from → to.
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function linkedPool(Product $product): Collection
    {
        return ProductSubstitution::query()
            ->usable()
            ->where('from_product_id', $product->id)
            ->orderByDesc('score')
            ->with('toProduct.category')
            ->get()
            ->filter(fn (ProductSubstitution $link) => $link->toProduct !== null)
            ->map(fn (ProductSubstitution $link) => [
                'product' => $link->toProduct,
                'reason' => $link->note ?: 'Проверенная замена: '.mb_strtolower($link->kind->label()),
                'rank' => (float) $link->score,
            ])
            ->values();
    }

    /**
     * Слой 2: варианты той же модели, исключая generic-группы — однословные
     * «модели» из 1С склеивают несвязанные товары (Neutral/CHERRY).
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function variantPool(Product $product): Collection
    {
        if ($product->model_id === null) {
            return collect();
        }

        $groupSize = Cache::remember(
            'substitutions:model-group-size:'.$product->model_id,
            3600,
            fn () => Product::withoutGlobalScopes()->where('model_id', $product->model_id)->count(),
        );

        if ($groupSize > (int) config('substitutions.matching.generic_model_group_size', 10)) {
            return collect();
        }

        return Product::query()
            ->where('model_id', $product->model_id)
            ->where('id', '!=', $product->id)
            ->with('category')
            ->get()
            ->map(fn (Product $candidate) => [
                'product' => $candidate,
                'reason' => 'Тот же товар, другой цвет или размер'
                    .($candidate->variant_name ? ': '.$candidate->variant_name : ''),
                'rank' => 100.0,
            ]);
    }

    /**
     * Слой 3: линейка бренда — общий значимый токен названия + тот же бренд
     * и категория (Lush 3 → Lush 4 / Lush Mini: model_id у них пуст,
     * слой вариантов их не ловит).
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function linePool(Product $product): Collection
    {
        if ($product->brand_id === null || $product->category_id === null) {
            return collect();
        }

        $tokens = $this->lineTokens($product->name);

        if ($tokens === []) {
            return collect();
        }

        return Product::query()
            ->where('brand_id', $product->brand_id)
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('name', 'like', '%'.$token.'%');
                }
            })
            ->limit(50)
            ->get()
            ->map(function (Product $candidate) use ($tokens) {
                $candidateTokens = $this->lineTokens($candidate->name);
                $shared = count(array_intersect($tokens, $candidateTokens));

                return [
                    'product' => $candidate,
                    'reason' => 'Та же линейка, соседнее поколение',
                    'rank' => (float) $shared,
                ];
            })
            ->filter(fn (array $entry) => $entry['rank'] > 0)
            ->sortByDesc('rank')
            ->values();
    }

    /**
     * Слой 4: функциональный тип — листовая категория + нормализованное головное
     * слово названия. Ранжирование — различающими атрибутами и близостью цены.
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function functionalPool(Product $product): Collection
    {
        if ($product->category_id === null) {
            return collect();
        }

        $headWord = $this->normalizedHeadWord($product->name);

        if ($headWord === null) {
            return collect();
        }

        $pool = Product::query()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(300)
            ->get()
            ->filter(fn (Product $candidate) => $this->normalizedHeadWord($candidate->name) === $headWord);

        if ($pool->isEmpty()) {
            return collect();
        }

        $attributeMap = $this->distinguishingAttributes(
            $pool->pluck('id')->push($product->id)->all(),
        );

        $own = $attributeMap[$product->id] ?? [];

        return $pool->map(function (Product $candidate) use ($attributeMap, $own, $headWord) {
            $matches = 0;

            foreach ($attributeMap[$candidate->id] ?? [] as $slug => $value) {
                if (($own[$slug] ?? null) !== null && $own[$slug] === $value) {
                    $matches++;
                }
            }

            return [
                'product' => $candidate,
                'reason' => \Illuminate\Support\Str::ucfirst($headWord).' другого бренда, то же назначение',
                'rank' => (float) $matches,
            ];
        })->sortByDesc('rank')->values();
    }

    /**
     * Слой 5: листовая категория + ценовой коридор, ранжирование по продажам
     * за окно (реализации 1С, бизнес-дата документа).
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function categoryPool(Product $product): Collection
    {
        if ($product->category_id === null) {
            return collect();
        }

        $sales = $this->salesRank($product->category_id);

        return Product::query()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(300)
            ->get()
            ->map(fn (Product $candidate) => [
                'product' => $candidate,
                'reason' => 'Ближайший аналог по назначению и цене',
                'rank' => (float) ($sales[$candidate->id] ?? 0),
            ])
            ->sortByDesc('rank')
            ->values();
    }

    /**
     * Слой 6: семантика Meilisearch в пределах родительской категории —
     * последний фолбэк для пустых категорий.
     *
     * @return Collection<int, array{product: Product, reason: string, rank: float}>
     */
    private function semanticPool(Product $product): Collection
    {
        try {
            $builder = Product::search($product->name);

            $categoryNames = $this->siblingCategoryNames($product);

            if ($categoryNames !== []) {
                $builder->whereIn('category', $categoryNames);
            }

            $options = HybridSearchOptions::forProducts();

            if ($options) {
                $builder->options($options);
            }

            $ids = $builder->take(30)->keys()->all();
        } catch (\Throwable $e) {
            Log::warning('Автоподбор замен: семантический слой недоступен', ['error' => $e->getMessage()]);

            return collect();
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id !== $product->id));

        if ($ids === []) {
            return collect();
        }

        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $products->get($id))
            ->filter()
            ->values()
            ->map(fn (Product $candidate, int $index) => [
                'product' => $candidate,
                'reason' => 'Похожий по описанию и назначению',
                'rank' => (float) (1000 - $index),
            ]);
    }

    /**
     * Сквозные фильтры слоя: остаток и ценовой коридор индивидуальной цены.
     *
     * @param  Collection<int, array{product: Product, reason: string, rank: float}>  $entries
     * @return list<array{product: Product, reason: string, rank: float, price: float, available: int}>
     */
    private function passBaseFilters(Collection $entries, ?User $client, int $needed, float $referencePrice): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $products = $entries->pluck('product');
        $stockMap = $this->stock->getAvailableStockMap($products, $client);
        $priceMap = $this->prices->getPriceMapForProducts($products, $client);

        $down = (float) config('substitutions.matching.price_corridor_down', 0.25);
        $up = (float) config('substitutions.matching.price_corridor_up', 0.10);

        $min = $referencePrice > 0 ? $referencePrice * (1 - $down) : 0.0;
        $max = $referencePrice > 0 ? $referencePrice * (1 + $up) : PHP_FLOAT_MAX;

        $passed = [];

        foreach ($entries as $entry) {
            $product = $entry['product'];
            $available = (int) ($stockMap[$product->id] ?? 0);

            if ($available < $needed) {
                continue;
            }

            $priceResult = $priceMap[$product->id] ?? null;
            $price = $priceResult?->getDisplayPrice() ?? 0.0;

            if ($price <= 0 || $price < $min || $price > $max) {
                continue;
            }

            $entry['price'] = $price;
            $entry['available'] = $available;
            $passed[] = $entry;
        }

        usort($passed, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        return $passed;
    }

    /**
     * Значимые токены линейки: слова названия после головного, длиной от 3 символов,
     * без чисто числовых (номер поколения не токен линейки, он различает внутри неё).
     *
     * @return list<string>
     */
    private function lineTokens(string $name): array
    {
        $words = preg_split('/[\s,\-–—\/()«»"]+/u', mb_strtolower($name)) ?: [];
        $words = array_slice(array_values(array_filter($words)), 1); // без головного слова

        return array_values(array_filter(
            $words,
            fn (string $word) => mb_strlen($word) >= 3 && ! preg_match('/^\d+$/u', $word),
        ));
    }

    /**
     * Нормализованное головное слово названия — функция товара.
     *
     * Модификаторы («Анальная», «Реалистичный») пропускаются: функция живёт
     * в существительном, а не в прилагательном перед ним.
     */
    public function normalizedHeadWord(string $name): ?string
    {
        $words = preg_split('/[\s,\/()«»"]+/u', mb_strtolower(trim($name))) ?: [];
        $modifiers = array_flip((array) config('substitutions.head_word_modifiers', []));

        $head = null;

        foreach ($words as $word) {
            if ($word === '' || isset($modifiers[$word])) {
                continue;
            }

            $head = $word;
            break;
        }

        if ($head === null) {
            return null;
        }

        $synonyms = (array) config('substitutions.head_words', []);

        return $synonyms[$head] ?? $head;
    }

    /**
     * Различающие атрибуты пула товаров одним запросом.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, string|null>>
     */
    private function distinguishingAttributes(array $productIds): array
    {
        $slugs = (array) config('substitutions.matching.distinguishing_attributes', [
            'osnovnoi-material', 'osnovnoi-cvet', 'razmer', 'obieem-ml', 'pitanie-osnovnogo-ustroistva',
        ]);

        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->leftJoin('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->whereIn('pav.product_id', $productIds)
            ->whereIn('a.slug', $slugs)
            ->select('pav.product_id', 'a.slug', 'av.value', 'pav.text_value', 'pav.number_value')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->product_id][$row->slug] = $row->value
                ?? $row->text_value
                ?? ($row->number_value !== null ? (string) $row->number_value : null);
        }

        return $map;
    }

    /**
     * Продажи товаров категории за окно — ранжирование слоя 5.
     * Кешируется по категории: вызывается при создании оффера и пересборе.
     *
     * @return array<int, int>
     */
    private function salesRank(int $categoryId): array
    {
        $windowDays = (int) config('substitutions.matching.sales_window_days', 90);

        return Cache::remember(
            "substitutions:sales-rank:{$categoryId}:{$windowDays}",
            1800,
            function () use ($categoryId, $windowDays) {
                $since = now()->subDays($windowDays)->toDateTimeString();

                return DB::table('shipment_items')
                    ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
                    ->join('products', 'products.id', '=', 'shipment_items.product_id')
                    ->whereNull('shipments.deleted_at')
                    ->where('products.category_id', $categoryId)
                    ->whereRaw('COALESCE(shipments.erp_created_at, shipments.date, shipments.created_at) >= ?', [$since])
                    ->groupBy('shipment_items.product_id')
                    ->selectRaw('shipment_items.product_id, SUM(shipment_items.quantity) as qty')
                    ->pluck('qty', 'product_id')
                    ->map(fn ($qty) => (int) $qty)
                    ->all();
            },
        );
    }

    /**
     * Имена листовых категорий-сестёр (для фильтра Meilisearch: индекс
     * фильтруется только по имени категории товара).
     *
     * @return list<string>
     */
    private function siblingCategoryNames(Product $product): array
    {
        $category = $product->category;

        if ($category === null) {
            return [];
        }

        $parent = $category->parent;

        if ($parent === null) {
            return [(string) $category->name];
        }

        return Cache::remember(
            'substitutions:sibling-categories:'.$parent->id,
            3600,
            fn () => $parent->descendants()->pluck('name')->push($parent->name)->unique()->values()->all(),
        );
    }
}
