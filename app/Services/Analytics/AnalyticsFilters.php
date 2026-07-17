<?php

namespace App\Services\Analytics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Иммутабельный набор фильтров для дашборда аналитики кабинета.
 * Все ID валидируются по принадлежности пользователю.
 */
class AnalyticsFilters
{
    /**
     * @param  array<int, int>  $companyIds
     * @param  array<int, int>  $brandIds
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $productIds
     */
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly array $companyIds = [],
        public readonly array $brandIds = [],
        public readonly array $categoryIds = [],
        public readonly ?string $sku = null,
        public readonly array $productIds = [],
    ) {}

    /**
     * Фильтры кабинета: company_ids валидируются по принадлежности пользователю.
     */
    public static function fromRequest(Request $request, User $user): self
    {
        [$dateFrom, $dateTo] = self::resolveRange($request);
        $userCompanyIds = $user->companies()->pluck('id')->all();

        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            companyIds: self::intersectIds($request->input('company_ids', []), $userCompanyIds),
            brandIds: self::sanitizeIds($request->input('brand_ids', [])),
            categoryIds: self::sanitizeIds($request->input('category_ids', [])),
            sku: self::sanitizeSku($request->input('sku')),
            productIds: self::sanitizeIds($request->input('product_ids', [])),
        );
    }

    /**
     * Фильтры CRM: изоляция обеспечивается скоупом клиентов (AnalyticsContext),
     * поэтому company_ids не пересекаются с чьими-либо юрлицами — id вне скоупа
     * просто не дадут строк. Разрез по менеджерам ограничивает состав скоупа
     * на стороне контроллера, здесь его нет.
     */
    public static function fromScopeRequest(Request $request): self
    {
        [$dateFrom, $dateTo] = self::resolveRange($request);

        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            companyIds: self::sanitizeIds($request->input('company_ids', [])),
            brandIds: self::sanitizeIds($request->input('brand_ids', [])),
            categoryIds: self::sanitizeIds($request->input('category_ids', [])),
            sku: self::sanitizeSku($request->input('sku')),
            productIds: self::sanitizeIds($request->input('product_ids', [])),
        );
    }

    /**
     * Допустимые режимы базы сравнения.
     */
    public const COMPARE_MODES = ['prev_period', 'month', 'quarter', 'year'];

    /**
     * Диапазон для сравнения — сдвиг текущего окна назад по выбранной базе.
     * Длина окна сохраняется, поэтому наложение на графике идёт по позиции точки.
     *
     * @param  string  $mode  prev_period|month|quarter|year (иначе — null)
     * @param  int  $offset  сколько единиц назад (1..12)
     */
    public function comparisonPeriod(string $mode, int $offset = 1): ?self
    {
        if (! in_array($mode, self::COMPARE_MODES, true)) {
            return null;
        }

        $offset = max(1, min(12, $offset));

        [$from, $to] = match ($mode) {
            'prev_period' => [
                $this->dateFrom->subDays($offset * $this->periodDays()),
                $this->dateTo->subDays($offset * $this->periodDays()),
            ],
            'month' => [
                $this->dateFrom->subMonthsNoOverflow($offset),
                $this->dateTo->subMonthsNoOverflow($offset),
            ],
            'quarter' => [
                $this->dateFrom->subMonthsNoOverflow($offset * 3),
                $this->dateTo->subMonthsNoOverflow($offset * 3),
            ],
            'year' => [
                $this->dateFrom->subYearsNoOverflow($offset),
                $this->dateTo->subYearsNoOverflow($offset),
            ],
        };

        return new self(
            dateFrom: $from,
            dateTo: $to,
            companyIds: $this->companyIds,
            brandIds: $this->brandIds,
            categoryIds: $this->categoryIds,
            sku: $this->sku,
            productIds: $this->productIds,
        );
    }

    /**
     * Предыдущий период такой же длины, вплотную до начала текущего.
     * Используется во внутренних инсайтах (trendBrands).
     */
    public function previousPeriod(): self
    {
        return $this->comparisonPeriod('prev_period', 1) ?? $this;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolveRange(Request $request): array
    {
        $dateFrom = self::parseDate($request->input('date_from'))
            ?? CarbonImmutable::now()->startOfMonth();
        $dateTo = self::parseDate($request->input('date_to'))
            ?? CarbonImmutable::now();

        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom->startOfDay(), $dateTo->endOfDay()];
    }

    public function periodDays(): int
    {
        return max(1, (int) $this->dateFrom->diffInDays($this->dateTo) + 1);
    }

    public function bucket(): string
    {
        $days = $this->periodDays();
        if ($days <= 62) {
            return 'day';
        }
        if ($days <= 186) {
            return 'week';
        }

        return 'month';
    }

    /**
     * Сериализация для отладки/кэш-ключа.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'company_ids' => $this->companyIds,
            'brand_ids' => $this->brandIds,
            'category_ids' => $this->categoryIds,
            'sku' => $this->sku,
            'product_ids' => $this->productIds,
        ];
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, int>
     */
    private static function sanitizeIds(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $input),
            fn (int $id) => $id > 0,
        )));
    }

    /**
     * @param  array<int, int>  $allowedIds
     * @return array<int, int>
     */
    private static function intersectIds(mixed $input, array $allowedIds): array
    {
        $ids = self::sanitizeIds($input);
        if ($ids === []) {
            return [];
        }

        return array_values(array_intersect($ids, $allowedIds));
    }

    private static function sanitizeSku(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
