<?php

namespace App\Http\Controllers\Crm;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\Crm\CrmScope;
use App\Enums\Crm\TaskStatus;
use App\Enums\Substitution\CandidateKind;
use App\Enums\Substitution\LinkKind;
use App\Enums\Substitution\LinkSource;
use App\Enums\Substitution\OfferStatus;
use App\Enums\Substitution\SignalEvent;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSubstitution;
use App\Models\SubstitutionEvent;
use App\Models\SubstitutionOffer;
use App\Models\SubstitutionOfferItem;
use App\Models\User;
use App\Services\Substitution\ShortageEmailDraftService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * CRM-раздел «Недоборы»: очередь подборок замен и карточка решения.
 *
 * Нагрузка — единицы событий в неделю, поэтому раздел затачивается на сценарий
 * «зашёл по задаче, решил за пять минут, ушёл»: карточка собирает всё решение
 * на одном экране, четыре исхода — по одной кнопке.
 */
class ShortageController extends CrmController
{
    public function __construct(
        private readonly PriceServiceInterface $prices,
        private readonly StockServiceInterface $stock,
        private readonly ShortageEmailDraftService $drafts,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->crmActor($request);
        $scope = CrmScope::fromRequest($request, $actor);
        $status = (string) $request->input('status', 'open');

        $query = $this->visibleOffers($request, $scope)
            ->with(['order:id,number,erp_number,erp_created_at,created_at', 'user:id,name', 'manager:id,name'])
            ->withCount(['items as candidates_count' => fn ($q) => $q->whereNull('removed_by_manager_at')]);

        $this->applyStatusFilter($query, $status);

        $urgentAmount = (float) config('substitutions.urgent_amount', 10000);

        // Сумма потери — по отменённым строкам исходного заказа; срочные сверху.
        $query->addSelect([
            'cancelled_amount' => OrderItem::query()
                ->selectRaw('COALESCE(SUM(subtotal), 0)')
                ->whereColumn('order_items.order_id', 'substitution_offers.order_id')
                ->where('cancelled', true),
        ]);

        $offers = $query
            ->orderByRaw('(cancelled_amount > ?) desc', [$urgentAmount])
            ->orderBy('expires_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SubstitutionOffer $offer) => $this->queueRow($offer, $urgentAmount));

        return Inertia::render('Crm/Pages/Shortages/Index', [
            'offers' => $offers,
            'counters' => $this->counters($request, $scope),
            'filters' => [
                'status' => $status,
                'scope' => $scope->value,
            ],
            'canSeeAll' => $this->seesDepartment($request),
        ]);
    }

    public function show(Request $request, int $offer): InertiaResponse
    {
        $actor = $this->crmActor($request);

        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)
            ->with([
                'order.items.product.media',
                'order.items.productDefect',
                'user:id,name,email,phone,personal_manager_id,created_at',
                'manager:id,name',
                'items' => fn ($q) => $q->with(['product.media', 'productDefect.product']),
            ])
            ->findOrFail($offer);

        return Inertia::render('Crm/Pages/Shortages/Show', [
            'offer' => $this->cardPayload($model, $actor),
            'draft' => $this->drafts->draftFor($model),
            'recipientOptions' => $this->drafts->recipientOptions($model, $actor),
            'outboundEnabled' => (bool) config('notifications.mail.features.crm_outbound'),
        ]);
    }

    /**
     * Поиск товара для ручного кандидата — свой роут под правом раздела:
     * crm.products.search закрыт правом аналитики, которого у рядового
     * менеджера может не быть.
     *
     * Контракт ответа — плоский массив, как у admin.products.search:
     * ProductSelector рендерит ответ напрямую через suggestions.map().
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', (string) $request->input('q', '')));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::search($query)->take(20)->get()->load(['media', 'brand:id,name', 'barcodes']);

        return response()->json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'brand_name' => $product->brand?->name,
            'image_url' => $product->getFirstMediaUrl('images', 'thumb') ?: null,
            'price' => (float) $product->base_price,
            'barcode' => $product->barcode,
            'barcodes' => $product->barcodes->pluck('barcode')->all(),
        ])->values());
    }

    /**
     * Перегенерация черновика письма после правки состава кандидатов —
     * карточка обновляет текст без перезагрузки страницы.
     */
    public function refreshDraft(Request $request, int $offer): JsonResponse
    {
        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)->findOrFail($offer);

