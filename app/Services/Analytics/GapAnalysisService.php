<?php

namespace App\Services\Analytics;

use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap-анализ (поиск возможностей для кросс-продаж): партнёры/контрагенты в
 * скоупе, у которых НЕТ покупок заданного бренда / категории / товара.
 *
 * Логика — разность множеств:
 *   (кандидаты) − (те, кто покупал X в окне проверки)
 * с опциональным сужением «при этом покупали Y».
 *
 * Изоляция данных обеспечивается скоупом клиентов из AnalyticsContext:
 * все запросы ограничены shipments.user_id IN ctx->userIds, поэтому чужой
 * партнёр/контрагент физически не попадёт ни в кандидатов, ни в метрики.
 */
class GapAnalysisService
{
    /** Нижняя граница окна «за всё время». */
    private const EPOCH = '2000-01-01 00:00:00';

    /**
     * @param  array{
     *   subject: string,
     *   exclude_dimension: string,
     *   exclude_value: int,
     *   exclude_window: string,
     *   exclude_months: int,
     *   include_dimension: ?string,
     *   include_value: ?int,
     *   include_dormant: bool
     * }  $params
     * @return array<string, mixed>
     */
    public function analyze(
        AnalyticsContext $ctx,
        array $params,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
    ): array {
        $subject = $params['subject'];
        $empty = [
            'subject' => $subject,
            'currency' => ['code' => 'RUB', 'symbol' => '₽'],
            'rows' => [],
            'summary' => ['base' => 0, 'bought' => 0, 'gap' => 0],
        ];

        // Без выбранного значения условия «не покупали» показывать нечего.
        if ($ctx->isEmpty() || $params['exclude_value'] <= 0) {
            return $empty;
        }

        $now = CarbonImmutable::now();

        // 1. База кандидатов: активные за период (+ опционально спящий пул).
        $candidates = $this->activeSubjectKeys($ctx, $subject, $periodFrom, $periodTo);
        if ($params['include_dormant']) {
            $candidates = array_values(array_unique(array_merge(
                $candidates,
                $this->dormantPoolKeys($ctx, $subject),
            )));
        }

        // 2. Сужение «при этом покупали Y» (за всё время).
        if ($params['include_dimension'] !== null && $params['include_value'] > 0) {
            $boughtInclude = $this->boughtSubjectKeys(
                $ctx, $subject, $params['include_dimension'], $params['include_value'],
                CarbonImmutable::parse(self::EPOCH), $now,
            );
            $candidates = array_values(array_intersect($candidates, $boughtInclude));
        }

        // 3. Множество тех, кто покупал X в окне проверки.
        [$exFrom, $exTo] = match ($params['exclude_window']) {
            'period' => [$periodFrom, $periodTo],
            'months' => [$now->subMonthsNoOverflow($params['exclude_months']), $now],
            default => [CarbonImmutable::parse(self::EPOCH), $now],
        };
        $boughtExclude = $this->boughtSubjectKeys(
            $ctx, $subject, $params['exclude_dimension'], $params['exclude_value'], $exFrom, $exTo,
        );

        // 4. Разность: кандидаты без покупок X.
        $gapKeys = array_values(array_diff($candidates, $boughtExclude));

        $rows = $gapKeys === []
            ? []
            : $this->enrichRows($ctx, $subject, $gapKeys, $periodFrom, $periodTo);

        return [
            'subject' => $subject,
            'currency' => ['code' => 'RUB', 'symbol' => '₽'],
            'rows' => $rows,
            'summary' => [
                'base' => count($candidates),
                'bought' => count(array_intersect($candidates, $boughtExclude)),
                'gap' => count($gapKeys),
            ],
        ];
    }

    /**
     * Ключи субъектов, у которых была хоть одна отгрузка в периоде (активная база).
     *
     * @return array<int, string>
     */
    private function activeSubjectKeys(AnalyticsContext $ctx, string $subject, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->boughtSubjectKeys($ctx, $subject, null, null, $from, $to);
    }

