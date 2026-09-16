<?php

namespace App\Services\Client\Api\Operations;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Currency;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductIdentifierResolver;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\CurrencyService;
use App\Services\Search\ProductSearchQuery;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Каталог глазами клиента: цены и остатки по его региону, поиск, карточка товара.
 *
 * Цены и остатки считаются пакетно (`getPriceMapForProducts`, `getStockMapsByIds`),
 * а не поштучно, как в legacy: прайс на весь каталог — это фид синхронизации, и
 * 500 запросов к базе цен на страницу превращали его в минуты.
 */
class CatalogOperations implements OperationProvider
{
    public function __construct(
        private readonly ProductIdentifierResolver $identifiers,
        private readonly PriceServiceInterface $prices,
        private readonly StockServiceInterface $stocks,
        private readonly UserCurrencyResolverInterface $currencyResolver,
        private readonly CurrencyService $currencies,
        private readonly ProductSearchQuery $search,
    ) {}

    public static function section(): array
    {
        return ['catalog', 'Каталог'];
    }

    public static function operations(): array
    {
        $feed = [
            Param::list('identifiers', 'Идентификаторы товаров: uuid 1С, код, артикул или штрихкод (до 500)', rules: ['max:500']),
            Param::string('cursor', 'Курсор следующей страницы из meta.next_cursor (без identifiers)'),
            Param::integer('per_page', 'Строк на странице, до 500', rules: ['min:1', 'max:500']),
        ];

        return [
            new Operation(
                id: 'catalog.prices',
                section: 'catalog',
                method: 'GET',
                uri: 'catalog/prices',
                summary: 'Цены клиента: базовая и индивидуальная, в его валюте',
                description: 'По списку идентификаторов — только запрошенные товары (порядок сохраняется, ненайденные и '
                    .'неоднозначные перечислены в meta). Без identifiers — весь каталог постранично по курсору. '
                    .'Цены конвертируются в валюту региона клиента; параметр currency (код) задаёт её явно.',
                params: [...$feed, Param::string('currency', 'Код валюты ответа, например RUB или BYN', rules: ['max:3'])],
                handler: [self::class, 'prices'],
            ),
            new Operation(
                id: 'catalog.stocks',
                section: 'catalog',
                method: 'GET',
                uri: 'catalog/stocks',
                summary: 'Остатки по региону клиента: свободный остаток и доступно под предзаказ',
                description: 'По списку идентификаторов или весь каталог постранично по курсору. Остаток — свободный, '
                    .'по складам региона клиента; preorder — доступно под предзаказ со сроком из профиля.',
                params: $feed,
                handler: [self::class, 'stocks'],
            ),
            new Operation(
                id: 'catalog.search',
                section: 'catalog',
                method: 'GET',
                uri: 'catalog/search',
                summary: 'Поиск товаров по названию, бренду, артикулу или штрихкоду',
                description: 'Точное совпадение по коду/артикулу/штрихкоду отдаёт только этот товар; иначе — поиск с '
                    .'опечатками и синонимами в порядке релевантности (сначала в наличии). Постраничный (page), '
                    .'до 100 на страницу. meta.no_exact_match — показаны похожие, точного совпадения нет.',
                params: [
                    Param::string('q', 'Строка запроса', true, ['min:2', 'max:200']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'search'],
            ),
            new Operation(
                id: 'catalog.product',
                section: 'catalog',
                method: 'GET',
                uri: 'catalog/products/{identifier}',
                summary: 'Карточка товара по uuid, коду, артикулу или штрихкоду',
                description: 'Цена клиента, остаток по региону, бренд, ссылка на карточку на сайте. Неизвестный '
                    .'идентификатор — 404.',
                params: [Param::string('identifier', 'uuid 1С, код, артикул или штрихкод', true, ['max:255'])],
                handler: [self::class, 'product'],
            ),
            new Operation(
                id: 'catalog.barcode',
                section: 'catalog',
                method: 'GET',
                uri: 'catalog/barcode/{barcode}',
                summary: 'Товар строго по штрихкоду (сценарий сканера)',
                description: 'Только штрихкод товара или дополнительный штрихкод упаковки; код и артикул не подходят.',
                params: [Param::string('barcode', 'Штрихкод', true, ['max:64'])],
                handler: [self::class, 'barcode'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prices(User $actor, OperationInput $input): array
    {
        $currency = $this->currency($actor, $input->string('currency'));

        return $this->feed($actor, $input, function (Collection $products) use ($actor, $currency): array {
            $prices = $this->prices->getPriceMapForProducts($products, $actor);

            return $products->map(fn (Product $p) => $this->identity($p) + $this->price($p, $prices[$p->id] ?? null, $currency))->all();
        }, [
            'currency_code' => $currency?->code ?? 'RUB',
            'currency_symbol' => $currency?->symbol ?? '₽',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stocks(User $actor, OperationInput $input): array
    {
        return $this->feed($actor, $input, function (Collection $products) use ($actor): array {
            $maps = $this->stocks->getStockMapsByIds($products->pluck('id')->map(fn ($id) => (int) $id)->all(), $actor);

            return $products->map(fn (Product $p) => $this->identity($p) + $this->stock($p, $maps))->all();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function search(User $actor, OperationInput $input): array
    {
        $perPage = Envelope::perPage($input->get('per_page'), 20);
        $result = $this->search->paginate((string) $input->string('q'), $perPage, max(1, $input->int('page', 1)));

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $paginator = $result['paginator'];
        $products = collect($paginator->items());
        $currency = $this->currency($actor, null);
        $prices = $this->prices->getPriceMapForProducts($products, $actor);
        $maps = $this->stocks->getStockMapsByIds($products->pluck('id')->map(fn ($id) => (int) $id)->all(), $actor);

        $envelope = Envelope::page($paginator, fn (Product $p) => $this->card($p, $prices[$p->id] ?? null, $currency, $maps));
        $envelope['meta']['no_exact_match'] = $paginator->total() > 0 && ! $result['exact'];
        $envelope['meta']['capped'] = $result['capped'];
        $envelope['meta']['currency_code'] = $currency?->code ?? 'RUB';

        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function product(User $actor, OperationInput $input): array
    {
        $product = $this->identifiers->resolve((string) $input->string('identifier'));

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        return $this->single($actor, $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function barcode(User $actor, OperationInput $input): array
    {
        $product = $this->identifiers->resolveBarcode((string) $input->string('barcode'));

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        return $this->single($actor, $product);
    }

    /**
     * Общий скелет фидов цен и остатков: по списку идентификаторов либо по курсору.
     *
     * @param  callable(Collection<int, Product>): list<array<string, mixed>>  $present
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function feed(User $actor, OperationInput $input, callable $present, array $meta = []): array
    {
        $identifiers = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $input->array('identifiers')), fn ($v) => $v !== ''));

        if ($identifiers !== []) {
            $resolved = $this->identifiers->resolveMany($identifiers);
            $products = collect(array_values($resolved['found']));

            return Envelope::data($present($products), $meta + [
                'requested' => count($identifiers),
                'found' => $products->count(),
                'missing' => $resolved['missing'],
                'ambiguous' => $resolved['ambiguous'],
            ]);
        }

        $perPage = Envelope::perPage($input->get('per_page'), Envelope::PER_PAGE_MAX_FEED, Envelope::PER_PAGE_MAX_FEED);
        $paginator = Product::query()
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $input->string('cursor'));

        $rows = $present(collect($paginator->items()));

        return Envelope::data($rows, $meta + [
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function single(User $actor, Product $product): array
    {
        $product->loadMissing('brand');
        $currency = $this->currency($actor, null);
        $prices = $this->prices->getPriceMapForProducts([$product], $actor);
        $maps = $this->stocks->getStockMapsByIds([(int) $product->id], $actor);

        return Envelope::data($this->card($product, $prices[$product->id] ?? null, $currency, $maps));
    }

    /**
     * Полная карточка: идентификаторы + цена + остаток + бренд и ссылка.
     *
     * @param  array{available: array<int, int>, preorder: array<int, int>}  $maps
     * @return array<string, mixed>
     */
    private function card(Product $product, ?PriceResult $price, ?Currency $currency, array $maps): array
    {
        return $this->identity($product) + [
            'slug' => $product->slug,
            'url' => $product->slug ? route('products.show', $product->slug) : null,
            'brand' => $product->brand?->name,
        ] + $this->price($product, $price, $currency) + $this->stock($product, $maps);
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Product $product): array
    {
        return [
            'uuid' => $product->external_id,
            'code' => $product->code,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function price(Product $product, ?PriceResult $price, ?Currency $currency): array
    {
        $price ??= PriceResult::withoutDiscount((float) $product->base_price);
        $base = round($price->basePrice, 2);
        $display = round($price->getDisplayPrice(), 2);

        if ($currency && ! $currency->is_base) {
            $base = $this->currencies->convertFromBase($base, $currency);
            $display = $this->currencies->convertFromBase($display, $currency);
        }

        return [
            'base_price' => $base,
            'price' => $display,
            'discount_percent' => $price->discountPercent,
            'currency_code' => $currency?->code ?? 'RUB',
        ];
    }

    /**
     * @param  array{available: array<int, int>, preorder: array<int, int>}  $maps
     * @return array<string, mixed>
     */
    private function stock(Product $product, array $maps): array
    {
        $available = (int) ($maps['available'][$product->id] ?? 0);
        $preorder = (int) ($maps['preorder'][$product->id] ?? 0);

        return [
            'available' => $available,
            'preorder' => $preorder,
            'preorder_lead_days' => $available === 0 && $preorder > 0 ? PreorderTerms::leadDays() : null,
        ];
    }

    private function currency(User $actor, ?string $code): ?Currency
    {
        $currency = null;

        if ($code !== null && $code !== '') {
            $currency = Currency::query()->where('code', mb_strtoupper($code))->first();
        }

        return $currency ?? $this->currencyResolver->resolve($actor);
    }
}
