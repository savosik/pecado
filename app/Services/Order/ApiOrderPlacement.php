<?php

namespace App\Services\Order;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\ProductIdentifierResolver;
use App\Services\Promotion\ClientApiPromotions;
use Illuminate\Support\Facades\DB;

/**
 * Размещение заказа по списку идентификаторов — «дружественный» приём.
 *
 * Единственная реализация для legacy `/api/client-api/{token}/orders` и v1
 * `orders.create`: заказ принимается, даже если часть позиций недоступна.
 * Недостающее не блокирует заказ, а попадает в ответ (not_accepted — в заказ
 * не попали, partial — приняты не полностью) и в журнал изменений заказа.
 * Совсем нечего отгружать — {@see NothingToPlaceException}.
 *
 * Гейт режима резервов и выбор юрлица остаются на транспорте: у legacy это
 * ИНН и 403 reserve_unavailable, у v1 — контекст юрлица и фича-гейт.
 */
class ApiOrderPlacement
{
    public function __construct(
        private readonly ProductIdentifierResolver $identifiers,
        private readonly StockServiceInterface $stocks,
        private readonly UserCurrencyResolverInterface $currencyResolver,
        private readonly ClientApiPromotions $promotions,
        private readonly OrderAssembler $assembler,
        private readonly OrderChangeLogger $changeLogger,
        private readonly ReservePolicy $reservePolicy,
    ) {}

    /**
     * @throws NothingToPlaceException
     */
    public function place(User $user, Company $company, PlacementRequest $request): PlacementResult
    {
        $instockItems = [];
        $preorderItems = [];
        $notAccepted = [];
        $partial = [];

        foreach ($request->products as $idx => $item) {
            $requestedQty = (int) $item['quantity'];
            $identifier = (string) $item['identifier'];
            $line = $idx + 1; // 1-based номер строки запроса — для сопоставления дублей identifier

            $product = $this->identifiers->resolve($identifier);

            if (! $product) {
                $notAccepted[] = [
                    'line' => $line,
                    'identifier' => $identifier,
                    'product_id' => null,
                    'slug' => null,
                    'name' => $identifier,
                    'requested' => $requestedQty,
                    'reason' => 'not_found',
                    'message' => 'Товар не найден',
                ];

                continue;
            }

            $stock = $this->stocks->getStock($product, $user);
            $available = (int) $stock['available'];
            $preorder = (int) $stock['preorder'];
            $totalAvailable = $available + $preorder;

            if ($totalAvailable <= 0) {
                $notAccepted[] = [
                    'line' => $line,
                    'identifier' => $identifier,
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'reason' => 'out_of_stock',
                    'message' => 'Нет в наличии',
                ];

                continue;
            }

            // Отгружаем столько, сколько реально доступно; остаток запроса — в shortfall.
            $fulfillQty = min($requestedQty, $totalAvailable);
            $instockQty = min($fulfillQty, $available);
            $preorderQty = $fulfillQty - $instockQty;

            if ($instockQty > 0) {
                $instockItems[] = ['product' => $product, 'quantity' => $instockQty];
            }

            if ($preorderQty > 0) {
                $preorderItems[] = ['product' => $product, 'quantity' => $preorderQty];
            }

            if ($fulfillQty < $requestedQty) {
                $partial[] = [
                    'line' => $line,
                    'identifier' => $identifier,
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'requested' => $requestedQty,
                    'fulfilled' => $fulfillQty,
                    'shortfall' => $requestedQty - $fulfillQty,
                ];
            }
        }

        if ($instockItems === [] && $preorderItems === []) {
            throw new NothingToPlaceException($notAccepted);
        }

        $currency = $this->currencyResolver->resolve($user);

        // Комментарий дополняется системной пометкой о недоступных/частичных
        // позициях, чтобы менеджер и 1С видели, что клиент запрашивал больше.
        $comment = $request->comment;

        if ($note = $this->fulfillmentNote($notAccepted, $partial)) {
            $comment = $comment !== null && $comment !== '' ? ($comment."\n\n".$note) : $note;
        }

        // Акции считаются по принятым позициям, а не по запрошенным: иначе подарок
        // уедет за товар, которого не отгрузили.
        $promoResult = $request->applyPromotions
            ? $this->promotions->resolve(array_merge($instockItems, $preorderItems), $user)
            : null;

        $draft = new OrderDraft(
            user: $user,
            company: $company,
            deliveryMethod: $request->deliveryMethod,
            groups: [
                OrderType::ORDER->value => $this->lines($instockItems),
                OrderType::PREORDER->value => $this->lines($preorderItems),
                OrderType::PROMO->value => $promoResult?->groups[OrderType::PROMO->value] ?? [],
                OrderType::PROMO_SAMPLE->value => $promoResult?->groups[OrderType::PROMO_SAMPLE->value] ?? [],
            ],
            deliveryAddress: $request->address,
            comment: $comment,
            currency: $currency,
            warehouseComments: $promoResult !== null ? $promoResult->warehouseComments : [],
            reserve: $request->reserve,
            reservedUntil: $request->reserve ? $this->reservePolicy->requestedReservedUntil($user) : null,
        );

        // Заказы и запись о недостаче — одной транзакцией. OrderCreated сборщик
        // выпустит после коммита, одинаково с чекаутом.
        $orders = DB::transaction(function () use ($draft, $notAccepted, $partial) {
            $orders = $this->assembler->assemble($draft);

            if ($notAccepted !== [] || $partial !== []) {
                $this->changeLogger->logApiShortfall(
                    $orders->first(),
                    array_map(fn (array $u) => [
                        'product_id' => $u['product_id'] ?? null,
                        'slug' => $u['slug'] ?? null,
                        'product_name' => $u['name'] ?? $u['identifier'],
                        'requested' => $u['requested'],
                        'reason' => $u['reason'] ?? null,
                        'message' => $u['message'] ?? null,
                    ], $notAccepted),
                    array_map(fn (array $p) => [
                        'product_id' => $p['product_id'] ?? null,
                        'slug' => $p['slug'] ?? null,
                        'product_name' => $p['name'] ?? $p['identifier'],
                        'requested' => $p['requested'],
                        'fulfilled' => $p['fulfilled'],
                    ], $partial),
                );
            }

            return $orders->all();
        });

        return new PlacementResult(array_values($orders), $notAccepted, $partial, $promoResult);
    }

    /**
     * Текстовая пометка о недоступных/частичных позициях для комментария заказа.
     *
     * @param  list<array<string, mixed>>  $notAccepted
     * @param  list<array<string, mixed>>  $partial
     */
    private function fulfillmentNote(array $notAccepted, array $partial): ?string
    {
        if ($notAccepted === [] && $partial === []) {
            return null;
        }

        $lines = ['[API] Заказ принят не в полном объёме:'];

        foreach ($notAccepted as $u) {
            $label = $u['name'] ?? $u['identifier'];
            $lines[] = "— «{$label}» (запрошено {$u['requested']}): {$u['message']}";
        }

        foreach ($partial as $p) {
            $lines[] = "— «{$p['name']}»: запрошено {$p['requested']}, отгружено {$p['fulfilled']}, не хватило {$p['shortfall']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{product: \App\Models\Product, quantity: int}>  $items
     * @return list<OrderLine>
     */
    private function lines(array $items): array
    {
        return array_map(
            static fn (array $item) => new OrderLine($item['product'], $item['quantity']),
            array_values($items),
        );
    }
}
