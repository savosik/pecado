<?php

namespace App\Http\Controllers\User;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Substitution\OfferStatus;
use App\Enums\Substitution\SignalEvent;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SubstitutionEvent;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Services\Defect\DefectStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Клиентская страница «Замена по заказу N» — единственное место, куда идёт клиент.
 *
 * Просмотр — по подписанной ссылке из письма, без входа. Согласование — только
 * под авторизацией владельца: кнопка создаёт финансовый документ с индивидуальными
 * ценами, и пересланное письмо не должно давать право постить заказы.
 */
class SubstitutionOfferController extends Controller
{
    public function __construct(
        private readonly PriceServiceInterface $prices,
        private readonly StockServiceInterface $stock,
        private readonly DefectStockService $defectStock,
        private readonly CheckoutServiceInterface $checkout,
    ) {}

    public function show(Request $request, string $offer): InertiaResponse|\Illuminate\Http\RedirectResponse
    {
        $model = SubstitutionOffer::query()
            ->where('uuid', $offer)
            ->with([
                'order.items',
                'user',
                'manager:id,name,email,phone',
                'items' => fn ($q) => $q->active()->with(['product.media', 'productDefect.product.media']),
            ])
            ->firstOrFail();

        // «Согласовать» без входа: запоминаем подписанный URL и ведём на вход —
        // после логина клиент вернётся сюда же, выбор переживёт редирект (localStorage).
        if ($request->boolean('login') && ! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrlWithoutQuery(['login']));

            return redirect()->route('login');
        }

        // Просроченный или закрытый оффер — честная заглушка с контактами менеджера.
        if (! $model->isOpen() || $model->expires_at->isPast()) {
            return Inertia::render('User/Substitutions/Show', [
                'state' => $model->status === OfferStatus::CONFIRMED ? 'confirmed' : 'inactive',
                'offer' => $this->inactivePayload($model),
            ]);
        }

        if ($model->status === OfferStatus::PENDING) {
            $model->update(['status' => OfferStatus::VIEWED, 'viewed_at' => now()]);
            $this->notifyManager($model, 'viewed');
        }

