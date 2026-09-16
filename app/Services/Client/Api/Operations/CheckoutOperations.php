<?php

namespace App\Services\Client\Api\Operations;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\DeliveryMethod;
use App\Exceptions\DebtRestrictionException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartStockNormalizer;
use App\Services\Client\Api\CompanyContext;
use App\Services\Client\Api\CompanyRequired;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\GateClosed;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Debt\DebtGate;
use App\Services\Order\CabinetCheckout;
use App\Services\Order\CheckoutPreview;
use App\Services\Order\CheckoutRequestDto;
use App\Services\Order\ClientOrderPresenter;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Оформление корзины — тем же слоем, что кнопка «Оформить» в кабинете.
 *
 * В отличие от `orders.create` (список товаров, «дружественное урезание»),
 * чекаут оформляет корзину целиком и отказывает, если остатки изменились
 * (409 stock_changed) — как в кабинете.
 */
class CheckoutOperations implements OperationProvider
{
    use ResolvesClientEntities;

    public function __construct(
        private readonly CartServiceInterface $carts,
        private readonly CheckoutPreview $preview,
        private readonly CabinetCheckout $checkout,
        private readonly CartStockNormalizer $normalizer,
        private readonly CompanyContext $companies,
        private readonly DebtGate $debt,
        private readonly ClientOrderPresenter $presenter,
    ) {}

    public static function section(): array
    {
        return ['checkout', 'Оформление'];
    }

