<?php

namespace App\Services\Order;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\QueryRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Выборка заказов клиента: поиск, фильтры, сортировка.
 *
 * Единственное место, где строится список заказов для клиента — кабинет
 * (список, счётчики статусов, экспорт) и клиентское API v1 работают через
 * него. До выноса кабинет строил выборку сам в 200 строк контроллера, и API
 * повторило бы их с расхождениями в мелочах.
 *
 * Фильтры (все необязательные): `search`, `type`, `status` (скаляр или список),
 * `company_id`, `brand_ids`, `product_id`, `date_from`/`date_to` (по дате
 * оформления), `amount_from`/`amount_to`, `items_count_from`/`items_count_to`,
 * `updated_since` (изменения с момента — для инкрементальной синхронизации),
 * `sort_by`/`sort_order`.
 */
class ClientOrderQuery
{
    /** Поля, по которым можно сортировать. */
    public const SORTABLE = ['id', 'total_amount', 'status', 'erp_created_at', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @param  bool|null  $preorders  раздел: true — только предзаказы, false — всё кроме них,
     *                                null — без ограничения по разделу (API)
     * @param  bool  $applyStatusFilter  false — не применять фильтр по статусу (для
     *                                   счётчиков: выбор одного статуса не должен обнулять остальные)
     * @return Builder<Order>
     */
    public function builder(User $user, array $filters, ?bool $preorders = false, bool $applyStatusFilter = true): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['company'])
            ->when($search !== '', fn ($q) => $q->with([
                'items:id,order_id,name,brand_name_snapshot',
            ]))
            ->withCount(['items'])
            ->withShipmentsCount()
            ->addSelect([
                'original_total_amount' => OrderItem::selectRaw('COALESCE(SUM(base_price * quantity), 0)')
                    ->whereColumn('order_id', 'orders.id'),
            ]);

        if ($search !== '') {
            $this->applySearch($query, $user, $search);
        }

        // Область раздела — предустановленный фильтр по типу, а не пользовательский:
        // предзаказы живут в своём разделе кабинета и не должны считаться дважды.
        if ($preorders !== null) {
            $query->where('type', $preorders ? '=' : '!=', OrderType::PREORDER->value);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if ($applyStatusFilter) {
            $statuses = self::statuses($filters['status'] ?? null);

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id'])
                ->whereHas('company', fn ($q) => $q->where('user_id', $user->id));
        }

        $brandIds = self::brandIds($filters['brand_ids'] ?? []);

        if ($brandIds !== []) {
            $query->whereHas('items.product', fn ($p) => $p->whereIn('brand_id', $brandIds));
        }

        $productId = (int) ($filters['product_id'] ?? 0);

        if ($productId > 0) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $productId));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['amount_from'])) {
            $query->where('total_amount', '>=', $filters['amount_from']);
        }

        if (! empty($filters['amount_to'])) {
            $query->where('total_amount', '<=', $filters['amount_to']);
        }

        $itemsFrom = $filters['items_count_from'] ?? null;
        $itemsTo = $filters['items_count_to'] ?? null;

        if ($itemsFrom !== null && $itemsFrom !== '') {
            $query->whereRaw(
                '(SELECT COUNT(*) FROM order_items WHERE order_items.order_id = orders.id) >= ?',
                [(int) $itemsFrom],
            );
        }

        if ($itemsTo !== null && $itemsTo !== '') {
            $query->whereRaw(
                '(SELECT COUNT(*) FROM order_items WHERE order_items.order_id = orders.id) <= ?',
                [(int) $itemsTo],
            );
        }

        if (! empty($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        $this->applySort(
            $query,
            (string) ($filters['sort_by'] ?? 'erp_created_at'),
            (string) ($filters['sort_order'] ?? 'desc'),
        );

        return $query;
    }

    /**
     * Количество заказов по каждому статусу — по тем же условиям, что и выдача,
     * но без фильтра по статусу.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(User $user, array $filters, ?bool $preorders = false): array
    {
        // select() сбрасывает колонки и их bindings, снимая подзапросы
        // withCount/withShipmentsCount; reorder() убирает сортировку,
        // недопустимую при GROUP BY в режиме ONLY_FULL_GROUP_BY.
        return $this->builder($user, $filters, $preorders, applyStatusFilter: false)
            ->reorder()
            ->select('orders.status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('orders.status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Статусы из фильтра: скаляр или список, пустые значения отброшены.
     *
     * @return list<string>
     */
    public static function statuses(mixed $input): array
    {
        if (is_array($input)) {
            return array_values(array_filter(
                array_map(fn ($v) => (string) $v, $input),
                fn (string $v) => $v !== '',
            ));
        }

        return $input ? [(string) $input] : [];
    }

    /**
     * @return list<int>
     */
    public static function brandIds(mixed $input): array
    {
        return array_values(array_filter(
            array_map('intval', (array) $input),
            fn (int $id) => $id > 0,
        ));
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, User $user, string $search): void
    {
        $normalized = preg_replace('/[\s\-]+/u', '', $search);
        $queryType = QueryRouter::classify($search);

        $fuzzyOrderIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
            ? FuzzyDocumentMatcher::findDocumentIds(
                $search,
                OrderItem::class,
                'order_id',
                'order',
                $user->id,
            )
            : [];

        $query->where(function ($q) use ($search, $normalized, $queryType, $user, $fuzzyOrderIds) {
            $q->where('uuid', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%")
                ->orWhere('erp_number', 'like', "%{$search}%");

            if (ctype_digit($search)) {
                $q->orWhere('id', (int) $search);
            }

            if ($normalized !== '') {
                $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
            }

            $q->orWhere('comment', 'like', "%{$search}%");

            $q->orWhereHas('items.product', function ($p) use ($search) {
                $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });

            $q->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

            if ($queryType === QueryRouter::TYPE_BARCODE) {
                $q->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search));
            }

            $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                ->where('name', 'like', "%{$search}%"));

            if ($queryType === QueryRouter::TYPE_TAX_ID) {
                $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                    ->where('tax_id', $search));
            } elseif (ctype_digit($search) && strlen($search) >= 4) {
                $q->orWhereHas('company', fn ($c) => $c->where('user_id', $user->id)
                    ->where('tax_id', 'like', "{$search}%"));
            }

            if (! empty($fuzzyOrderIds)) {
                $q->orWhereIn('id', $fuzzyOrderIds);
            }
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function applySort(Builder $query, string $sortBy, string $sortOrder): void
    {
        if (! in_array($sortBy, self::SORTABLE, true)) {
            return;
        }

        $direction = $sortOrder === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'erp_created_at') {
            $query->orderByRaw("COALESCE(erp_created_at, created_at) {$direction}");
            // Документы одного оформления создаются в одну секунду, поэтому
            // без вторичной сортировки они перемешивались бы. checkout_uuid
            // держит их вместе, id — в порядке сборки (заказ → предзаказ →
            // уценка → промо → образцы), как их и создаёт OrderAssembler.
            $query->orderByRaw("checkout_uuid {$direction}")->orderBy('id');

            return;
        }

        if ($sortBy === 'created_at') {
            // Курсорной пагинации нужны только колонки (без raw) и тай-брейк.
            $query->orderBy('created_at', $direction)->orderBy('id', $direction);

            return;
        }

        $query->orderBy($sortBy, $sortOrder);
    }
}
