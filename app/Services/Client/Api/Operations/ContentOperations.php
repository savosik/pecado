<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\PromotionRuleMode;
use App\Helpers\ContentHelper;
use App\Helpers\TagHelper;
use App\Models\Faq;
use App\Models\News;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Promotion\PromotionRuleDescriber;
use App\Support\Content\ContentText;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Контент сайта глазами клиента: новости, акции, подборки, страницы и FAQ.
 *
 * Отбор тот же, что на сайте: опубликованное и активное, с учётом региона
 * клиента (контент с привязкой к регионам виден только в этих регионах).
 * Тексты отдаются целиком и без разметки (ContentText), чтобы агент мог
 * отвечать по ним, а не пересказывать заголовки.
 */
class ContentOperations implements OperationProvider
{
    public function __construct(private readonly PromotionRuleDescriber $describer) {}

    public static function section(): array
    {
        return ['content', 'Новости, акции, подборки, страницы и FAQ'];
    }

    public static function operations(): array
    {
        $cursor = Param::string('cursor', 'Курсор страницы из meta.next_cursor');
        $perPage = Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']);
        $slug = fn (string $what) => Param::string('slug', "Адрес {$what} (slug из списка)", true, ['max:255']);

        return [
            new Operation(
                id: 'promotions.list', section: 'content', method: 'GET', uri: 'promotions',
                summary: 'Актуальные акции с условиями',
                description: 'Действующие акции для клиента: описание, период, условия и что клиент получит. '
                    .'Акции, у которых все правила закончились, не начались или адресованы не этому клиенту, не показываются. '
                    .'how_applied: auto — промо-позиция добавится в заказ сама, manager — её добавит менеджер после оформления. '
                    .'Цены и остатки товаров акции — catalog.prices по идентификаторам из promotions.products.',
                params: [$cursor, $perPage],
                handler: [self::class, 'promotions'],
            ),
            new Operation(
                id: 'promotions.get', section: 'content', method: 'GET', uri: 'promotions/{slug}',
                summary: 'Акция: полный текст и правила',
                description: 'Текст лендинга целиком и правила акции, как в promotions.list.',
                params: [$slug('акции')],
                handler: [self::class, 'promotion'],
            ),
            new Operation(
                id: 'promotions.products', section: 'content', method: 'GET', uri: 'promotions/{slug}/products',
                summary: 'Товары акции',
                description: 'Товары лендинга и товары из условий и наград действующих правил. role: landing, condition, reward '
                    .'(у товара может быть несколько ролей).',
                params: [$slug('акции'), $cursor, $perPage],
                handler: [self::class, 'promotionProducts'],
            ),
            new Operation(
                id: 'news.list', section: 'content', method: 'GET', uri: 'news',
                summary: 'Новости',
                description: 'Опубликованные новости, свежие первыми, с кратким анонсом. Полный текст — news.get.',
                params: [
                    Param::list('tags', 'Только новости с любым из тегов'),
                    $cursor, $perPage,
                ],
                handler: [self::class, 'newsList'],
            ),
            new Operation(
                id: 'news.get', section: 'content', method: 'GET', uri: 'news/{slug}',
                summary: 'Новость целиком',
                description: 'Полный текст новости без разметки.',
                params: [$slug('новости')],
                handler: [self::class, 'newsItem'],
            ),
            new Operation(
                id: 'collections.list', section: 'content', method: 'GET', uri: 'collections',
                summary: 'Подборки товаров',
                description: 'Активные тематические подборки (как на главной и в /collections): название, описание, число товаров.',
                params: [$cursor, $perPage],
                handler: [self::class, 'collections'],
            ),
            new Operation(
                id: 'collections.get', section: 'content', method: 'GET', uri: 'collections/{slug}',
                summary: 'Подборка: описание',
                description: 'Полное описание подборки. Товары — collections.products.',
                params: [$slug('подборки')],
                handler: [self::class, 'collection'],
            ),
            new Operation(
                id: 'collections.products', section: 'content', method: 'GET', uri: 'collections/{slug}/products',
                summary: 'Товары подборки',
                description: 'featured — товар выделен в подборке. Цены и остатки — catalog.prices по идентификаторам.',
                params: [$slug('подборки'), $cursor, $perPage],
                handler: [self::class, 'collectionProducts'],
            ),
            new Operation(
                id: 'pages.list', section: 'content', method: 'GET', uri: 'pages',
                summary: 'Информационные страницы',
                description: 'Опубликованные страницы сайта (доставка, оплата, условия работы и т. п.): заголовок и адрес.',
                params: [],
                handler: [self::class, 'pages'],
            ),
            new Operation(
                id: 'pages.get', section: 'content', method: 'GET', uri: 'pages/{slug}',
                summary: 'Страница целиком',
                description: 'Полный текст страницы без разметки.',
                params: [$slug('страницы')],
                handler: [self::class, 'page'],
            ),
            new Operation(
                id: 'faq.list', section: 'content', method: 'GET', uri: 'faq',
                summary: 'Частые вопросы с ответами',
                description: 'Все опубликованные вопросы и ответы целиком, в порядке сайта. q — поиск по вопросу и ответу. '
                    .'Отвечайте клиенту по этому тексту; если ответа нет — client-ask-manager.',
                params: [Param::string('q', 'Поиск по вопросу и ответу', rules: ['max:200'])],
                handler: [self::class, 'faq'],
            ),
        ];
    }