    public static function operations(): array
    {
        $cart = Param::string('cart', 'Корзина: id или active — текущая (по умолчанию)', rules: ['max:20']);

        return [
            new Operation(
                id: 'checkout.preview', section: 'checkout', method: 'GET', uri: 'checkout',
                summary: 'Что будет оформлено: группы строк, итоги, конфликты остатков, долг',
                description: 'Наличие, предзаказ, уценка, промо и образцы уезжают отдельными заказами — здесь они '
                    .'показаны группами с подытогами. `stock_conflicts` — строки, где в корзине больше доступного '
                    .'(оформление откажет, пока не выполнить checkout.normalize). `debt_restriction` — ограничение '
                    .'по лестнице долга для выбранного юрлица, если оно есть. Ничего не пишет.',
                params: [$cart, Param::integer('company_id', 'Юрлицо (по умолчанию основная компания)', rules: ['min:1'])],
                handler: [self::class, 'preview'],
            ),
            new Operation(
                id: 'checkout.normalize', section: 'checkout', method: 'POST', uri: 'checkout/normalize',
                summary: 'Привести количества в корзине к доступному остатку',
                description: 'Больше доступного — уменьшить, доступно 0 — убрать строку. После этого checkout.submit не '
                    .'откажет по остаткам.',
                params: [$cart],
                handler: [self::class, 'normalize'],
                mutating: true,
            ),
            new Operation(
                id: 'checkout.submit', section: 'checkout', method: 'POST', uri: 'checkout',
                summary: 'Оформить корзину в заказы',
                description: 'Создаёт заказы одного оформления (наличие / предзаказ / уценка / промо), очищает корзину, '
                    .'запоминает способ получения. `instock_only=true` — снять предзаказные строки перед оформлением. '
                    .'`reserve=true` — удержание складской части (только участникам режима). Отказы: 409 stock_changed '
                    .'(остатки изменились — см. checkout.normalize), 422 debt_restricted (лестница долга), '
                    .'422 nothing_to_checkout. **Заголовок Idempotency-Key обязателен.**',
                params: [
                    $cart,
                    Param::integer('company_id', 'Юрлицо-покупатель (по умолчанию основная компания)', rules: ['min:1']),
                    Param::string('delivery_method', 'Способ получения', enum: ['delivery', 'pickup']),
                    Param::string('delivery_address', 'Адрес доставки (обязателен при delivery)', rules: ['max:1000'], nullable: true),
                    Param::string('comment', 'Комментарий к заказу', rules: ['max:5000'], nullable: true),
                    Param::string('manager_comment', 'Комментарий менеджеру', nullable: true),
                    Param::string('warehouse_comment', 'Комментарий складу', nullable: true),
                    Param::boolean('instock_only', 'Снять предзаказные строки перед оформлением'),
                    Param::boolean('reserve', 'Поставить складскую часть в резерв'),
                    Param::boolean('save_address', 'Сохранить адрес в адресную книгу'),
                    Param::string('address_name', 'Название адреса для адресной книги', rules: ['max:255'], nullable: true),
                    Param::boolean('address_make_default', 'Сделать адрес основным'),
                ],
                handler: [self::class, 'submit'],
                mutating: true, idempotent: true, idempotencyRequired: true, companyScoped: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $preview = $this->preview->build($actor, $cart);

        $conflicts = [];

        foreach (array_merge($preview['instock_items'], $preview['preorder_items'], $preview['defect_items']) as $row) {
            $max = (int) ($row['max_total'] ?? PHP_INT_MAX);

            if ((int) ($row['quantity'] ?? 0) > $max || ($row['is_unavailable'] ?? false)) {
                $conflicts[] = [
                    'cart_item_id' => $row['id'] ?? null,
                    'product_id' => $row['product']['id'] ?? null,
                    'name' => $row['product']['name'] ?? null,
                    'quantity' => (int) ($row['quantity'] ?? 0),
                    'available' => $max === PHP_INT_MAX ? null : $max,
                ];
            }
        }

        $preview['stock_conflicts'] = $conflicts;
        $preview['preorder_lead_days'] = PreorderTerms::leadDays();
        $preview['debt_restriction'] = null;

        try {
            $company = $this->companies->resolve($actor, $input->int('company_id'));
            $preview['company'] = ['id' => $company->id, 'name' => $company->name, 'inn' => $company->tax_id];
            $this->debt->check($actor, $company, $cart);
        } catch (CompanyRequired $e) {
            $preview['company'] = null;
            $preview['company_choices'] = $e->choices();
        } catch (DebtRestrictionException $e) {
            $preview['debt_restriction'] = $e->toPayload();
        }

        return Envelope::data($preview);
    }

    /** @return array<string, mixed> */
    public function normalize(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);

        return Envelope::data($this->normalizer->normalize($actor, $cart));
    }

    /** @return array<string, mixed> */
    public function submit(User $actor, OperationInput $input): array
    {
        $cart = $this->cart($actor, $input);
        $company = $this->company($input);
        $method = DeliveryMethod::from($input->string('delivery_method') ?? DeliveryMethod::DELIVERY->value);
        $address = $input->string('delivery_address');

        if ($method === DeliveryMethod::DELIVERY && trim((string) $address) === '') {
            throw ValidationException::withMessages(['delivery_address' => 'Укажите адрес доставки.']);
        }

        if ($input->bool('reserve') && ! FeatureGate::RESERVE->allows($actor)) {
            throw new GateClosed(FeatureGate::RESERVE);
        }

        $orders = $this->checkout->submit($actor, $cart, $company, new CheckoutRequestDto(
            deliveryMethod: $method,
            deliveryAddress: $method === DeliveryMethod::DELIVERY ? $address : null,
            comment: $input->string('comment'),
            managerComment: $input->string('manager_comment'),
            warehouseComment: $input->string('warehouse_comment'),
            instockOnly: $input->bool('instock_only'),
            reserve: $input->bool('reserve'),
            saveAddress: $input->bool('save_address'),
            addressName: $input->string('address_name'),
            addressMakeDefault: $input->bool('address_make_default'),
        ));

        return Envelope::data(
            $orders->map(fn (Order $order) => $this->presenter->row($order->fresh(['items', 'company'])))->values()->all(),
            ['created' => true, 'total_orders' => $orders->count()],
        );
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

        $cart = $actor->carts()->whereKey((int) $key)->first();

        if ($cart === null) {
            throw (new ModelNotFoundException)->setModel(Cart::class, [(int) $key]);
        }

        return $cart;
    }
}
