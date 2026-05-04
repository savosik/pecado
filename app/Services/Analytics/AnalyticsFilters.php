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
     */
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly array $companyIds = [],
        public readonly array $brandIds = [],
        public readonly array $categoryIds = [],
        public readonly ?string $sku = null,
    ) {}

    public static function fromRequest(Request $request, User $user): self
    {
        $dateFrom = self::parseDate($request->input('date_from'))
            ?? CarbonImmutable::now()->startOfMonth();
        $dateTo = self::parseDate($request->input('date_to'))
            ?? CarbonImmutable::now();

        if ($dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $userCompanyIds = $user->companies()->pluck('id')->all();

        return new self(
            dateFrom: $dateFrom->startOfDay(),
            dateTo: $dateTo->endOfDay(),
            companyIds: self::intersectIds($request->input('company_ids', []), $userCompanyIds),
            brandIds: self::sanitizeIds($request->input('brand_ids', [])),
            categoryIds: self::sanitizeIds($request->input('category_ids', [])),
            sku: self::sanitizeSku($request->input('sku')),
        );
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