    // ── Акции ──────────────────────────────────────────

    /** @return array<string, mixed> */
    public function promotions(User $actor, OperationInput $input): array
    {
        // Отбор по правилам не выражается запросом (аудитория в JSON), поэтому
        // активные акции региона берутся целиком: их единицы, а не тысячи.
        $promotions = Promotion::active()->forRegion($actor->region_id)->withCount('rules')
            ->with(['rules' => fn ($q) => $q->active()->orderBy('priority')->orderBy('id')])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (Promotion $p) => [$p, $this->rulesFor($p, $actor)])
            // Лендинг без правил — информационная акция; с правилами — только пока хоть одно действует для клиента
            ->filter(fn (array $pair) => (int) $pair[0]->rules_count === 0 || $pair[1]->isNotEmpty())
            ->values();

        $this->describer->warmUp($promotions->flatMap(fn (array $pair) => $pair[1]));

        $perPage = Envelope::perPage($input->get('per_page'), 50);
        $offset = $this->offset($input->string('cursor'));
        $page = $promotions->slice($offset, $perPage);

        return Envelope::data(
            $page->map(fn (array $pair) => $this->promotionRow($pair[0], $pair[1], false))->values()->all(),
            [
                'per_page' => $perPage,
                'has_more' => $promotions->count() > $offset + $perPage,
                'next_cursor' => $promotions->count() > $offset + $perPage ? (string) ($offset + $perPage) : null,
                'total' => $promotions->count(),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function promotion(User $actor, OperationInput $input): array
    {
        [$promotion, $rules] = $this->visiblePromotion($actor, (string) $input->string('slug'));
        $this->describer->warmUp($rules);

        return Envelope::data($this->promotionRow($promotion, $rules, true));
    }

    /** @return array<string, mixed> */
    public function promotionProducts(User $actor, OperationInput $input): array
    {
        [$promotion, $rules] = $this->visiblePromotion($actor, (string) $input->string('slug'));

        $roles = [];

        foreach ($promotion->products()->pluck('products.id') as $id) {
            $roles[(int) $id][] = 'landing';
        }

        $ruleProducts = \Illuminate\Support\Facades\DB::table('promotion_rule_product')
            ->whereIn('promotion_rule_id', $rules->pluck('id'))
            ->get(['product_id', 'role']);

        foreach ($ruleProducts as $row) {
            $roles[(int) $row->product_id][] = (string) $row->role;
        }

        $paginator = Product::query()->whereKey(array_keys($roles))->orderBy('id')
            ->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (Product $p) => $this->productRow($p) + [
            'role' => array_values(array_unique($roles[$p->id] ?? [])),
        ]);
    }

    /**
     * @return array{0: Promotion, 1: Collection<int, PromotionRule>}
     */
    private function visiblePromotion(User $actor, string $slug): array
    {
        $promotion = Promotion::active()->forRegion($actor->region_id)->withCount('rules')->where('slug', $slug)
            ->with(['rules' => fn ($q) => $q->active()->orderBy('priority')->orderBy('id')])
            ->firstOrFail();

        $rules = $this->rulesFor($promotion, $actor);

        if ((int) $promotion->rules_count > 0 && $rules->isEmpty()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Promotion::class);
        }

        return [$promotion, $rules];
    }

