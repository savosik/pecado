<?php

namespace App\Services\Shipment;

use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Support\Search\FuzzyDocumentMatcher;
use App\Support\Search\QueryRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Выборка реализаций клиента: фильтры и сортировка.
 *
 * Общий источник для кабинета (список, экспорт), legacy `/api/client-api` и
 * API v1. Реализации внутренних юрлиц («Реклама» с образцами) клиенту не
 * показываем ни в одном канале: строка «не оплачена» на 0,58 ₽ читается как долг.
 *
 * Фильтры (все необязательные): `search` (кабинет), `status` (скаляр или список),
 * `payment_status` (список, действует только при открытых финансах),
 * `company_id`, `inn` (ИНН контрагента), `order_uuid` (реализации по заказу),
 * `number` (часть номера, дефисы и пробелы не важны), `brand_ids`,
 * `date_from`/`date_to` (по дате отгрузки), `updated_since`,
 * `amount_from`/`amount_to`, `sort_by`/`sort_order`.
 */
class ClientShipmentQuery
{
    /** Поля, по которым можно сортировать. */
    public const SORTABLE = ['id', 'date', 'total_amount', 'status'];

    /**
     * @param  array<string, mixed>  $filters
     * @param  bool  $finance  открыты ли клиенту денежные данные: без них фильтр по
     *                         статусу оплаты стал бы обходным путём к скрытым цифрам долга
     * @return Builder<Shipment>
     */
    public function builder(User $user, array $filters, bool $finance = false): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Shipment::query()
            ->where('user_id', $user->id)
            ->withoutInternalOrganizations();

        if ($search !== '') {
            $this->applySearch($query, $user, $search);
        }

        $statuses = self::values($filters['status'] ?? null);

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($finance) {
            $paymentStatuses = self::paymentStatuses($filters['payment_status'] ?? null);

            if ($paymentStatuses !== []) {
                $query->whereIn('payment_status', $paymentStatuses);
            }
        }

        if (! empty($filters['order_uuid'])) {
            $query->whereHas('items', fn ($q) => $q->where('order_uuid', $filters['order_uuid']));
        }

        $brandIds = array_values(array_filter(
            array_map('intval', (array) ($filters['brand_ids'] ?? [])),
            fn (int $id) => $id > 0,
        ));

        if ($brandIds !== []) {
            $query->whereHas('items.product', fn ($p) => $p->whereIn('brand_id', $brandIds));
        }

        // Контрагент: у клиента их может быть несколько юрлиц, и бухгалтерия
        // сверяет отгрузки по каждому отдельно.
        $companyId = (int) ($filters['company_id'] ?? 0);

        if ($companyId > 0) {
            $query->where('company_id', $companyId);
        }

        if (! empty($filters['inn'])) {
            $inn = (string) $filters['inn'];
            $query->where(fn ($q) => $q->where('tax_id', $inn)
                ->orWhereHas('company', fn ($c) => $c->where('tax_id', $inn)));
        }

        // Номер ищем и как есть, и в нормализованном виде: 29УТ-003413 ≡ 29УТ003413.
        $number = trim((string) ($filters['number'] ?? ''));

