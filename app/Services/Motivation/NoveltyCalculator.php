<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPartnerNovelty;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Кэш новизны партнёров (эпик mot-00, карточка mot-23).
 *
 * Партнёр признаётся Новым, если у него не было отгрузок двенадцать месяцев,
 * предшествующих возобновлению закупок (п. 2.9). Период новизны — шесть
 * расчётных периодов с месяца первой покупки после перерыва (п. 2.10).
 *
 * Принятое упрощение (решение заказчика от 08.09.2026): отсутствие партнёра
 * в нашей истории отгрузок приравнивается к отсутствию закупок. Партнёр,
 * которого в истории нет вовсе, при первой покупке признаётся Новым.
 *
 * История отгрузок начинается 12.01.2026, поэтому партнёр с первой покупкой
 * в первом месяце выгрузки неотличим от партнёра с многолетней историей.
 * Такие строки помечаются флагом history_incomplete. На начисление флаг
 * не влияет — система обязана различать знание и его отсутствие, а не
 * подменять одно другим.
 */
class NoveltyCalculator
{
    /**
     * Пересчитать кэш. Возвращает число обработанных партнёров.
     *
     * @param  list<int>  $partnerIds  пусто — все партнёры
     */
    public function rebuild(array $partnerIds = []): int
    {
        $months = $this->purchaseMonths($partnerIds);
        $historyStart = $this->historyStart();
        $now = now();

        $ids = $partnerIds !== [] ? $partnerIds : $this->allPartnerIds();
        $rows = [];

        foreach ($ids as $partnerId) {
            $rows[] = $this->row($partnerId, $months[$partnerId] ?? [], $historyStart, $now);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MotivationPartnerNovelty::query()->upsert(
                $chunk,
                ['user_id'],
                [
                    'first_shipment_on', 'last_shipment_before_gap_on', 'gap_days',
                    'novelty_started_on', 'novelty_ends_on', 'history_incomplete', 'computed_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * Строка кэша по одному партнёру.
     *
     * @param  list<string>  $purchaseMonths  месяцы покупок в порядке возрастания, Y-m-01
     * @return array<string, mixed>
     */
    private function row(int $partnerId, array $purchaseMonths, ?CarbonImmutable $historyStart, \DateTimeInterface $now): array
    {
        $base = [
            'user_id' => $partnerId,
            'first_shipment_on' => null,
            'last_shipment_before_gap_on' => null,
            'gap_days' => null,
            'novelty_started_on' => null,
            'novelty_ends_on' => null,
            'history_incomplete' => false,
            'computed_at' => $now,
        ];

        if ($purchaseMonths === []) {
            // Ни одной отгрузки: партнёр станет Новым при первой покупке,
            // но пока Период новизны не начат — начислять нечего.
            return $base;
        }

        $first = CarbonImmutable::parse($purchaseMonths[0]);
        $noveltyMonths = max(1, (int) config('motivation.default_parameters.novelty_periods', 6));
        $gapMonths = max(1, (int) config('motivation.default_parameters.no_purchase_months', 12));

        // Последний перерыв нужной длины задаёт начало действующего Периода новизны:
        // партнёр, замолчавший на год и вернувшийся, снова Новый (п. 2.9).
        $start = $first;
        $lastBeforeGap = null;
        $gapDays = null;

        for ($i = 1; $i < count($purchaseMonths); $i++) {
            $previous = CarbonImmutable::parse($purchaseMonths[$i - 1]);
            $current = CarbonImmutable::parse($purchaseMonths[$i]);

            if ($previous->diffInMonths($current) >= $gapMonths) {
                $start = $current;
                $lastBeforeGap = $previous;
                $gapDays = (int) $previous->diffInDays($current);
            }
        }

        return array_replace($base, [
            'first_shipment_on' => $first->toDateString(),
            'last_shipment_before_gap_on' => $lastBeforeGap?->toDateString(),
            'gap_days' => $gapDays,
            'novelty_started_on' => $start->toDateString(),
            'novelty_ends_on' => $start->addMonths($noveltyMonths)->subDay()->toDateString(),
            // Перерыв подтвердить нельзя, если Период новизны начат первой
            // покупкой, пришедшейся на первый месяц доступной истории.
            'history_incomplete' => $lastBeforeGap === null
                && $historyStart !== null
                && $start->lessThanOrEqualTo($historyStart),
        ]);
    }

    /**
     * Месяцы покупок по каждому партнёру, по возрастанию.
     *
     * Выбираются различные дни отгрузок, а месяцы складываются в PHP:
     * DATE() понимают и MySQL, и SQLite, на котором идут тесты, тогда как
     * DATE_FORMAT есть только в MySQL. Объём это позволяет — во всей истории
     * восемь с половиной тысяч отгрузок у ста семнадцати партнёров.
     *
     * @param  list<int>  $partnerIds
     * @return array<int, list<string>>
     */
    private function purchaseMonths(array $partnerIds): array
    {
        $rows = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereNotNull('user_id')
            ->whereNotNull('erp_created_at')
            ->when($partnerIds !== [], fn ($q) => $q->whereIn('user_id', $partnerIds))
            ->distinct()
            ->orderBy('user_id')
            ->orderBy('day')
            ->get(['user_id', DB::raw('DATE(erp_created_at) AS day')]);

        $months = [];

        foreach ($rows as $row) {
            $partnerId = (int) $row->getAttribute('user_id');
            $month = CarbonImmutable::parse((string) $row->getAttribute('day'))->startOfMonth()->toDateString();

            if (($months[$partnerId] ?? []) === [] || end($months[$partnerId]) !== $month) {
                $months[$partnerId][] = $month;
            }
        }

        return $months;
    }

    /**
     * Первый месяц доступной истории отгрузок.
     */
    private function historyStart(): ?CarbonImmutable
    {
        $first = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereNotNull('erp_created_at')
            ->min('erp_created_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first)->startOfMonth();
    }

    /**
     * @return list<int>
     */
    private function allPartnerIds(): array
    {
        return User::query()->clients()->pluck('id')->map('intval')->all();
    }
}