    /**
     * Весь закреплённый пул (в т.ч. спящие без отгрузок):
     *  - партнёры — все клиенты скоупа;
     *  - контрагенты — все их юрлица.
     *
     * @return array<int, string>
     */
    private function dormantPoolKeys(AnalyticsContext $ctx, string $subject): array
    {
        if ($subject === 'partner') {
            return array_map(fn ($id) => (string) (int) $id, $ctx->userIds);
        }

        return DB::table('companies')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $ctx->userIds)
            ->pluck('id')
            ->map(fn ($id) => 'id:'.(int) $id)
            ->all();
    }

    /**
     * Ключи субъектов, купивших измерение=значение в окне [from, to].
     * dimension=null → любая покупка (для активной базы).
     *
     * @return array<int, string>
     */
    private function boughtSubjectKeys(
        AnalyticsContext $ctx,
        string $subject,
        ?string $dimension,
        ?int $value,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $query = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->where($ctx->dateColumn, '>=', $from->format('Y-m-d H:i:s'))
            ->where($ctx->dateColumn, '<=', $to->format('Y-m-d H:i:s'));

        $this->applyDimension($query, $dimension, $value);

        if ($subject === 'partner') {
            return $query->distinct()
                ->pluck('shipments.user_id')
                ->filter()
                ->map(fn ($id) => (string) (int) $id)
                ->values()
                ->all();
        }

        return collect($query->select('shipments.company_id', 'shipments.tax_id')->distinct()->get())
            ->map(fn ($r) => $this->contractorKey($r->company_id, $r->tax_id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Накладывает фильтр по измерению (бренд/категория/товар) на запрос покупок.
     * Категория раскрывается до поддерева, чтобы покупка подкатегории считалась
     * покупкой родителя.
     */
    private function applyDimension(\Illuminate\Database\Query\Builder $query, ?string $dimension, ?int $value): void
    {
        if ($dimension === null || $value === null) {
            return;
        }

        match ($dimension) {
            'brand' => $query
                ->join('products as pf', 'pf.id', '=', 'shipment_items.product_id')
                ->where('pf.brand_id', $value),
            'category' => $query
                ->join('products as pf', 'pf.id', '=', 'shipment_items.product_id')
                ->whereIn('pf.category_id', $this->categorySubtreeIds($value)),
            'product' => $query->where('shipment_items.product_id', $value),
            default => null,
        };
    }

    /**
     * id категории вместе со всеми потомками (nested set).
     *
     * @return array<int, int>
     */
    private function categorySubtreeIds(int $categoryId): array
    {
        $ids = Category::whereDescendantOf($categoryId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids[] = $categoryId;

        return array_values(array_unique($ids));
    }

    /**
     * Достраивает строки результата: метрики за период, последняя покупка,
     * наименование и менеджер. Отсортировано по обороту за период ↓.
     *
     * @param  array<int, string>  $gapKeys
     * @return array<int, array<string, mixed>>
     */
    private function enrichRows(AnalyticsContext $ctx, string $subject, array $gapKeys, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $gapSet = array_flip($gapKeys);
        $period = $this->periodMetrics($ctx, $subject, $from, $to);
        $lastPurchase = $this->lastPurchaseMap($ctx, $subject);
        $meta = $this->subjectMeta($subject, $gapKeys);

        $rows = [];
        foreach ($gapKeys as $key) {
            $p = $period[$key] ?? ['amount' => 0.0, 'shipments' => 0, 'qty' => 0];
            $m = $meta[$key] ?? ['id' => null, 'label' => $key, 'manager_id' => null, 'manager' => null];

            $rows[] = [
                'key' => $key,
                'id' => $m['id'],
                'label' => $m['label'],
                'manager_id' => $m['manager_id'],
                'manager' => $m['manager'],
                'amount' => round((float) $p['amount'], 2),
                'shipments_count' => (int) $p['shipments'],
                'qty' => (int) $p['qty'],
                'last_purchase_at' => $lastPurchase[$key] ?? null,
            ];
        }
        unset($gapSet);

        usort($rows, function ($a, $b) {
            if ($a['amount'] !== $b['amount']) {
                return $b['amount'] <=> $a['amount'];
            }

            return strcmp((string) $b['last_purchase_at'], (string) $a['last_purchase_at']);
        });

        return $rows;
    }

    /**
     * Оборот/поставки/штуки за период, ключ → метрики. Считаем по всему скоупу
     * и фильтруем к gap-ключам уже в enrichRows.
     *
     * @return array<string, array{amount: float, shipments: int, qty: int}>
     */
    private function periodMetrics(AnalyticsContext $ctx, string $subject, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->leftJoin('currencies', 'currencies.code', '=', 'shipments.currency_code')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->where($ctx->dateColumn, '>=', $from->format('Y-m-d H:i:s'))
            ->where($ctx->dateColumn, '<=', $to->format('Y-m-d H:i:s'));

        $amountExpr = 'COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS amount';
        $qtyExpr = 'COALESCE(SUM(shipment_items.quantity), 0) AS qty';
        $shipExpr = 'COUNT(DISTINCT shipments.id) AS shipments';

        if ($subject === 'partner') {
            $rows = $query->selectRaw("shipments.user_id AS k, {$amountExpr}, {$qtyExpr}, {$shipExpr}")
                ->groupBy('shipments.user_id')
                ->get();

            $map = [];
            foreach ($rows as $r) {
                $map[(string) (int) $r->k] = ['amount' => (float) $r->amount, 'shipments' => (int) $r->shipments, 'qty' => (int) $r->qty];
            }

            return $map;
        }

        $rows = $query->selectRaw("shipments.company_id AS cid, shipments.tax_id AS tid, {$amountExpr}, {$qtyExpr}, {$shipExpr}")
            ->groupBy('shipments.company_id', 'shipments.tax_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $key = $this->contractorKey($r->cid, $r->tid);
            if ($key === null) {
                continue;
            }
            // Один контрагент может встретиться и как company_id, и как tax-only — суммируем.
            $map[$key]['amount'] = ($map[$key]['amount'] ?? 0) + (float) $r->amount;
            $map[$key]['shipments'] = ($map[$key]['shipments'] ?? 0) + (int) $r->shipments;
            $map[$key]['qty'] = ($map[$key]['qty'] ?? 0) + (int) $r->qty;
        }

        return $map;
    }

    /**
     * Дата последней покупки за всё время, ключ → 'Y-m-d'.
     *
     * Публичный: этой же картой пользуется `App\Services\Crm\OpportunityService`,
     * чтобы посчитать давность закупки. Своя копия запроса там означала бы два
     * ответа на вопрос «когда клиент покупал в последний раз».
     *
     * @return array<string, string>
     */
    public function lastPurchaseMap(AnalyticsContext $ctx, string $subject = 'partner'): array
    {
        $query = DB::table('shipments')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $ctx->userIds);

        $dateCol = $ctx->dateColumn;

        if ($subject === 'partner') {
            $rows = $query->selectRaw("user_id AS k, MAX({$dateCol}) AS last_at")
                ->groupBy('user_id')
                ->get();

            $map = [];
            foreach ($rows as $r) {
                if ($r->last_at) {
                    $map[(string) (int) $r->k] = CarbonImmutable::parse($r->last_at)->toDateString();
                }
            }

            return $map;
        }

        $rows = $query->selectRaw("company_id AS cid, tax_id AS tid, MAX({$dateCol}) AS last_at")
            ->groupBy('company_id', 'tax_id')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $key = $this->contractorKey($r->cid, $r->tid);
            if ($key === null || ! $r->last_at) {
                continue;
            }
            $date = CarbonImmutable::parse($r->last_at)->toDateString();
            if (! isset($map[$key]) || $date > $map[$key]) {
                $map[$key] = $date;
            }
        }

        return $map;
    }

    /**
     * Наименование и менеджер для набора ключей.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{id: ?int, label: string, manager_id: ?int, manager: ?string}>
     */
    private function subjectMeta(string $subject, array $keys): array
    {
        if ($subject === 'partner') {
            $ids = array_map('intval', $keys);
            $rows = DB::table('users')
                ->leftJoin('personal_managers as pm', 'pm.id', '=', 'users.personal_manager_id')
                ->whereIn('users.id', $ids)
                ->select('users.id', 'users.email', DB::raw("COALESCE(NULLIF(users.erp_name, ''), users.name) as name"), 'pm.id as manager_id', 'pm.name as manager_name')
                ->get();

            $map = [];
            foreach ($rows as $r) {
                $map[(string) (int) $r->id] = [
                    'id' => (int) $r->id,
                    'label' => $r->name ?: ($r->email ?: ('Партнёр #'.$r->id)),
                    'manager_id' => $r->manager_id ? (int) $r->manager_id : null,
                    'manager' => $r->manager_name ?: null,
                ];
            }

            return $map;
        }

        // Контрагенты: ключи вида id:{companyId} и tax:{taxId}.
        $companyIds = [];
        $taxIds = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, 'id:')) {
                $companyIds[] = (int) substr($key, 3);
            } elseif (str_starts_with($key, 'tax:')) {
                $taxIds[] = substr($key, 4);
            }
        }

        $map = [];

        if ($companyIds !== []) {
            $rows = DB::table('companies')
                ->leftJoin('users', 'users.id', '=', 'companies.user_id')
                ->leftJoin('personal_managers as pm', 'pm.id', '=', 'users.personal_manager_id')
                ->whereIn('companies.id', $companyIds)
                ->select('companies.id', 'companies.name', 'companies.legal_name', 'companies.tax_id', 'pm.id as manager_id', 'pm.name as manager_name')
                ->get();

            foreach ($rows as $r) {
                $map['id:'.(int) $r->id] = [
                    'id' => (int) $r->id,
                    'label' => $r->name ?: ($r->legal_name ?: ($r->tax_id ? 'ИНН '.$r->tax_id : 'Контрагент #'.$r->id)),
                    'manager_id' => $r->manager_id ? (int) $r->manager_id : null,
                    'manager' => $r->manager_name ?: null,
                ];
            }
        }

        foreach ($taxIds as $tax) {
            $map['tax:'.$tax] = [
                'id' => null,
                'label' => "ИНН {$tax} (не привязано)",
                'manager_id' => null,
                'manager' => null,
            ];
        }

        return $map;
    }

    /**
     * Единый ключ контрагента: приоритет company_id, иначе ИНН.
     */
    private function contractorKey(mixed $companyId, mixed $taxId): ?string
    {
        if ($companyId) {
            return 'id:'.(int) $companyId;
        }
        if ($taxId) {
            return 'tax:'.$taxId;
        }

        return null;
    }
}