        return Inertia::render('User/Substitutions/Show', [
            'state' => 'open',
            'offer' => $this->payload($model),
            'isOwner' => $request->user() !== null && $request->user()->id === $model->user_id,
        ]);
    }

    /**
     * «Согласовать замену»: программная корзина из выбранного → CheckoutService.
     */
    public function confirm(Request $request, string $offer): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $model = SubstitutionOffer::query()
            ->where('uuid', $offer)
            ->with(['order', 'user', 'items.product', 'items.productDefect', 'manager:id,name,email,phone'])
            ->firstOrFail();

        // Владелец заказа — и только он: подборка с чужого аккаунта не согласуется.
        abort_unless($model->user_id === $user->id, 403, 'Подборка принадлежит другому аккаунту.');

        // Двойной сабмит (второй таб): не ошибка, а честный ответ «уже согласовано».
        if ($model->status === OfferStatus::CONFIRMED) {
            return response()->json([
                'already_confirmed' => true,
                'orders' => $this->resultOrders($model),
            ]);
        }

        abort_unless($model->isOpen() && ! $model->expires_at->isPast(), 422, 'Подборка уже неактуальна — свяжитесь с менеджером.');

        $validated = $request->validate([
            'choices' => ['required', 'array'],
            'choices.*.source_order_item_id' => ['required', 'integer'],
            'choices.*.type' => ['required', 'in:candidate,wait,none'],
            'choices.*.candidate_id' => ['required_if:choices.*.type,candidate', 'nullable', 'integer'],
            'choices.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $sourceOrder = $model->order;
        $chosen = [];

        foreach ($validated['choices'] as $choice) {
            if ($choice['type'] !== 'candidate') {
                continue;
            }

            /** @var SubstitutionOfferItem|null $candidate */
            $candidate = $model->items
                ->where('id', (int) $choice['candidate_id'])
                ->where('source_order_item_id', (int) $choice['source_order_item_id'])
                ->whereNull('removed_by_manager_at')
                ->first();

            abort_if($candidate === null, 422, 'Выбранный вариант замены не найден — обновите страницу.');

            // Кап: увеличить количество сверх отменённого нельзя (докупка — обычный заказ).
            $quantity = min(
                (int) ($choice['quantity'] ?? $candidate->suggested_quantity),
                $candidate->suggested_quantity,
            );

            $chosen[] = ['candidate' => $candidate, 'quantity' => max(1, $quantity)];
        }

        if ($chosen === []) {
            // Клиент осознанно отказался от всех замен: фиксируем и закрываем.
            $this->finalize($model, []);
            $this->notifyManager($model, 'confirmed');

            return response()->json(['confirmed' => true, 'orders' => []]);
        }

        $company = $this->resolveCompany($sourceOrder, $user);

        if ($company === null) {
            return response()->json([
                'message' => 'Не удалось определить компанию для заказа — свяжитесь с менеджером.',
            ], 422);
        }

        try {
            $orders = $this->postReplacementOrders($model, $chosen, $company);
        } catch (InsufficientStockException $e) {
            // Товар кончился, пока клиент думал: не 500, а пересбор кандидатов
            // и человеческое сообщение со списком закончившегося.
            app(\App\Services\Substitution\SubstitutionOfferService::class)->refreshCandidates($model);

            $gone = collect($e->getItems())->pluck('name')->filter()->implode(', ');

            return response()->json([
                'insufficient_stock' => true,
                'message' => trim('Пока вы выбирали, часть товара закончилась: '.$gone.'. Мы обновили остатки — проверьте выбор ещё раз.'),
            ], 409);
        }

        $this->finalize($model, $chosen, $orders->pluck('id')->all());

        $this->notifyManager(
            $model,
            'confirmed',
            $orders->map(fn (Order $o) => (string) ($o->erp_number ?: $o->number))->all(),
        );

        return response()->json([
            'confirmed' => true,
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->erp_number ?: $order->number,
            ])->values(),
            'source_number' => $sourceOrder->erp_number ?: $sourceOrder->number,
        ]);
    }

    /**
     * Программная корзина → CheckoutService: реквизиты и доставка наследуются
     * от исходного заказа, протокол обмена не меняется.
     *
     * @param  list<array{candidate: SubstitutionOfferItem, quantity: int}>  $chosen
     * @return \Illuminate\Support\Collection<int, Order>
     */
    private function postReplacementOrders(SubstitutionOffer $offer, array $chosen, Company $company)
    {
        $sourceOrder = $offer->order;
        $sourceNumber = $sourceOrder->erp_number ?: $sourceOrder->number;

        $cart = Cart::create([
            'user_id' => $offer->user_id,
            'name' => 'Замена недоборов по заказу '.$sourceNumber,
            'is_active' => false,
        ]);

        foreach ($chosen as $entry) {
            $candidate = $entry['candidate'];

            if ($candidate->product_defect_id !== null) {
                $defect = $candidate->productDefect;

                $cart->items()->create([
                    'product_id' => $defect->product_id,
                    'product_defect_id' => $defect->id,
                    'quantity' => $entry['quantity'],
                    'price' => $defect->price,
                    'item_type' => 'defect',
                ]);

                continue;
            }

            $available = $this->stock->getAvailableStock($candidate->product, $offer->user);

            $cart->items()->create([
                'product_id' => $candidate->product_id,
                'quantity' => $entry['quantity'],
                'item_type' => $entry['quantity'] <= $available ? 'instock' : 'preorder',
            ]);
        }

        $cart->load('items.product', 'items.productDefect', 'user');

        $orders = $this->checkout->checkout(
            $cart,
            $company,
            $sourceOrder->delivery_address,
            null,
            'Замена недоборов по заказу '.$sourceNumber,
            null,
            $sourceOrder->delivery_method ?? \App\Enums\DeliveryMethod::DELIVERY,
        );

        // Связи — тихо: OrderCreated уже ушёл в 1С, order.updated из-за
        // внутренней разметки сайта публиковать нельзя.
        foreach ($orders as $order) {
            $order->replacement_for_order_id = $sourceOrder->id;
            $order->saveQuietly();

            foreach ($order->items as $item) {
                $match = collect($chosen)->first(function (array $entry) use ($item) {
                    $candidate = $entry['candidate'];

                    return $candidate->product_defect_id !== null
                        ? $item->product_defect_id === $candidate->product_defect_id
                        : ($item->product_id === $candidate->product_id && $item->product_defect_id === null);
                });

                if ($match !== null) {
                    $item->replaces_order_item_id = $match['candidate']->source_order_item_id;
                    $item->saveQuietly();
                }
            }
        }

        return $orders;
    }

    /**
     * Зафиксировать выбор: chosen/chosen_quantity + события обучающей выборки.
     *
     * @param  list<array{candidate: SubstitutionOfferItem, quantity: int}>  $chosen
     * @param  list<int>  $resultOrderIds
     */
    private function finalize(SubstitutionOffer $offer, array $chosen, array $resultOrderIds = []): void
    {
        DB::transaction(function () use ($offer, $chosen, $resultOrderIds) {
            $chosenIds = [];

            foreach ($chosen as $entry) {
                $entry['candidate']->update([
                    'chosen' => true,
                    'chosen_quantity' => $entry['quantity'],
                ]);
                $chosenIds[] = $entry['candidate']->id;

                SubstitutionEvent::create([
                    'offer_item_id' => $entry['candidate']->id,
                    'event' => SignalEvent::CLIENT_CHOSEN,
                ]);
            }

            $offer->items()
                ->active()
                ->whereNotIn('id', $chosenIds)
                ->get()
                ->each(fn (SubstitutionOfferItem $item) => SubstitutionEvent::create([
                    'offer_item_id' => $item->id,
                    'event' => SignalEvent::CLIENT_SKIPPED,
                ]));

            $offer->update([
                'status' => OfferStatus::CONFIRMED,
                'confirmed_at' => now(),
                'result_order_ids' => $resultOrderIds ?: null,
            ]);
        });
    }

    /**
     * Тихое письмо менеджеру: информирует, не требует действий.
     *
     * @param  list<string>  $orderNumbers
     */
    private function notifyManager(SubstitutionOffer $offer, string $kind, array $orderNumbers = []): void
    {
        if (! config('substitutions.manager_notices', true)) {
            return;
        }

        $email = $offer->manager?->email;

        if (blank($email)) {
            return;
        }

        \Illuminate\Support\Facades\Notification::route('mail', $email)
            ->notify(new \App\Notifications\Substitutions\ShortageManagerNoticeNotification($offer, $kind, $orderNumbers));
    }

    /**
     * Компания заказа-замены: с исходного заказа, иначе основная компания клиента.
     */
    private function resolveCompany(Order $sourceOrder, \App\Models\User $user): ?Company
    {
        if ($sourceOrder->company_id !== null) {
            $company = Company::find($sourceOrder->company_id);

            if ($company !== null) {
                return $company;
            }
        }

        return Company::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SubstitutionOffer $offer): array
    {
        $order = $offer->order;
        $client = $offer->user;
        $candidatesBySource = $offer->items->groupBy('source_order_item_id');
        $cancelledLines = $order->items->where('cancelled', true)->values();

        return [
            'uuid' => $offer->uuid,
            'status' => $offer->status->value,
            'expires_at' => $offer->expires_at->format('d.m.Y'),
            'order_number' => $order->erp_number ?: $order->number,
            'manager' => [
                'name' => $offer->manager?->name,
                'email' => $offer->manager?->email,
                'phone' => $offer->manager?->phone,
            ],
            'lines' => $cancelledLines->map(function (OrderItem $line) use ($candidatesBySource, $client) {
                $preorderStock = $line->product_id && $line->product
                    ? $this->stock->getPreorderStock($line->product, $client)
                    : 0;

                return [
                    'id' => $line->id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'final_price' => (float) $line->final_price,
                    'subtotal' => (float) $line->subtotal,
                    'wait_available' => $preorderStock >= $line->quantity
                        || ($candidatesBySource[$line->id] ?? collect())
                            ->contains(fn (SubstitutionOfferItem $item) => $item->kind === \App\Enums\Substitution\CandidateKind::SAME_PRODUCT_WAIT),
                    'candidates' => ($candidatesBySource[$line->id] ?? collect())
                        ->reject(fn (SubstitutionOfferItem $item) => $item->kind === \App\Enums\Substitution\CandidateKind::SAME_PRODUCT_WAIT)
                        ->map(fn (SubstitutionOfferItem $item) => $this->candidatePayload($item, $client, $line))
                        ->filter(fn (array $payload) => $payload['available'] > 0)
                        ->values(),
                ];
            })->values(),
        ];
    }

    /**
     * Цена — живая, на момент рендера (создаваемый заказ посчитает её же),
     * снапшот из подборки остаётся менеджеру.
     *
     * @return array<string, mixed>
     */
    private function candidatePayload(SubstitutionOfferItem $item, ?\App\Models\User $client, OrderItem $line): array
    {
        if ($item->product_defect_id !== null) {
            $defect = $item->productDefect;
            $product = $defect?->product;
            $available = $defect ? $this->defectStock->available($defect) : 0;
            $price = (float) ($defect?->price ?? 0);
        } else {
            $product = $item->product;
            $available = $product ? $this->stock->getAvailableStock($product, $client) : 0;
            $price = $product ? $this->prices->getUserPrice($product, $client) : 0.0;
        }

        $maxQuantity = min($item->suggested_quantity, max($available, 0));

        return [
            'id' => $item->id,
            'is_defect' => $item->product_defect_id !== null,
            'name' => $product?->name ?? '—',
            'image_url' => $product?->getFirstMediaUrl('images', 'thumb') ?: null,
            'reason' => $item->reason,
            'defect_description' => $item->productDefect?->defect_description,
            'price' => $price,
            'available' => $available,
            'suggested_quantity' => $item->suggested_quantity,
            'max_quantity' => $maxQuantity,
            'partial' => $available > 0 && $available < $line->quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inactivePayload(SubstitutionOffer $offer): array
    {
        return [
            'uuid' => $offer->uuid,
            'status' => $offer->status->value,
            'order_number' => $offer->order?->erp_number ?: $offer->order?->number,
            'manager' => [
                'name' => $offer->manager?->name,
                'email' => $offer->manager?->email,
                'phone' => $offer->manager?->phone,
            ],
            'orders' => $this->resultOrders($offer),
        ];
    }

    /**
     * @return list<array{id: int, number: string}>
     */
    private function resultOrders(SubstitutionOffer $offer): array
    {
        return Order::query()
            ->whereIn('id', $offer->result_order_ids ?? [])
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => (string) ($order->erp_number ?: $order->number),
            ])
            ->all();
    }
}
