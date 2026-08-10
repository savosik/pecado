<?php

namespace App\Services\Crm\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Иммутабельный набор фильтров финансового раздела CRM.
 *
 * Изоляцию данных фильтры не обеспечивают — её задаёт скоуп партнёров
 * (User::visibleInCrm) на стороне контроллера. Здесь только отбор внутри скоупа,
 * поэтому id не пересекаются ни с какими списками доступного: чужой id просто
 * не даст строк.
 */
class FinanceFilters
{
    /** Максимальная глубина периода. Дальше отчёт превращается в выгрузку всей истории. */
    private const MAX_RANGE_DAYS = 730;

    /** Горизонт по умолчанию: ближайшие 90 дней ожидаемых поступлений. */
    private const DEFAULT_FORWARD_DAYS = 90;

    /** @var list<string> */
    public const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * @param  array<int, int>  $managerIds  разрез по менеджерам (только РОПу)
     * @param  array<int, int>  $clientIds  конкретные партнёры (users.id)
     * @param  array<int, int>  $organizationIds  наши юрлица (shipments.organization_id)
     * @param  bool  $onlyOverdue  только строки с прошедшей плановой датой
     * @param  bool  $includeNoSchedule  включать долг реализаций без графика от 1С
     */
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly array $managerIds = [],
        public readonly array $clientIds = [],
        public readonly array $organizationIds = [],
        public readonly bool $onlyOverdue = false,
        public readonly bool $includeNoSchedule = true,
        public readonly string $granularity = 'week',
    ) {}

    /**
     * Период по умолчанию — от сегодня на 90 дней вперёд: раздел отвечает
     * на вопрос «сколько придёт», а не «сколько пришло». Просрочка в него
     * не попадает намеренно — она считается отдельно и от периода не зависит.
     */
    public static function fromRequest(Request $request): self
    {
        [$dateFrom, $dateTo] = self::resolveRange($request);

        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            managerIds: self::sanitizeIds($request->input('manager_ids', [])),
            clientIds: self::sanitizeIds($request->input('client_ids', [])),
            organizationIds: self::sanitizeIds($request->input('organization_ids', [])),
            onlyOverdue: $request->boolean('only_overdue'),
            includeNoSchedule: ! $request->has('include_no_schedule') || $request->boolean('include_no_schedule'),
            granularity: self::sanitizeGranularity($request->input('granularity')),
        );
    }

    /**
     * Копия с другим периодом — для блока «по месяцам» в выгрузке, где нужен
     * тот же отбор, но шире окно.
     */
    public function withRange(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            dateFrom: $from,
            dateTo: $to,
            managerIds: $this->managerIds,
            clientIds: $this->clientIds,
            organizationIds: $this->organizationIds,
            onlyOverdue: $this->onlyOverdue,
            includeNoSchedule: $this->includeNoSchedule,
            granularity: $this->granularity,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'manager_ids' => $this->managerIds,
            'client_ids' => $this->clientIds,
            'organization_ids' => $this->organizationIds,
            'only_overdue' => $this->onlyOverdue,
            'include_no_schedule' => $this->includeNoSchedule,
            'granularity' => $this->granularity,
        ];
    }

    /**
     * Человекочитаемое описание периода для шапки выгрузки.
     */
    public function rangeLabel(): string
    {
        return $this->dateFrom->format('d.m.Y').' — '.$this->dateTo->format('d.m.Y');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolveRange(Request $request): array
    {
        $today = CarbonImmutable::today();

        $from = self::parseDate($request->input('date_from')) ?? $today;
        $to = self::parseDate($request->input('date_to')) ?? $today->addDays(self::DEFAULT_FORWARD_DAYS);

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        // Обрезаем хвост, а не начало: пользователь задал точку отсчёта осознанно,
        // а конец периода чаще всего результат опечатки в годе.
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sanitizeGranularity(mixed $value): string
    {
        return is_string($value) && in_array($value, self::GRANULARITIES, true) ? $value : 'week';
    }

    /**
     * @return array<int, int>
     */
    private static function sanitizeIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): int => (int) $item, $value),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
