<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Support\Collection;

/**
 * Товар по идентификатору, который клиент передал как ему удобно.
 *
 * Единственное место разбора «uuid / код 1С / артикул / штрихкод»: раньше
 * legacy client-api, импорт заказа в корзину и поиск делали это по-своему, и
 * одинаковый артикул мог найтись в одном месте и не найтись в другом.
 *
 * Порядок совпадений сохранён от legacy: uuid (external_id) → code → sku →
 * barcode товара → дополнительные штрихкоды (product_barcodes). Числовой id
 * товара намеренно не принимается: артикулы и штрихкоды тоже бывают числами,
 * и «2209» означало бы разные товары в зависимости от того, что нашлось первым.
 */
class ProductIdentifierResolver
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Один товар по одному идентификатору; null — не найден.
     */
    public function resolve(string $identifier): ?Product
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (preg_match(self::UUID, $identifier)) {
            $product = Product::query()->where('external_id', $identifier)->first();

            if ($product) {
                return $product;
            }
        }

        return Product::query()->where('code', $identifier)->first()
            ?? Product::query()->where('sku', $identifier)->first()
            ?? Product::query()->where('barcode', $identifier)->first()
            ?? ProductBarcode::query()->where('barcode', $identifier)->with('product')->first()?->product;
    }

    /**
     * Товар строго по штрихкоду (сценарий сканера): свой штрихкод или дополнительный.
     */
    public function resolveBarcode(string $barcode): ?Product
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return null;
        }

        return Product::query()->where('barcode', $barcode)->first()
            ?? ProductBarcode::query()->where('barcode', $barcode)->with('product')->first()?->product;
    }

    /**
     * Пакетный разбор: карта «идентификатор в нижнем регистре → коллекция товаров».
     *
     * Несколько товаров под одним ключом — неоднозначность (одинаковый штрихкод
     * у разных карточек), решать её должен вызывающий, а не резолвер.
     *
     * @param  list<string>  $identifiers
     * @return Collection<string, Collection<int, Product>>
     */
    public function lookup(array $identifiers): Collection
    {
        $unique = collect($identifiers)
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        if ($unique->isEmpty()) {
            return collect();
        }

        $needles = $unique->all();
        $uuids = array_values(array_filter($needles, fn (string $v) => (bool) preg_match(self::UUID, $v)));

        /** @var array<string, array<int, Product>> $map */
        $map = [];

        $add = function (?string $value, Product $product) use (&$map) {
            $key = mb_strtolower(trim((string) $value));

            if ($key === '') {
                return;
            }

            $map[$key][$product->getKey()] = $product;
        };

        Product::query()
            ->where(function ($q) use ($needles, $uuids) {
                $q->whereIn('sku', $needles)
                    ->orWhereIn('code', $needles)
                    ->orWhereIn('barcode', $needles);

                if ($uuids !== []) {
                    $q->orWhereIn('external_id', $uuids);
                }
            })
            ->get()
            ->each(function (Product $p) use ($add) {
                $add($p->sku, $p);
                $add($p->code, $p);
                $add($p->barcode, $p);
                $add($p->external_id, $p);
            });

        ProductBarcode::query()
            ->whereIn('barcode', $needles)
            ->with('product')
            ->get()
            ->each(function (ProductBarcode $pb) use ($add) {
                if ($pb->product) {
                    $add($pb->barcode, $pb->product);
                }
            });

        $result = collect();

        foreach ($needles as $needle) {
            $key = mb_strtolower($needle);

            if (isset($map[$key]) && ! $result->has($key)) {
                $result->put($key, collect(array_values($map[$key])));
            }
        }

        return $result;
    }

    /**
     * Пакетный разбор с разложением на найденные, отсутствующие и неоднозначные.
     *
     * @param  list<string>  $identifiers
     * @return array{found: array<string, Product>, missing: list<string>, ambiguous: list<string>}
     */
    public function resolveMany(array $identifiers): array
    {
        $lookup = $this->lookup($identifiers);
        $found = [];
        $missing = [];
        $ambiguous = [];

        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);

            if ($identifier === '' || isset($found[$identifier])) {
                continue;
            }

            $products = $lookup->get(mb_strtolower($identifier));

            if ($products === null || $products->isEmpty()) {
                $missing[] = $identifier;
            } elseif ($products->count() > 1) {
                $ambiguous[] = $identifier;
            } else {
                $found[$identifier] = $products->first();
            }
        }

        return ['found' => $found, 'missing' => array_values(array_unique($missing)), 'ambiguous' => array_values(array_unique($ambiguous))];
    }
}