        abort_unless($model->isOpen(), 422, 'Подборка уже закрыта.');

        return response()->json($this->drafts->refreshDraft($model));
    }

    /**
     * Ручной кандидат: добавляется в подборку и создаёт связь `manual`
     * в справочнике — выбор менеджера это обучающая выборка.
     */
    public function storeCandidate(Request $request, int $offer): JsonResponse
    {
        $actor = $this->crmActor($request);
        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)->findOrFail($offer);

        abort_unless($model->isOpen(), 422, 'Подборка уже закрыта.');

        $data = $request->validate([
            'source_order_item_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var OrderItem $sourceItem */
        $sourceItem = OrderItem::query()
            ->where('order_id', $model->order_id)
            ->where('cancelled', true)
            ->findOrFail($data['source_order_item_id']);

        $product = Product::withoutGlobalScopes()->findOrFail($data['product_id']);

        $client = $model->user;
        $price = $this->prices->getUserPrice($product, $client);
        $available = $this->stock->getAvailableStock($product, $client);

        $item = SubstitutionOfferItem::query()->firstOrCreate(
            [
                'offer_id' => $model->id,
                'source_order_item_id' => $sourceItem->id,
                'product_id' => $product->id,
                'product_defect_id' => null,
            ],
            [
                'kind' => CandidateKind::MANUAL,
                'reason' => ($data['reason'] ?? null) ?: 'Подобран персональным менеджером',
                'price_snapshot' => $price,
                'suggested_quantity' => max(1, min($sourceItem->quantity, max($available, 1))),
            ],
        );

        // Менеджер вернул кандидата, которого сам же снимал — галочка вернулась.
        if ($item->removed_by_manager_at !== null) {
            $item->update(['removed_by_manager_at' => null]);
        }

        if ($sourceItem->product_id !== null && $sourceItem->product_id !== $product->id) {
            ProductSubstitution::query()->firstOrCreate(
                [
                    'from_product_id' => $sourceItem->product_id,
                    'to_product_id' => $product->id,
                ],
                [
                    'kind' => LinkKind::EQUIVALENT,
                    'source' => LinkSource::MANUAL,
                    'score' => 80,
                    'note' => $data['reason'] ?? null,
                    'confirmed_at' => now(),
                    'created_by' => $actor->id,
                ],
            );
        }

        return response()->json(['id' => $item->id]);
    }

    /**
     * Снятая галочка — негативный сигнал для пары товаров.
     */
    public function removeCandidate(Request $request, int $offer, int $item): JsonResponse
    {
        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)->findOrFail($offer);

        /** @var SubstitutionOfferItem $candidate */
        $candidate = $model->items()->findOrFail($item);

        if ($candidate->removed_by_manager_at === null) {
            $candidate->update(['removed_by_manager_at' => now()]);
            SubstitutionEvent::create([
                'offer_item_id' => $candidate->id,
                'event' => SignalEvent::MANAGER_REMOVED,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function restoreCandidate(Request $request, int $offer, int $item): JsonResponse
    {
        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)->findOrFail($offer);

        $model->items()->findOrFail($item)->update(['removed_by_manager_at' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Четыре исхода одной кнопкой: отправить / подождать / позвонить / закрыть.
     * Любой исход закрывает задачу «Недобор по заказу…».
     */
    public function outcome(Request $request, int $offer): JsonResponse
    {
        $actor = $this->crmActor($request);
        $model = $this->visibleOffers($request, CrmScope::DEPARTMENT)->findOrFail($offer);

        $data = $request->validate([
            'type' => ['required', 'in:send,wait,call,dismiss'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'to' => ['nullable', 'array', 'max:10'],
            'to.*' => ['string', 'email'],
            'reason' => ['required_if:type,dismiss', 'nullable', 'string', 'max:255'],
        ], [
            'to.*.email' => 'Адрес «:input» не похож на email.',
        ]);

        abort_unless($model->isOpen(), 422, 'Подборка уже закрыта.');

        switch ($data['type']) {
            case 'send':
            case 'wait':
                $result = $this->drafts->send(
                    $model,
                    $actor,
                    (string) ($data['subject'] ?? ''),
                    (string) ($data['body_html'] ?? ''),
                    array_values($data['to'] ?? []),
                );

                if (! $result['ok']) {
                    return response()->json(['message' => $result['message']], 422);
                }

                $model->update(['sent_at' => now()]);
                break;

            case 'call':
                // Итог звонка менеджер фиксирует через CrmCall (диалог звонка);
                // здесь исход только снимает задачу — подборка остаётся открытой.
                break;

            case 'dismiss':
                $model->update([
                    'status' => OfferStatus::DISMISSED,
                    'dismiss_reason' => $data['reason'],
                ]);
                break;
        }

        $this->closeShortageTask($model);

        return response()->json(['ok' => true, 'status' => $model->refresh()->status->value]);
    }

    /**
     * Срез недоборов в CRM-аналитике: скорость реакции, покрытие, конверсия,
     * спасённая сумма, слои и удержание — ежемесячный взгляд руководителя.
     */
    public function analytics(Request $request, \App\Services\Substitution\ShortageAnalyticsService $analytics): InertiaResponse
    {
        $actor = $this->crmActor($request);

        $from = \Carbon\CarbonImmutable::parse((string) $request->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = \Carbon\CarbonImmutable::parse((string) $request->input('to', now()->toDateString()))->endOfDay();

        // Скоуп клиентов — как в остальной CRM-аналитике.
        $userIds = User::query()->visibleInCrm($actor)->pluck('id')->all();

        return Inertia::render('Crm/Pages/Shortages/Analytics', [
            'metrics' => $analytics->metrics($from, $to, $userIds),
            'layers' => $analytics->layerAcceptance($from, $to, $userIds),
            'managers' => $this->seesManagerBreakdown($request)
                ? $analytics->byManager($from, $to, $userIds)
                : [],
            'retention' => $analytics->retention($from, $to, $userIds),
            'repeated' => $analytics->repeatedShortages(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * Вкладка «Связи»: очередь подтверждений learned/ai-связей справочника.
     * Еженедельная 15-минутная рутина менеджера.
     */
    public function links(Request $request): InertiaResponse
    {
        $links = ProductSubstitution::query()
            ->awaitingReview()
            ->with(['fromProduct.media', 'toProduct.media', 'author:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (ProductSubstitution $link) {
                // Негативные сигналы по паре — аргумент против подтверждения.
                $negativeSignals = SubstitutionEvent::query()
                    ->whereIn('event', [SignalEvent::MANAGER_REMOVED, SignalEvent::CLIENT_SKIPPED])
                    ->whereHas('offerItem', function ($q) use ($link) {
                        $q->where('product_id', $link->to_product_id)
                            ->whereHas('sourceOrderItem', fn ($s) => $s->where('product_id', $link->from_product_id));
                    })
                    ->count();

                return [
                    'id' => $link->id,
                    'from' => $this->linkProductPayload($link->fromProduct),
                    'to' => $this->linkProductPayload($link->toProduct),
                    'kind' => $link->kind->value,
                    'kind_label' => $link->kind->label(),
                    'source' => $link->source->value,
                    'source_label' => $link->source->label(),
                    'score' => $link->score,
                    'note' => $link->note,
                    'negative_signals' => $negativeSignals,
                    'created_at' => $link->created_at?->format('d.m.Y'),
                ];
            });

        return Inertia::render('Crm/Pages/Shortages/Links', [
            'links' => $links,
            'awaitingCount' => ProductSubstitution::awaitingReview()->count(),
        ]);
    }

    public function approveLink(Request $request, int $link): JsonResponse
    {
        $model = ProductSubstitution::query()->awaitingReview()->findOrFail($link);

        $model->update(['confirmed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Отклонённая пара больше не предлагается автоподбором
     * и не создаётся заново — защита от зацикливания.
     */
    public function rejectLink(Request $request, int $link): JsonResponse
    {
        $model = ProductSubstitution::query()->awaitingReview()->findOrFail($link);

        $model->update(['rejected_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function linkProductPayload(?Product $product): ?array
    {
        if ($product === null) {
            return null;
        }

        $client = null; // очередь смотрит менеджер: цены базовые, остатки общие

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'image_url' => $product->getFirstMediaUrl('images', 'thumb') ?: null,
            'price' => (float) $product->base_price,
            'available' => $this->stock->getAvailableStock($product, $client),
        ];
    }

    /**
     * Скоуп видимости: менеджер — свои подборки (снимок менеджера на оффере
     * или текущее закрепление клиента), отдел — все.
     *
     * @return Builder<SubstitutionOffer>
     */
    private function visibleOffers(Request $request, CrmScope $scope): Builder
    {
        $actor = $this->crmActor($request);
        $query = SubstitutionOffer::query();

        $restrictToMine = ! $this->seesDepartment($request)
            || ($scope->isMine() && $actor->managerProfile !== null);

        if ($restrictToMine) {
            $managerId = $actor->managerProfile?->id;

            $query->where(function (Builder $q) use ($actor, $managerId) {
                $q->where('manager_user_id', $actor->id);

                if ($managerId !== null) {
                    $q->orWhereHas('user', fn (Builder $u) => $u->where('personal_manager_id', $managerId));
                }
            });
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'open' => $query->open(),
            'sent' => $query->open()->whereNotNull('sent_at')->whereNull('viewed_at'),
            'viewed' => $query->where('status', OfferStatus::VIEWED),
            'confirmed' => $query->where('status', OfferStatus::CONFIRMED),
            'expired' => $query->where('status', OfferStatus::EXPIRED),
            'dismissed' => $query->where('status', OfferStatus::DISMISSED),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function counters(Request $request, CrmScope $scope): array
    {
        $base = fn () => $this->visibleOffers($request, $scope);

        return [
            'pending' => (clone $base())->where('status', OfferStatus::PENDING)->count(),
            'sent_not_viewed' => (clone $base())->open()->whereNotNull('sent_at')->whereNull('viewed_at')->count(),
            'viewed_not_confirmed' => (clone $base())->where('status', OfferStatus::VIEWED)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueRow(SubstitutionOffer $offer, float $urgentAmount): array
    {
        $amount = (float) ($offer->cancelled_amount ?? 0);

        return [
            'id' => $offer->id,
            'order_number' => $offer->order?->erp_number ?: $offer->order?->number,
            'client' => $offer->user?->name ?? '—',
            'manager' => $offer->manager?->name ?? 'не назначен',
            'candidates_count' => (int) $offer->candidates_count,
            'cancelled_amount' => $amount,
            'urgent' => $amount > $urgentAmount,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'status_color' => $offer->status->color(),
            'sent_at' => $offer->sent_at?->format('d.m.Y H:i'),
            'viewed_at' => $offer->viewed_at?->format('d.m.Y H:i'),
            'expires_at' => $offer->expires_at?->format('d.m.Y'),
            'created_at' => $offer->created_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Карточка: всё решение на одном экране.
     *
     * @return array<string, mixed>
     */
    private function cardPayload(SubstitutionOffer $offer, User $actor): array
    {
        $order = $offer->order;
        $client = $offer->user;

        $cancelledItems = $order->items->where('cancelled', true)->values();
        $candidatesBySource = $offer->items->groupBy('source_order_item_id');

        // Контекст клиента: оборот по заказам за год и история недоборов.
        $turnover = $client
            ? (float) Order::query()
                ->where('user_id', $client->id)
                ->where(fn ($q) => $q->where('erp_created_at', '>=', now()->subYear())
                    ->orWhere(fn ($qq) => $qq->whereNull('erp_created_at')->where('created_at', '>=', now()->subYear())))
                ->sum('total_amount')
            : 0.0;

        $previousOffers = $client
            ? SubstitutionOffer::query()
                ->where('user_id', $client->id)
                ->where('id', '<', $offer->id)
                ->where('created_at', '>=', now()->subQuarter())
                ->count()
            : 0;

        return [
            'id' => $offer->id,
            'uuid' => $offer->uuid,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'status_color' => $offer->status->color(),
            'is_open' => $offer->isOpen(),
            'dismiss_reason' => $offer->dismiss_reason,
            'sent_at' => $offer->sent_at?->format('d.m.Y H:i'),
            'viewed_at' => $offer->viewed_at?->format('d.m.Y H:i'),
            'confirmed_at' => $offer->confirmed_at?->format('d.m.Y H:i'),
            'expires_at' => $offer->expires_at?->format('d.m.Y H:i'),
            'created_at' => $offer->created_at?->format('d.m.Y H:i'),
            'result_order_ids' => $offer->result_order_ids,
            'client_page_url' => \Illuminate\Support\Facades\Route::has('substitutions.show')
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'substitutions.show',
                    $offer->expires_at ?? now()->addDays(7),
                    ['offer' => $offer->uuid],
                )
                : null,
            'order' => [
                'id' => $order->id,
                'number' => $order->erp_number ?: $order->number,
                'created_at' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
                'delivery_method' => $order->delivery_method?->label(),
                'delivery_address' => $order->delivery_address,
            ],
            'client' => [
                'id' => $client?->id,
                'name' => $client?->name ?? '—',
                'email' => $client?->email,
                'phone' => $client?->phone,
                'turnover_year' => $turnover,
                'previous_shortages_quarter' => $previousOffers,
                'manager' => $offer->manager?->name ?? 'не назначен',
            ],
            'lines' => $cancelledItems->map(function (OrderItem $line) use ($candidatesBySource, $client) {
                $preorderStock = $line->product
                    ? $this->stock->getPreorderStock($line->product, $client)
                    : 0;

                return [
                    'id' => $line->id,
                    'name' => $line->name,
                    'sku' => $line->product?->sku,
                    'product_id' => $line->product_id,
                    'image_url' => $line->product?->getFirstMediaUrl('images', 'thumb') ?: null,
                    'quantity' => $line->quantity,
                    'subtotal' => (float) $line->subtotal,
                    'final_price' => (float) $line->final_price,
                    // Прогноз возврата: остаток на preorder-складах региона клиента.
                    'preorder_stock' => $preorderStock,
                    'candidates' => ($candidatesBySource[$line->id] ?? collect())
                        ->map(fn (SubstitutionOfferItem $item) => $this->candidatePayload($item, $client))
                        ->values(),
                ];
            })->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePayload(SubstitutionOfferItem $item, ?User $client): array
    {
        $product = $item->product ?? $item->productDefect?->product;

        $available = $item->product_defect_id !== null
            ? (int) $item->productDefect?->quantity
            : ($product ? $this->stock->getAvailableStock($product, $client) : 0);

        return [
            'id' => $item->id,
            'is_defect' => $item->product_defect_id !== null,
            'product_id' => $item->product_id,
            'name' => $product?->name ?? '—',
            'sku' => $product?->sku,
            'image_url' => $product?->getFirstMediaUrl('images', 'thumb') ?: null,
            'kind' => $item->kind->value,
            'kind_label' => $item->kind->label(),
            'reason' => $item->reason,
            'defect_description' => $item->productDefect?->defect_description,
            'price' => (float) $item->price_snapshot,
            'available' => $available,
            'suggested_quantity' => $item->suggested_quantity,
            'removed' => $item->removed_by_manager_at !== null,
            'chosen' => $item->chosen,
            'chosen_quantity' => $item->chosen_quantity,
        ];
    }

    private function closeShortageTask(SubstitutionOffer $offer): void
    {
        CrmTask::query()
            ->where('related_type', (new Order)->getMorphClass())
            ->where('related_id', $offer->order_id)
            ->whereIn('status', [TaskStatus::OPEN, TaskStatus::IN_PROGRESS])
            ->where('title', 'like', 'Недобор по заказу%')
            ->get()
            ->each(fn (CrmTask $task) => $task->update(['status' => TaskStatus::DONE]));
    }
}