        if ($number !== '') {
            $normalized = preg_replace('/[\s\-]+/u', '', $number);
            $query->where(function ($q) use ($number, $normalized) {
                $q->where('number', 'like', "%{$number}%")
                    ->orWhere('erp_number', 'like', "%{$number}%");

                if ($normalized !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"])
                        ->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                }
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        // Инкрементальная выгрузка: «что изменилось с прошлой синхронизации».
        if (! empty($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        if (! empty($filters['amount_from'])) {
            $query->where('total_amount', '>=', $filters['amount_from']);
        }

        if (! empty($filters['amount_to'])) {
            $query->where('total_amount', '<=', $filters['amount_to']);
        }

        $this->applySort(
            $query,
            (string) ($filters['sort_by'] ?? 'id'),
            (string) ($filters['sort_order'] ?? 'desc'),
        );

        return $query;
    }

    /**
     * Реализация клиента по id, uuid или номеру (1С либо сайта) — порядок
     * проверки как в legacy `/api/client-api`. Внутренние юрлица скрыты.
     */
    public function find(User $user, string $identifier): ?Shipment
    {
        $identifier = trim($identifier);
        $base = fn (): Builder => Shipment::query()->where('user_id', $user->id)->withoutInternalOrganizations();

        return (ctype_digit($identifier) ? $base()->whereKey((int) $identifier)->first() : null)
            ?? $base()->where('uuid', $identifier)->first()
            ?? $base()->where('erp_number', $identifier)->first()
            ?? $base()->where('number', $identifier)->first();
    }

    /**
     * Связи для строк API: контрагент, продавец и — по запросу — состав.
     *
     * Товар в составе берётся без витринных скоупов: скрытый на сайте товар
     * всё равно отгружен, и без этого его строка приехала бы без артикулов.
     *
     * @return array<int|string, mixed>
     */
    public function eagerLoads(bool $withItems): array
    {
        $loads = [
            'company:id,name,legal_name,tax_id',
            'organization:id,name,legal_name,tax_id,is_stub,is_settlements_excluded',
        ];

        if ($withItems) {
            $loads += self::itemsEagerLoad();
        }

        return $loads;
    }

    /**
     * Состав реализации с товарами (форма legacy `/api/client-api`).
     *
     * @return array<string, \Closure>
     */
    public static function itemsEagerLoad(): array
    {
        return [
            'items.product' => fn ($q) => $q->withoutGlobalScopes()
                ->select('id', 'external_id', 'code', 'sku', 'barcode', 'name'),
        ];
    }

    /**
     * Значения фильтра: скаляр или список, пустые отброшены.
     *
     * @return list<string>
     */
    public static function values(mixed $input): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => (string) $v, (array) ($input ?? [])),
            fn (string $v) => $v !== '',
        ));
    }

    /**
     * Статусы оплаты фильтра — только известные модели.
     *
     * @return list<string>
     */
    public static function paymentStatuses(mixed $input): array
    {
        return array_values(array_intersect(self::values($input), Shipment::PAYMENT_STATUSES));
    }

    /**
     * @param  Builder<Shipment>  $query
     */
    private function applySearch(Builder $query, User $user, string $search): void
    {
        $normalized = preg_replace('/[\s\-]+/u', '', $search);
        $queryType = QueryRouter::classify($search);

        $fuzzyShipmentIds = FuzzyDocumentMatcher::isApplicable($search, $queryType)
            ? FuzzyDocumentMatcher::findDocumentIds(
                $search,
                ShipmentItem::class,
                'shipment_id',
                'shipment',
                $user->id,
            )
            : [];

        $query->where(function ($q) use ($search, $normalized, $queryType, $fuzzyShipmentIds) {
            $q->where('uuid', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%")
                ->orWhere('erp_number', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%");

            // Нормализованная форма номера: 29УТ-003413 ≡ 29УТ003413 (C-4.1).
            if ($normalized !== '') {
                $q->orWhereRaw("REPLACE(REPLACE(number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
                $q->orWhereRaw("REPLACE(REPLACE(erp_number, '-', ''), ' ', '') LIKE ?", ["%{$normalized}%"]);
            }

            // Состав: name/sku/code товара.
            $q->orWhereHas('items.product', function ($p) use ($search) {
                $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });

            // Бренд в составе (C-4.2).
            $q->orWhereHas('items.product.brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));

            // Штрихкод (C-4.3, точное совпадение).
            if ($queryType === QueryRouter::TYPE_BARCODE) {
                $q->orWhereHas('items.product.barcodes', fn ($b) => $b->where('barcode', $search));
            }

            // Fuzzy через Meilisearch (PR 4.2, флаг CABINET_SEARCH_FUZZY_DOCUMENTS).
            if (! empty($fuzzyShipmentIds)) {
                $q->orWhereIn('id', $fuzzyShipmentIds);
            }
        });
    }

    /**
     * @param  Builder<Shipment>  $query
     */
    private function applySort(Builder $query, string $sortBy, string $sortOrder): void
    {
        if (! in_array($sortBy, self::SORTABLE, true)) {
            return;
        }

        $query->orderBy($sortBy, $sortOrder);

        // Дата хранится без времени — без тай-брейка по id страницы курсора
        // и соседние документы одного дня перемешивались бы.
        if ($sortBy !== 'id') {
            $query->orderBy('id', $sortOrder === 'asc' ? 'asc' : 'desc');
        }
    }
}
