<?php

namespace App\Services\Returns;

use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\User;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\QueryRouter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Выборка возвратов клиента с фильтрами и поиском — общая для кабинета и API v1.
 *
 * Поиск повторяет сценарии кабинета (docs/cabinet-search-scenarios.md, C-2.x):
 * номер и uuid, в т. ч. без дефисов; номер исходной реализации; товар и бренд
 * в составе; штрихкод; текст комментария; fuzzy через Meilisearch за флагом.
 */
class ClientReturnQuery
{
    public const SORT_FIELDS = ['id', 'total_amount', 'status', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters  search, status (скаляр|список), reason (скаляр|список),
     *                                         date_from, date_to, amount_from, amount_to
     * @return Builder<ProductReturn>
     */
    public function builder(User $user, array $filters = []): Builder
    {
        $query = ProductReturn::query()
            ->where('user_id', $user->id)
            ->with(['items']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $this->applySearch($query, $search, $user);
        }

        $statuses = $this->list($filters['status'] ?? null);

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $reasons = $this->list($filters['reason'] ?? null);

        if ($reasons !== []) {
            $query->whereHas('items', fn ($q) => $q->whereIn('reason', $reasons));
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

        return $query;
    }

    /**
     * Сортировка по разрешённому полю; неизвестное поле игнорируется.
     *
     * @param  Builder<ProductReturn>  $query
     */
    public function applySort(Builder $query, string $sortBy, string $sortOrder): void
    {
        if (in_array($sortBy, self::SORT_FIELDS, true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }
    }

    /**
     * Скаляр или список → список непустых значений.
     *
     * @return list<string>
     */
    public function list(mixed $input): array
    {
        if (is_array($input)) {
            return array_values(array_filter(array_map(fn ($v) => (string) $v, $input), fn ($v) => $v !== ''));
        }

        return $input !== null && $input !== '' ? [(string) $input] : [];
    }

    /**
     * @param  Builder<ProductReturn>  $query
     */
    private function applySearch(Builder $query, string $search, User $user): void
    {
        $normalized = preg_replace('/[\s\-]+/u', '', $search);
        $queryType = QueryRouter::classify($search);

        $fuzzyReturnIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
            ? FuzzyDocumentMatcher::findDocumentIds($search, ReturnItem::class, 'return_id', 'return', $user->id)
            : [];

        $query->where(function ($q) use ($search, $normalized, $queryType, $fuzzyReturnIds) {
            $q->where('uuid', 'like', "%{$search}%")
                ->orWhere('erp_number', 'like', "%{$search}%");

            if (ctype_digit($search)) {
                $q->orWhere('id', (int) $search);
            }

            if ($normalized !== '') {
                $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
            }

            $q->orWhereHas('items.shipmentItem.shipment', function ($s) use ($search, $normalized) {
                $s->where('number', 'like', "%{$search}%")
                    ->orWhere('erp_number', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%");

                if ($normalized !== '') {
                    $s->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                    $s->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }
            });

            $q->orWhereHas('items.shipmentItem.product', function ($p) use ($search) {
                $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });

            $q->orWhereHas('items.shipmentItem.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

            if ($queryType === QueryRouter::TYPE_BARCODE) {
                $q->orWhereHas('items.shipmentItem.product.barcodes', fn ($b) => $b->where('barcode', $search));
            }

            $q->orWhereHas('items', fn ($i) => $i->where('reason_comment', 'like', "%{$search}%"));

            if ($fuzzyReturnIds !== []) {
                $q->orWhereIn('id', $fuzzyReturnIds);
            }
        });
    }
}
