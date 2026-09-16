<?php

namespace App\Services\Client\Api\Operations;

use App\Contracts\Cart\CartServiceInterface;
use App\Models\Cart;
use App\Models\User;
use App\Services\Cart\OrderImportService;
use App\Services\Catalog\ProductIdentifierResolver;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Promotion\CartPromoChoice;
use App\Services\Promotion\CartPromotionProgress;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Корзины клиента: несколько корзин, состав, импорт строк, акции.
 *
 * Корзина — не бизнес-документ, поэтому здесь есть удаление; всё остальное
 * делает `CartService`, тот же, что у кабинета. Параметр `{cart}` принимает id
 * или слово `active` — агент чаще работает с текущей корзиной и не должен
 * сначала спрашивать её номер.
 */
class CartOperations implements OperationProvider
{
    public function __construct(
        private readonly CartServiceInterface $carts,
        private readonly ProductIdentifierResolver $identifiers,
        private readonly OrderImportService $import,
        private readonly CartPromotionProgress $promotions,
        private readonly CartPromoChoice $promoChoice,
    ) {}

    public static function section(): array
    {
        return ['carts', 'Корзины'];
    }

    public static function operations(): array
    {
        $cart = Param::string('cart', 'Корзина: id или active — текущая', true, ['max:20']);

        return [
            new Operation(
                id: 'carts.list', section: 'carts', method: 'GET', uri: 'carts',
                summary: 'Список корзин с итогами',
                description: 'Все корзины клиента: имя, признак активной, количество позиций и сумма по ценам клиента.',
                params: [],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'carts.create', section: 'carts', method: 'POST', uri: 'carts',
                summary: 'Создать корзину и сделать её активной',
                description: 'Новая корзина становится активной; прежние остаются. Принимает Idempotency-Key.',
                params: [Param::string('name', 'Название корзины', rules: ['max:100'])],
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'carts.get', section: 'carts', method: 'GET', uri: 'carts/{cart}',
                summary: 'Состав корзины с ценами, остатками и промо-позициями',
                description: 'Тот же расчёт, что видит клиент в кабинете: цена клиента, доступность, разбиение на '
                    .'наличие/предзаказ, промо-позиции и итоги. `active` вместо id — текущая корзина (создаётся, если нет).',
                params: [$cart],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'carts.rename', section: 'carts', method: 'PATCH', uri: 'carts/{cart}',
                summary: 'Переименовать корзину',
                description: 'Меняет только название.',
                params: [$cart, Param::string('name', 'Новое название', true, ['max:100'])],
                handler: [self::class, 'rename'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.delete', section: 'carts', method: 'DELETE', uri: 'carts/{cart}',
                summary: 'Удалить корзину',
                description: 'Последнюю корзину удалить нельзя (422). Если удалена активная — активной становится первая по порядку.',
                params: [$cart],
                handler: [self::class, 'delete'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.activate', section: 'carts', method: 'POST', uri: 'carts/{cart}/activate',
                summary: 'Сделать корзину активной',
                description: 'Активная корзина — та, в которую попадают добавления без явного cart и которая оформляется в чекауте.',
                params: [$cart],
                handler: [self::class, 'activate'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.add', section: 'carts', method: 'POST', uri: 'carts/{cart}/items',
                summary: 'Добавить товар (к текущему количеству)',
                description: 'Количество прибавляется к уже лежащему в корзине и урезается по доступному остатку '
                    .'(наличие + предзаказ); в ответе — сколько лежит и сколько урезано.',
                params: [
                    $cart,
                    Param::string('identifier', 'uuid 1С, код, артикул или штрихкод товара', true, ['max:255']),
                    Param::integer('quantity', 'Сколько добавить', true, ['min:1']),
                ],
                handler: [self::class, 'add'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.set-quantity', section: 'carts', method: 'PUT', uri: 'carts/{cart}/items',
                summary: 'Задать количество товара (0 — убрать)',
                description: 'Целевое количество, а не прибавка. Урезается по доступному остатку.',
                params: [
                    $cart,
                    Param::string('identifier', 'uuid 1С, код, артикул или штрихкод товара', true, ['max:255']),
                    Param::integer('quantity', 'Целевое количество, 0 — убрать из корзины', true, ['min:0']),
                ],
                handler: [self::class, 'setQuantity'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.set-quantities', section: 'carts', method: 'PUT', uri: 'carts/{cart}/items/bulk',
                summary: 'Задать количества нескольких товаров одним вызовом',
                description: 'rows — список {identifier, quantity}; целевые количества. Ненайденные и неоднозначные '
                    .'идентификаторы перечислены в meta.unresolved, остальное применяется.',
                params: [
                    $cart,
                    Param::list('rows', 'Строки {identifier, quantity}', 'object', true, ['min:1', 'max:500']),
                ],
                handler: [self::class, 'setQuantities'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.add-by-barcode', section: 'carts', method: 'POST', uri: 'carts/{cart}/barcode',
                summary: 'Добавить товар по штрихкоду (сценарий сканера)',
                description: 'Статусы в ответе: success — добавлено; partial — добавлено меньше запрошенного; warning — '
                    .'достигнут максимум, ничего не добавлено. Неизвестный штрихкод — 404.',
                params: [$cart, Param::string('barcode', 'Штрихкод', true, ['max:64']), Param::integer('quantity', 'Сколько добавить', rules: ['min:1'])],
                handler: [self::class, 'addByBarcode'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.import', section: 'carts', method: 'POST', uri: 'carts/{cart}/import',
                summary: 'Импорт строк «идентификатор × количество»',
                description: 'mode=merge (по умолчанию) прибавляет к текущему, mode=replace очищает корзину и кладёт '
                    .'только импортированное. Ответ — что распознано, что нет и почему.',
                params: [
                    $cart,
                    Param::list('rows', 'Строки {identifier, quantity}', 'object', true, ['min:1', 'max:1000']),
                    Param::string('mode', 'merge — прибавить, replace — заменить состав', enum: ['merge', 'replace']),
                ],
                handler: [self::class, 'import'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.clear', section: 'carts', method: 'POST', uri: 'carts/{cart}/clear',
                summary: 'Очистить корзину',
                description: 'Удаляет все строки, сама корзина остаётся.',
                params: [$cart],
                handler: [self::class, 'clear'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.promotions', section: 'carts', method: 'GET', uri: 'carts/{cart}/promotions',
                summary: 'Акции по корзине: что начислено и до чего не хватило',
                description: 'Прогресс по правилам акций для текущего состава и промо-позиции, которые уедут отдельным заказом.',
                params: [$cart],
                handler: [self::class, 'promotions'],
            ),
            new Operation(
                id: 'carts.promo-select', section: 'carts', method: 'POST', uri: 'carts/{cart}/promotions/select',
                summary: 'Выбрать вариант награды по акции',
                description: 'Для наград «на выбор»: rule_id и reward_index из carts.promotions, product_id — выбранный товар.',
                params: [
                    $cart,
                    Param::integer('rule_id', 'Правило акции', true, ['exists:promotion_rules,id']),
                    Param::integer('reward_index', 'Индекс награды в правиле', true, ['min:0']),
                    Param::integer('product_id', 'Выбранный товар', true, ['exists:products,id']),
                ],
                handler: [self::class, 'promoSelect'],
                mutating: true,
            ),
            new Operation(
                id: 'carts.promo-decline', section: 'carts', method: 'POST', uri: 'carts/{cart}/promotions/decline',
                summary: 'Отказаться от платной промо-позиции или вернуть её',
                description: 'От бесплатного подарка отказаться нельзя (422). declined=false возвращает позицию.',
                params: [
                    $cart,
                    Param::integer('rule_id', 'Правило акции', true, ['exists:promotion_rules,id']),
                    Param::integer('reward_index', 'Индекс награды в правиле', true, ['min:0']),
                    Param::boolean('declined', 'true — отказаться, false — вернуть', true),
                ],
                handler: [self::class, 'promoDecline'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $carts = $actor->carts()->orderBy('id')->get();
        $summaries = $this->carts->getCartsSummary($carts, $actor);

        return Envelope::data($carts->map(fn (Cart $cart) => $this->row($cart, $summaries[$cart->id] ?? []))->values()->all());
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $cart = $this->carts->createCart($actor, $input->string('name'));

        return Envelope::data($this->row($cart, $this->carts->getCartSummary($cart, $actor)), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        return $this->details($actor, $this->cart($actor, $input));
    }

    /** @return array<string, mixed> */
    public function rename(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $this->carts->renameCart($actor, $cart, (string) $input->string('name'));

        return Envelope::data($this->row($cart->refresh(), $this->carts->getCartSummary($cart, $actor)));
    }

    /** @return array<string, mixed> */
    public function delete(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $this->carts->deleteCart($actor, $cart);

        return Envelope::data(['deleted' => true, 'id' => (int) $cart->id]);
    }

    /** @return array<string, mixed> */
    public function activate(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $this->carts->switchActiveCart($actor, $cart);

        return Envelope::data($this->row($cart->refresh(), $this->carts->getCartSummary($cart, $actor)));
    }

    /** @return array<string, mixed> */
    public function add(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $product = $this->product((string) $input->string('identifier'));
        $result = $this->carts->addProduct($actor, $cart, $product, (int) $input->int('quantity'));

        return Envelope::data($this->lineResult($product->id, $product->name, $result));
    }

    /** @return array<string, mixed> */
    public function setQuantity(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $product = $this->product((string) $input->string('identifier'));
        $result = $this->carts->setProductQuantity($actor, $cart, $product, (int) $input->int('quantity'));

        return Envelope::data($this->lineResult($product->id, $product->name, $result));
    }

    /** @return array<string, mixed> */
    public function setQuantities(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        [$targets, $unresolved] = $this->targets($input->array('rows'));

        $result = $this->carts->setProductsQuantity($actor, $cart, $targets);

        return Envelope::data([
            'items' => $result['items'] ?? [],
            'cart_totals' => $result['cart_totals'] ?? null,
        ], ['applied' => count($targets), 'unresolved' => $unresolved]);
    }

    /** @return array<string, mixed> */
    public function addByBarcode(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $result = $this->carts->addByBarcode($actor, $cart, (string) $input->string('barcode'), max(1, $input->int('quantity', 1)));

        if ($result['status'] === 'not_found') {
            throw (new ModelNotFoundException)->setModel(\App\Models\Product::class);
        }

        return Envelope::data($result);
    }

    /** @return array<string, mixed> */
    public function import(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $mode = $input->string('mode', 'merge');

        $rows = array_map(fn ($row) => [
            'identifier' => (string) ($row['identifier'] ?? ''),
            'quantity' => (string) ($row['quantity'] ?? ''),
        ], $input->array('rows'));

        $resolution = $this->import->resolve($rows);
        $targets = [];

        if ($mode === 'replace') {
            $cart->clear();
        }

        foreach ($resolution['resolved'] as $row) {
            $pid = (int) $row['product_id'];
            $current = $mode === 'replace' ? 0 : (int) $cart->items()->where('product_id', $pid)->excludingDefect()->sum('quantity');
            $targets[$pid] = $current + (int) $row['quantity'];
        }

        $result = $targets !== [] ? $this->carts->setProductsQuantity($actor, $cart, $targets) : null;

        return Envelope::data([
            'mode' => $mode,
            'resolved' => $resolution['resolved'],
            'unresolved' => $resolution['unresolved'],
            'cart_totals' => $result['cart_totals'] ?? null,
        ], ['added_count' => count($resolution['resolved'])]);
    }

    /** @return array<string, mixed> */
    public function clear(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $removed = $cart->clear();

        return Envelope::data(['cleared' => true, 'removed_lines' => $removed]);
    }

    /** @return array<string, mixed> */
    public function promotions(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $details = $this->carts->getCartDetails($cart->fresh(), $actor);

        return Envelope::data([
            'progress' => $this->promotions->forCart($cart, $actor),
            'promo_items' => $details['promo_items'] ?? [],
            'promo_quantity' => $details['promo_quantity'] ?? 0,
            'promo_amount' => $details['promo_amount'] ?? 0,
        ]);
    }

    /** @return array<string, mixed> */
    public function promoSelect(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $this->promoChoice->select($cart, (int) $input->int('rule_id'), (int) $input->int('reward_index'), (int) $input->int('product_id'));

        return $this->promotions($actor, $input);
    }

    /** @return array<string, mixed> */
    public function promoDecline(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $this->promoChoice->decline($cart, (int) $input->int('rule_id'), (int) $input->int('reward_index'), $input->bool('declined'));

        return $this->promotions($actor, $input);
    }

    /**
     * Корзина по аргументу `cart`: id среди своих либо `active`.
     */
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

    private function product(string $identifier): \App\Models\Product
    {
        $product = $this->identifiers->resolve($identifier);

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(\App\Models\Product::class);
        }

        return $product;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array{0: array<int, int>, 1: list<array<string, mixed>>}
     */
    private function targets(array $rows): array
    {
        $identifiers = [];

        foreach ($rows as $row) {
            $identifiers[] = trim((string) (is_array($row) ? ($row['identifier'] ?? '') : ''));
        }

        $resolved = $this->identifiers->resolveMany(array_values(array_filter($identifiers, fn ($v) => $v !== '')));
        $targets = [];
        $unresolved = [];

        foreach ($rows as $row) {
            $identifier = trim((string) (is_array($row) ? ($row['identifier'] ?? '') : ''));
            $quantity = is_array($row) ? ($row['quantity'] ?? null) : null;

            if ($identifier === '') {
                continue;
            }

            if (! is_numeric($quantity) || (int) $quantity < 0) {
                $unresolved[] = ['identifier' => $identifier, 'reason' => 'Неверное количество'];

                continue;
            }

            $product = $resolved['found'][$identifier] ?? null;

            if ($product === null) {
                $unresolved[] = [
                    'identifier' => $identifier,
                    'reason' => in_array($identifier, $resolved['ambiguous'], true)
                        ? 'Неоднозначный идентификатор — найдено несколько товаров'
                        : 'Товар не найден',
                ];

                continue;
            }

            $targets[(int) $product->id] = (int) $quantity;
        }

        return [$targets, $unresolved];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function row(Cart $cart, array $summary): array
    {
        return [
            'id' => (int) $cart->id,
            'name' => $cart->name,
            'is_active' => (bool) $cart->is_active,
            'items_count' => $summary['items_count'] ?? 0,
            'total_price' => $summary['total_price'] ?? 0,
            'available_count' => $summary['available_count'] ?? 0,
            'preorder_count' => $summary['preorder_count'] ?? 0,
            'updated_at' => $cart->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function details(User $actor, Cart $cart): array
    {
        $details = $this->carts->getCartDetails($cart->fresh(), $actor);

        return Envelope::data([
            'id' => (int) $cart->id,
            'name' => $cart->name,
            'is_active' => (bool) $cart->is_active,
        ] + $details);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function lineResult(int $productId, string $name, array $result): array
    {
        return [
            'product_id' => $productId,
            'product_name' => $name,
            'instock' => $result['instock'] ?? 0,
            'preorder' => $result['preorder'] ?? 0,
            'clamped' => $result['clamped'] ?? 0,
            'max_total' => $result['max_total'] ?? 0,
            'cart_totals' => $result['cart_totals'] ?? null,
        ];
    }
}
