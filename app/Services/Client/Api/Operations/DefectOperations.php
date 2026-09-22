<?php

namespace App\Services\Client\Api\Operations;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Defect\DefectStockServiceInterface;
use App\Models\Cart;
use App\Models\ProductDefect;
use App\Models\User;
use App\Services\Catalog\ProductIdentifierResolver;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Уценка (некондиция) глазами агента клиента: партии в продаже, партии
 * конкретного товара, партия с фото, строка уценки в корзине.
 *
 * Единица — партия (product_defects): дефект + количество + фото + цена.
 * Свободный остаток — производная (quantity − резерв живых заказов), считает
 * DefectStockService. В корзине уценка лежит отдельной строкой на партию,
 * цена фиксирована, скидки и акции к ней не применяются; заказ уходит
 * отдельным документом type=defect через обычный checkout.
 */
class DefectOperations implements OperationProvider
{
    public function __construct(
        private readonly DefectStockServiceInterface $stock,
        private readonly CartServiceInterface $carts,
        private readonly ProductIdentifierResolver $identifiers,
    ) {}

    public static function section(): array
    {
        return ['defects', 'Уценка'];
    }

    public static function operations(): array
    {
        $cart = Param::string('cart', 'Корзина: id или active — текущая', true, ['max:20']);
        $defect = Param::integer('defect_id', 'id партии уценки из defects.list', true, ['min:1']);

        return [
            new Operation(
                id: 'defects.list', section: 'defects', method: 'GET', uri: 'defects',
                summary: 'Партии уценки в продаже: дефект, цена, остаток, фото',
                description: 'Только опубликованные партии со свободным остатком. q — поиск по названию или артикулу товара. '
                    .'Цена партии фиксированная, скидки и акции не применяются.',
                params: [
                    Param::string('q', 'Поиск по названию или артикулу товара', rules: ['max:200']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'defects.for-product', section: 'defects', method: 'GET', uri: 'products/{identifier}/defects',
                summary: 'Партии уценки конкретного товара',
                description: 'Товар по uuid 1С, коду, артикулу или штрихкоду. Пустой список — уценки на товар нет.',
                params: [Param::string('identifier', 'uuid 1С, код, артикул или штрихкод товара', true, ['max:255'])],
                handler: [self::class, 'forProduct'],
            ),
            new Operation(
                id: 'defects.get', section: 'defects', method: 'GET', uri: 'defects/{defect}',
                summary: 'Партия уценки целиком: описание дефекта, фото, остаток',
                description: 'Все фото партии полноразмерными ссылками. Закрытая, неопубликованная или распроданная партия — 404.',
                params: [Param::integer('defect', 'id партии', true)],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'carts.add-defect', section: 'carts', method: 'POST', uri: 'carts/{cart}/defects',
                summary: 'Положить партию уценки в корзину (к текущему количеству)',
                description: 'Отдельная строка на партию; количество урезается по свободному остатку партии. '
                    .'В ответе quantity — сколько теперь лежит, available — остаток партии.',
                params: [$cart, $defect, Param::integer('quantity', 'Сколько добавить', true, ['min:1'])],
                handler: [self::class, 'addToCart'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.set-defect-quantity', section: 'carts', method: 'PUT', uri: 'carts/{cart}/defects',
                summary: 'Задать количество партии уценки в корзине (0 — убрать)',
                description: 'Итоговое количество строки; больше свободного остатка партии не положить, 0 убирает строку.',
                params: [$cart, $defect, Param::integer('quantity', 'Сколько должно лежать; 0 убирает строку', true, ['min:0'])],
                handler: [self::class, 'setCartQuantity'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $q = trim((string) $input->string('q', ''));

        $query = ProductDefect::query()
            ->sellable()
            ->with(['product:id,name,sku,slug', 'media'])
            ->when($q !== '', fn ($builder) => $builder->whereHas('product', function ($p) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $p->where('name', 'like', $like)->orWhere('sku', 'like', $like);
            }))
            ->orderBy('price')
            ->orderBy('id');

        $paginator = $query->cursorPaginate(
            Envelope::perPage($input->int('per_page'), 50),
            ['*'],
            'cursor',
            $input->string('cursor') ?: null,
        );

        $available = $this->stock->availableMap($paginator->items());

        // Партии без свободного остатка (всё в заказах) клиенту не показываем,
        // но курсор идёт по всем sellable — иначе страница «плавала» бы.
        $rows = [];

        foreach ($paginator->items() as $defect) {
            if (($available[(int) $defect->id] ?? 0) > 0) {
                $rows[] = $this->row($defect, $available[(int) $defect->id], false);
            }
        }

        return Envelope::data($rows, [
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'catalog_url' => url('/products/utsenka'),
        ]);
    }

    /** @return array<string, mixed> */
    public function forProduct(User $actor, OperationInput $input): array
    {
        $product = $this->identifiers->resolve((string) $input->string('identifier'));

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(\App\Models\Product::class);
        }

        $rows = $this->stock->sellableForProduct($product)
            ->map(fn (ProductDefect $defect) => $this->row($defect, (int) $defect->available_quantity, true))
            ->values()
            ->all();

        return Envelope::data($rows, ['product' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name]]);
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        $defect = ProductDefect::query()->sellable()->with(['product:id,name,sku,slug', 'media'])->findOrFail((int) $input->int('defect'));
        $available = $this->stock->available($defect);

        if ($available <= 0) {
            throw (new ModelNotFoundException)->setModel(ProductDefect::class);
        }

        return Envelope::data($this->row($defect, $available, true));
    }

    /** @return array<string, mixed> */
    public function addToCart(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $defect = $this->sellable((int) $input->int('defect_id'));
        $result = $this->carts->addDefect($actor, $cart, $defect, (int) $input->int('quantity'));

        return Envelope::data($this->cartLine($defect, $result));
    }

    /** @return array<string, mixed> */
    public function setCartQuantity(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $defect = $this->sellable((int) $input->int('defect_id'));
        $result = $this->carts->setDefectQuantity($actor, $cart, $defect, (int) $input->int('quantity'));

        return Envelope::data($this->cartLine($defect, $result));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProductDefect $defect, int $available, bool $allPhotos): array
    {
        $photos = $defect->getMedia(ProductDefect::MEDIA_COLLECTION);

        if (! $allPhotos) {
            $photos = $photos->take(1);
        }

        return [
            'id' => (int) $defect->id,
            'product' => [
                'id' => (int) $defect->product->id,
                'sku' => $defect->product->sku,
                'name' => $defect->product->name,
                'slug' => $defect->product->slug,
            ],
            'defect' => $defect->defect_description,
            'price' => (float) $defect->price,
            'available' => $available,
            'photos' => $photos->map(fn ($media) => $media->hasGeneratedConversion('thumb') && ! $allPhotos
                ? $media->getUrl('thumb')
                : $media->getUrl())->values()->all(),
            'photos_total' => $defect->getMedia(ProductDefect::MEDIA_COLLECTION)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function cartLine(ProductDefect $defect, array $result): array
    {
        return [
            'defect_id' => (int) $defect->id,
            'product_name' => $defect->product->name,
            'quantity' => (int) ($result['quantity'] ?? 0),
            'available' => (int) ($result['available'] ?? 0),
            'price' => (float) $defect->price,
            'cart_totals' => $result['cart_totals'] ?? null,
        ];
    }

    private function sellable(int $id): ProductDefect
    {
        return ProductDefect::query()->sellable()->with('product:id,name,sku,slug')->findOrFail($id);
    }

    private function cart(User $actor, OperationInput $input): Cart
    {
        $key = trim((string) $input->string('cart', 'active'));

        if ($key === '' || $key === 'active') {
            return $this->carts->getOrCreateActiveCart($actor);
        }

        if (! ctype_digit($key)) {
            throw ValidationException::withMessages(['cart' => 'Корзина задаётся числовым id или словом active.']);
        }

        return $actor->carts()->whereKey((int) $key)->firstOrFail();
    }
}
