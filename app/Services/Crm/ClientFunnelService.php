<?php

namespace App\Services\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Support\Crm\ClientListFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Воронка партнёров: сколько их на каждой стадии и сколько они приносят в месяц.
 *
 * Раздел партнёров должен читаться как воронка (решение РОПа 09.09.2026):
 * «где, сколько и каких клиентов» — одной строкой чипов от лида до банкрота,
 * до того, как менеджер начнёт листать таблицу.
 *
 * Считается по тому же отбору, что и список, кроме самой стадии: чипы —
 * это оси воронки, а не ещё один фильтр, и число спящих не должно исчезать,
 * когда смотришь активных. Остальные отборы (менеджер, задачи, покупки)
 * сужают базу воронки — они комплементарны, а не конкурируют с ней.
 *
 * Деньги — исключительно через {@see ShipmentAnalyticsService}: свой SUM по
 * отгрузкам стал бы вторым движком выручки, и цифра на чипе разошлась бы с
 * планом/фактом в той же таблице и с /crm/analytics.
 */
class ClientFunnelService
{
    /**
     * Окно среднемесячных отгрузок, в месяцах — текущий и одиннадцать до него.
     */
    public const MONTHS = 12;

    /**
     * Сколько держать суммы по скоупу свежими — те же пять минут, что у
     * плана/факта ({@see ClientPlanFactService}): отгрузки приезжают из 1С
     * пачками, а воронка перечитывается на каждый клик по чипу.
     */
    private const CACHE_TTL = 300;

    /**
     * Ещё час значение отдаётся протухшим, пересчёт уходит в фон.
     */
    private const CACHE_GRACE = 3600;

    public function __construct(
        private readonly ClientListService $list,
        private readonly ShipmentAnalyticsService $analytics,
    ) {}

    /**
     * Воронка по текущему отбору.
     *
     * @param  bool  $withMoney  считать ли суммы (право видеть выручку партнёров)
     * @return array{
     *     total: int,
     *     months: int,
     *     stages: list<array{
     *         value: string, label: string, color: string, description: string,
     *         group: string, group_label: string,
     *         count: int, monthly_amount: float|null
     *     }>
     * }
     */
    public function forFilters(User $actor, ClientListFilters $filters, bool $withMoney): array
    {
        $stageByClient = $this->stageByClient($actor, $filters);

        $counts = array_fill_keys(
            array_map(fn (ClientLifecycleStatus $s) => $s->value, ClientLifecycleStatus::cases()),
            0,
        );

        foreach ($stageByClient as $stage) {
            $counts[$stage] = ($counts[$stage] ?? 0) + 1;
        }

        $money = $withMoney ? $this->monthlyByStage($stageByClient) : [];

        $stages = [];

        foreach (ClientLifecycleStatus::cases() as $status) {
            $stages[] = [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
                'description' => $status->description(),
                'group' => $status->group(),
                'group_label' => $status->groupLabel(),
                'count' => $counts[$status->value] ?? 0,
                'monthly_amount' => $withMoney ? round($money[$status->value] ?? 0.0, 2) : null,
            ];
        }

        return [
            'total' => count($stageByClient),
            'months' => self::MONTHS,
            'stages' => $stages,
        ];
    }

    /**
     * Стадия каждого партнёра отбора: id => значение стадии.
     *
     * Один запрос по всему скоупу — партнёр без профиля читается как «активен»,
     * ровно как в колонке списка и в фильтре по стадии
     * ({@see ClientListService::applyLifecycle()}). Разойтись с ними здесь
     * нельзя: чип «Активные: 300» и таблица «Активные» на 250 строк — это баг,
     * который заметят в первый же день.
     *
     * @return array<int, string>
     */
    private function stageByClient(User $actor, ClientListFilters $filters): array
    {
        $default = ClientLifecycleStatus::ACTIVE->value;

        $rows = $this->list->filtered($actor, $filters, withLifecycle: false)
            ->leftJoin('crm_client_profiles as funnel_profile', 'funnel_profile.user_id', '=', 'users.id')
            ->selectRaw('users.id as client_id, COALESCE(funnel_profile.lifecycle_status, ?) as stage', [$default])
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $stage = (string) $row->stage;

            // Снятая стадия в старом профиле (см. RETIRED в перечислении) —
            // считаем её преемницей, а не выбрасываем партнёра из воронки.
            if (ClientLifecycleStatus::tryFrom($stage) === null) {
                $replacement = ClientLifecycleStatus::replacementFor($stage);
                $stage = $replacement === null ? $default : $replacement->value;
            }

            $result[(int) $row->client_id] = $stage;
        }

        return $result;
    }

    /**
     * Сумма среднемесячных отгрузок по каждой стадии, в рублях.
     *
     * Среднемесячная отгрузка партнёра — его выручка за последние двенадцать
     * месяцев (текущий и одиннадцать до него, по бизнес-дате 1С), делённая
     * на двенадцать. Партнёры без отгрузок в окне дают ноль и на сумму чипа
     * не влияют.
     *
     * @param  array<int, string>  $stageByClient
     * @return array<string, float>
     */
    private function monthlyByStage(array $stageByClient): array
    {
        if ($stageByClient === []) {
            return [];
        }

        $revenue = $this->revenueForClients(array_keys($stageByClient));
        $result = [];

        foreach ($revenue as $clientId => $amount) {
            $stage = $stageByClient[$clientId] ?? null;

            if ($stage === null) {
                continue;
            }

            $result[$stage] = ($result[$stage] ?? 0.0) + $amount / self::MONTHS;
        }

        return $result;
    }

    /**
     * Выручка за окно по каждому партнёру, в рублях, с кэшем по составу скоупа.
     *
     * @param  list<int>  $clientIds
     * @return array<int, float>
     */
    private function revenueForClients(array $clientIds): array
    {
        sort($clientIds);

        $to = CarbonImmutable::now();
        $from = $to->subMonthsNoOverflow(self::MONTHS - 1)->startOfMonth();
        $key = 'crm:funnel:revenue:'.md5(implode(',', $clientIds)).':'.$to->format('Y-m-d');

        return Cache::flexible(
            $key,
            [self::CACHE_TTL, self::CACHE_TTL + self::CACHE_GRACE],
            function () use ($clientIds, $from, $to): array {
                $ctx = AnalyticsContext::forScope($clientIds, AnalyticsContext::DATE_ERP, null);

                if ($ctx->isEmpty()) {
                    return [];
                }

                $filters = new AnalyticsFilters(
                    dateFrom: $from->startOfDay(),
                    dateTo: $to->endOfDay(),
                );

                $rows = [];

                // Лимит по числу партнёров: byPartner отдаёт топ-50 по умолчанию,
                // и хвост длинного списка молча остался бы без выручки.
                foreach ($this->analytics->byPartner($ctx, $filters, count($clientIds)) as $row) {
                    if ($row['partner_id'] !== null) {
                        $rows[(int) $row['partner_id']] = (float) $row['amount'];
                    }
                }

                return $rows;
            },
        );
    }

    /**
     * Сбросить кэш сумм: пригодится тестам и ручному пересчёту.
     */
    public static function forget(array $clientIds): void
    {
        sort($clientIds);

        Cache::forget('crm:funnel:revenue:'.md5(implode(',', $clientIds)).':'.CarbonImmutable::now()->format('Y-m-d'));
    }
}
