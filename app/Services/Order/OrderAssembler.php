<?php

namespace App\Services\Order;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrdersPlaced;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единственное место, где создаётся заказ.
 *
 * До этого сборка жила в двух местах — `CheckoutService::checkout()` и
 * `ClientApiController::orders()` — и каналы уже успели разойтись: чекаут
 * фиксировал сумму через `saveQuietly()`, API через `update()`, из-за чего
 * в 1С уходил лишний `order.updated`, причём **раньше** `order.created`.
 *
 * Сборщик отвечает ровно за три вещи: создать заказ на каждую непустую группу,
 * создать позиции и зафиксировать сумму, не выпустив `OrderUpdated`. События
 * `OrderCreated` уходят одинаково в обоих каналах — после коммита транзакции.
 *
 * Чего сборщик **не** делает: не проверяет остатки (в чекауте это исключение,
 * в API — дружелюбное урезание), не считает промо и не форматирует лист отбора.
 * Всё это готовит вызывающий и кладёт в OrderDraft.
 */
class OrderAssembler
{
    public function __construct(private readonly PriceServiceInterface $priceService) {}

    /**
     * Создать заказы по заявке. На каждую непустую группу — один заказ.
     *
     * @return Collection<int, Order>
     */
    public function assemble(OrderDraft $draft): Collection
    {
        $orders = collect();

        // Одно оформление — один идентификатор на все документы сборки.
        // По cart_id их связывать нельзя: корзина переиспользуется, и в группу
        // слипалась бы вся её история
        $checkoutUuid = (string) \Illuminate\Support\Str::uuid();

        foreach ($draft->filledGroups() as $type => $lines) {
            $order = Order::create($this->baseData($draft, $type) + ['checkout_uuid' => $checkoutUuid]);

            $order->total_amount = $this->createItems($order, $lines, $draft->user);

            // Только saveQuietly: Order::$dispatchesEvents['updated'] выпустил бы
            // OrderUpdated сразу после создания, и в шину ушёл бы order.updated
            // по документу, о котором 1С ещё не знает
            $order->saveQuietly();

            $orders->push($order);
        }

        // Единый порядок для всех каналов. Вне транзакции колбэк выполняется
        // сразу же, поэтому вызывающему не нужно знать, обёрнут он в неё или нет
        foreach ($orders as $order) {
            DB::afterCommit(static fn () => OrderCreated::dispatch($order));
        }

        // Событие про покупку, а не про документ: одно оформление даёт до пяти
        // заказов, а письмо клиенту должно быть одно. OrderCreated выше остаётся
        // для обмена с 1С, где каждый документ уезжает отдельным сообщением.
        if ($orders->isNotEmpty()) {
            DB::afterCommit(static fn () => OrdersPlaced::dispatch($orders));
        }

        return $orders;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseData(OrderDraft $draft, string $type): array
    {
        return [
            'user_id' => $draft->user->id,
            'company_id' => $draft->company->id,
            // При самовывозе адрес доставки не хранится
            'delivery_address' => $draft->deliveryMethod === \App\Enums\DeliveryMethod::PICKUP
                ? null
                : $draft->deliveryAddress,
            'delivery_method' => $draft->deliveryMethod,
            'cart_id' => $draft->cartId,
            'status' => OrderStatus::PENDING_APPROVAL,
            'comment' => $draft->comment,
            'manager_comment' => $draft->managerComment ?: null,
            'warehouse_comment' => $draft->warehouseCommentFor($type),
            'total_amount' => 0,
            // ?? уже гасит обращение к свойству null — nullsafe здесь лишний
            'exchange_rate' => $draft->currency->exchange_rate ?? 1.0,
            'rate_coefficient' => $draft->currency->rate_coefficient ?? 1.0,
            'currency_code' => $draft->currency->code ?? 'RUB',
            'type' => OrderType::from($type),
        ];
    }

    /**
     * @param  list<OrderLine>  $lines
     */
    private function createItems(Order $order, array $lines, User $user): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            [$price, $basePrice, $discountPercent] = $this->resolvePrice($line, $user);

            $subtotal = $price * $line->quantity;
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line->product->id,
                'product_defect_id' => $line->productDefectId,
                'defect_description' => $line->defectDescription,
                'promotion_rule_id' => $line->promotionRuleId,
                'promo_kind' => $line->promoKind,
                'name' => $line->product->name,
                'price' => $price,
                'base_price' => $basePrice,
                'discount_percent' => $discountPercent,
                'final_price' => $price,
                'quantity' => $line->quantity,
                'subtotal' => $subtotal,
            ]);
        }

        return $total;
    }

    /**
     * @return array{0: float, 1: float, 2: float} цена, базовая цена, скидка в процентах
     */
    private function resolvePrice(OrderLine $line, User $user): array
    {
        // Промо-позиция: цена из награды, но base_price — обычная цена клиента,
        // чтобы в документе было видно размер выгоды. discount_percent —
        // производная и передаётся только для наглядности: 1С берёт final_price
        // авторитетно и сумму через процент не пересчитывает (подтверждено
        // заказчиком). Не «оптимизировать» это поле.
        if ($line->price !== null && $line->promoKind !== null) {
            $base = (float) $this->priceService->getPriceResult($line->product, $user)->getDisplayPrice();

            $discount = $base > 0
                ? round((1 - $line->price / $base) * 100, 2)
                : 0.0;

            return [$line->price, $base, $discount];
        }

        // Фиксированная цена строки (уценка): индивидуальные цены и скидки к ней
        // не применяются — клиент видел именно её
        if ($line->price !== null) {
            return [$line->price, $line->price, 0.0];
        }

        $result = $this->priceService->getPriceResult($line->product, $user);
        $price = $result->getDisplayPrice();

        return [$price, (float) $result->basePrice, (float) $result->discountPercent];
    }
}