    /**
     * Действующие правила акции, адресованные этому клиенту.
     *
     * Аудитория — как в PromotionEngine::matchesAudience: заданное ограничение
     * по региону, клиенту или менеджеру должно совпасть.
     *
     * @return Collection<int, PromotionRule>
     */
    private function rulesFor(Promotion $promotion, User $actor): Collection
    {
        return $promotion->rules->filter(function (PromotionRule $rule) use ($actor) {
            $audience = (array) ($rule->audience ?? []);

            foreach ([
                'region_ids' => $actor->region_id,
                'user_ids' => $actor->id,
                'manager_ids' => $actor->personal_manager_id,
            ] as $key => $value) {
                $allowed = array_values(array_filter(array_map('intval', (array) ($audience[$key] ?? []))));

                if ($allowed !== [] && ($value === null || ! in_array((int) $value, $allowed, true))) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, PromotionRule>  $rules
     * @return array<string, mixed>
     */
    private function promotionRow(Promotion $promotion, Collection $rules, bool $full): array
    {
        $row = [
            'id' => (int) $promotion->id,
            'name' => $promotion->name,
            'slug' => $promotion->slug,
            'url' => url('/promotions/'.$promotion->slug),
            'summary' => ContentHelper::extractText($promotion->description, 300),
            'rules' => $rules->map(fn (PromotionRule $rule) => $this->ruleRow($rule))->all(),
        ];

        if ($full) {
            $row['text'] = ContentText::toText($promotion->description);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function ruleRow(PromotionRule $rule): array
    {
        $rewardIds = [];

        foreach ((array) ($rule->rewards ?? []) as $reward) {
            $reward = (array) $reward;
            $rewardIds = array_merge($rewardIds, array_filter([(int) ($reward['product_id'] ?? 0)]), array_map('intval', (array) ($reward['choices'] ?? [])));
        }

        $products = Product::query()->whereKey(array_unique($rewardIds))->get()->keyBy('id');

        $channels = (array) (($rule->audience ?? [])['channels'] ?? []);

        return [
            'id' => (int) $rule->id,
            'name' => $rule->name,
            'starts_at' => $rule->starts_at?->toDateString(),
            'ends_at' => $rule->ends_at?->toDateString(),
            'period' => $this->describer->period($rule),
            'conditions' => $this->describer->conditionLines($rule),
            'conditions_mode' => ($rule->conditions['mode'] ?? 'all') === 'any' ? 'any' : 'all',
            'rewards' => array_values(array_map(function ($reward) use ($products) {
                $reward = (array) $reward;
                $ids = ! empty($reward['product_id'])
                    ? [(int) $reward['product_id']]
                    : array_map('intval', (array) ($reward['choices'] ?? []));

                return [
                    'kind' => ($reward['type'] ?? 'fixed') === 'choice' ? 'choice' : 'fixed',
                    'products' => array_values(array_filter(array_map(
                        fn (int $id) => $products->has($id) ? $this->productRow($products->get($id)) : null,
                        $ids,
                    ))),
                    'quantity' => (int) ($reward['quantity'] ?? 1),
                    'price' => $this->describer->promoAmountLabel(null, (float) ($reward['price'] ?? 0)),
                    'per_threshold' => ($reward['multiply'] ?? 'once') === 'per_threshold',
                    'max_multiplier' => isset($reward['max_multiplier']) ? (int) $reward['max_multiplier'] : null,
                    'optional' => (float) ($reward['price'] ?? 0) > 0 && (bool) ($reward['optional'] ?? false),
                ];
            }, (array) ($rule->rewards ?? []))),
            'how_applied' => $rule->mode === PromotionRuleMode::ISSUE ? 'auto' : 'manager',
            'channels' => $channels === [] ? ['site', 'api'] : array_values($channels),
        ];
    }

    // ── Новости ───────────────────────────────────────

    /** @return array<string, mixed> */
    public function newsList(User $actor, OperationInput $input): array
    {
        $query = News::published()->forRegion($actor->region_id)->with('tags')
            ->orderByDesc('published_at')->orderByDesc('id');

        $tags = array_values(array_filter((array) $input->array('tags')));

        if ($tags !== []) {
            $query->withAnyTags($tags);
        }

        $paginator = $query->cursorPaginate(Envelope::perPage($input->get('per_page'), 20), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (News $news) => $this->newsRow($news, false));
    }

    /** @return array<string, mixed> */
    public function newsItem(User $actor, OperationInput $input): array
    {
        $news = News::published()->forRegion($actor->region_id)->with('tags')
            ->where('slug', (string) $input->string('slug'))->firstOrFail();

        return Envelope::data($this->newsRow($news, true));
    }

    /** @return array<string, mixed> */
    private function newsRow(News $news, bool $full): array
    {
        $row = [
            'id' => (int) $news->id,
            'title' => $news->title,
            'slug' => $news->slug,
            'url' => url('/news/'.$news->slug),
            'published_at' => $news->published_at?->toIso8601String(),
            'summary' => $news->short_description ?: ContentHelper::extractText($news->detailed_description, 300),
            'tags' => TagHelper::names($news->tags),
        ];

        if ($full) {
            $row['text'] = ContentText::toText($news->detailed_description);
        }

        return $row;
    }

    // ── Подборки ──────────────────────────────────────

    /** @return array<string, mixed> */
    public function collections(User $actor, OperationInput $input): array
    {
        $paginator = ProductSelection::active()->forRegion($actor->region_id)->withCount('products')
            ->orderBy('sort_order')->orderBy('id')
            ->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (ProductSelection $s) => $this->collectionRow($s, false));
    }

    /** @return array<string, mixed> */
    public function collection(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->collectionRow($this->visibleCollection($actor, $input), true));
    }

    /** @return array<string, mixed> */
    public function collectionProducts(User $actor, OperationInput $input): array
    {
        $paginator = $this->visibleCollection($actor, $input)->products()
            ->orderBy('products.id')
            ->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['products.*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (Product $p) => $this->productRow($p) + [
            'featured' => (bool) ($p->pivot->featured ?? false),
        ]);
    }

    private function visibleCollection(User $actor, OperationInput $input): ProductSelection
    {
        return ProductSelection::active()->forRegion($actor->region_id)->withCount('products')
            ->where('slug', (string) $input->string('slug'))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function collectionRow(ProductSelection $selection, bool $full): array
    {
        $row = [
            'id' => (int) $selection->id,
            'name' => $selection->name,
            'slug' => $selection->slug,
            'url' => url('/collections/'.$selection->slug),
            'summary' => $selection->short_description ?: ContentHelper::extractText($selection->description, 300),
            'products_count' => (int) ($selection->products_count ?? 0),
        ];

        if ($full) {
            $row['text'] = ContentText::toText($selection->description);
        }

        return $row;
    }

    // ── Страницы и FAQ ────────────────────────────────

    /** @return array<string, mixed> */
    public function pages(User $actor, OperationInput $input): array
    {
        $pages = $this->pageQuery($actor)->orderBy('title')->get();

        return Envelope::data($pages->map(fn (Page $page) => [
            'id' => (int) $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => url('/pages/'.$page->slug),
            'summary' => ContentHelper::extractText($page->content, 200),
        ])->all());
    }

    /** @return array<string, mixed> */
    public function page(User $actor, OperationInput $input): array
    {
        $page = $this->pageQuery($actor)->where('slug', (string) $input->string('slug'))->firstOrFail();

        return Envelope::data([
            'id' => (int) $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => url('/pages/'.$page->slug),
            'text' => ContentText::toText($page->content),
        ]);
    }

    /** @return Builder<Page> */
    private function pageQuery(User $actor): Builder
    {
        return Page::query()->where('is_published', true)->forRegion($actor->region_id);
    }

    /** @return array<string, mixed> */
    public function faq(User $actor, OperationInput $input): array
    {
        $query = Faq::query()->where('is_published', true)->forRegion($actor->region_id)
            ->orderBy('sort_order')->orderBy('id');

        $q = trim((string) $input->string('q'));

        if ($q !== '') {
            $query->where(fn (Builder $w) => $w->where('title', 'like', "%{$q}%")->orWhere('content', 'like', "%{$q}%"));
        }

        return Envelope::data($query->get()->map(fn (Faq $faq) => [
            'id' => (int) $faq->id,
            'question' => $faq->title,
            'answer' => ContentText::toText($faq->content),
        ])->all(), ['url' => url('/faq')]);
    }

    // ── Общее ─────────────────────────────────────────

    /** @return array<string, mixed> */
    private function productRow(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'code' => $product->code,
            'sku' => $product->sku,
            'name' => $product->name,
            'slug' => $product->slug,
        ];
    }

    private function offset(?string $cursor): int
    {
        return max(0, (int) $cursor);
    }
}
