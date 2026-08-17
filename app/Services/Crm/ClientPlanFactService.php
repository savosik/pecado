<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * План и факт продаж по партнёрам за месяц — для колонки в списке партнёров.
 *
 * Факт берётся исключительно из {@see ShipmentAnalyticsService}: свой SUM по
 * отгрузкам был бы вторым движком расчёта, и цифра в списке партнёров рано или
 * поздно разошлась бы с /crm/analytics. Расхождение таких цифр — баг по
 * определению, а не «разные методики».
 *
 * Полное выполнение плана (прогноз, burndown, разрез по менеджерам) живёт
 * в отдельной карточке crm-06 и сюда не тянется.
 */
class ClientPlanFactService
{
    /**
     * Сколько держать факт по всему скоупу свежим.
     *
     * Пять минут: отгрузки приезжают из 1С пачками, и пересчитывать агрегат по
     * восьмистам партнёрам на каждое нажатие «отстают от плана» незачем.
     */
    private const SCOPE_CACHE_TTL = 300;

    /**
     * Ещё час после протухания значение отдаётся как есть, а пересчёт уходит
     * в фон после ответа — тот же stale-while-revalidate, что в
     * {@see PlanProgressService}: первый заход после простоя не должен ждать
     * агрегат по восьмистам партнёрам синхронно.
     */
    private const SCOPE_CACHE_GRACE = 3600;

    public function __construct(private readonly ShipmentAnalyticsService $analytics) {}

    /**
     * План и факт по конкретным партнёрам за месяц.
     *
     * Два запроса независимо от числа партнёров: планы одной выборкой, факт —
     * одним сгруппированным агрегатом.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{plan: float|null, fact: float, percent: int|null}>
     */
    public function forClients(array $clientIds, CarbonInterface $month): array
    {
        $clientIds = array_values(array_unique(array_filter(
            array_map('intval', $clientIds),
            fn (int $id) => $id > 0,
        )));

        if ($clientIds === []) {
            return [];
        }

        $plans = CrmSalesPlan::query()
            ->forPeriod($month)
            ->where('target_type', PlanTarget::CLIENT->value)
            ->whereIn('target_id', $clientIds)
            ->pluck('amount', 'target_id');

        $facts = $this->facts($clientIds, $month);

        $result = [];

        foreach ($clientIds as $id) {
            $plan = $plans->has($id) ? (float) $plans->get($id) : null;
            $fact = (float) ($facts[$id] ?? 0.0);

            $result[$id] = [
                'plan' => $plan,
                'fact' => $fact,
                // Процент считаем только при положительном плане: ноль означает
                // «в этом месяце не продаём», и делить на него нечего.
                'percent' => $plan !== null && $plan > 0 ? (int) round($fact / $plan * 100) : null,
            ];
        }

        return $result;
    }

    /**
     * План и факт по всему видимому скоупу — для сортировки и отбора отстающих.
     *
     * Отобрать «кто отстаёт» можно только зная факт по всем партнёрам сразу:
     * страница из пятнадцати строк для этого бесполезна. Результат кэшируется,
     * ключ включает состав скоупа и месяц.
     *
     * @param  Builder<\App\Models\User>  $visibleClients
     * @return array<int, array{plan: float|null, fact: float, percent: int|null}>
     */
    public function forScope(Builder $visibleClients, CarbonInterface $month): array
    {
        /** @var list<int> $ids */
        $ids = $visibleClients->clone()->reorder()->pluck('users.id')->map('intval')->all();

        if ($ids === []) {
            return [];
        }

        sort($ids);
        $key = 'crm:plan-fact:'.md5(implode(',', $ids)).':'.CarbonImmutable::instance($month)->format('Y-m');

        return Cache::flexible(
            $key,
            [self::SCOPE_CACHE_TTL, self::SCOPE_CACHE_TTL + self::SCOPE_CACHE_GRACE],
            fn (): array => $this->forClients($ids, $month),
        );
    }

    /**
     * Факт по партнёрам за месяц, в рублях по бизнес-дате 1С.
     *
     * @param  list<int>  $clientIds
     * @return array<int, float>
     */
    private function facts(array $clientIds, CarbonInterface $month): array
    {
        $ctx = AnalyticsContext::forScope($clientIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $start = CarbonImmutable::instance($month)->startOfMonth();

        $filters = new AnalyticsFilters(
            dateFrom: $start->startOfDay(),
            dateTo: $start->endOfMonth()->endOfDay(),
        );

        $rows = [];

        // limit по числу партнёров: byPartner по умолчанию отдаёт топ-50, и без
        // явного лимита у длинного списка хвост молча получил бы нулевой факт.
        foreach ($this->analytics->byPartner($ctx, $filters, count($clientIds)) as $row) {
            if ($row['partner_id'] !== null) {
                $rows[(int) $row['partner_id']] = (float) $row['amount'];
            }
        }

        return $rows;
    }
}
