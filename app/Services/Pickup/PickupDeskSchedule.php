<?php

namespace App\Services\Pickup;

use App\Models\Pickup\PickupDeskDay;
use App\Models\Pickup\PickupDeskPause;
use App\Services\Warehouse\WarehouseSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Стойка выдачи самовывоза (pick-18): выдают ли сейчас и когда перерывы.
 *
 * Источник — одна таблица pickup_desk_days: на каждый день недели часы выдачи и технические перерывы,
 * ровно то, что видят курьер и клиент (решение заказчика 22.09.2026: внутренняя кухня со сменами
 * никому не нужна). Поверх плана — живые отлучки «Отойти» с экрана выдачи: нажал — стойка закрыта
 * до срока, «Вернулся» — открыта.
 *
 * Все интервалы — минуты от полуночи дня склада: интервальная арифметика проще и без ошибок на
 * границах, чем сравнение дат.
 */
class PickupDeskSchedule
{
    public const STATE_OPEN = 'open';

    public const STATE_BREAK = 'break';

    public const STATE_CLOSED = 'closed';

    public function __construct(private readonly WarehouseSchedule $warehouse) {}

    /**
     * Плановые перерывы на день недели, как записаны в таблице.
     *
     * @return list<array{from: string, to: string, label: string}>
     */
    public function plannedBreaks(int $isoWeekday): array
    {
        $day = PickupDeskDay::query()->where('iso_weekday', $isoWeekday)->first();

        return $day ? array_values(array_map(fn (array $b) => [
            'from' => (string) $b['from'], 'to' => (string) $b['to'], 'label' => (string) ($b['label'] ?? ''),
        ], (array) $day->breaks)) : [];
    }

    /**
     * Окна, когда выдача закрыта, в пределах часов склада на дату.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, label: string}>
     */
    public function closedWindows(CarbonInterface $date, bool $withPauses = true): array
    {
        $day = $this->local($date)->startOfDay();
        $hours = $this->warehouse->hoursFor($day);
        if ($hours === null) {
            return [];
        }

        [$open, $close] = [$this->minutes($hours[0]), $this->minutes($hours[1])];
        $cuts = []; // [from, to, label]

        foreach ($this->plannedBreaks($day->isoWeekday()) as $break) {
            $cuts[] = [$this->hm($break['from']), $this->hm($break['to']), $break['label']];
        }
        if ($withPauses) {
            foreach ($this->pausesOn($day) as $pause) {
                $cuts[] = $this->pauseInterval($pause, $day);
            }
        }

        $available = [[$open, $close]];
        foreach ($cuts as $cut) {
            $available = $this->subtract($available, $cut);
        }
        $closed = [[$open, $close]];
        foreach ($available as $keep) {
            $closed = $this->subtract($closed, $keep);
        }

        return array_map(function (array $window) use ($day, $cuts) {
            $labels = [];
            foreach ($cuts as [$from, $to, $label]) {
                if ($from < $window[1] && $to > $window[0] && trim($label) !== '') {
                    $labels[mb_strtolower(trim($label))] = true;
                }
            }

            return [
                'from' => $day->addMinutes($window[0]),
                'to' => $day->addMinutes($window[1]),
                'label' => implode(', ', array_keys($labels)),
            ];
        }, $closed);
    }

    /**
     * Что со стойкой прямо сейчас.
     *
     * @return array{state: string, text: string, until: ?string, label: ?string, next_break: ?string}
     */
    public function status(CarbonInterface $at): array
    {
        $at = $this->local($at);

        if (! $this->warehouse->isOpen($at)) {
            $opening = $this->warehouse->nextOpening($at);

            return [
                'state' => self::STATE_CLOSED,
                'text' => $opening ? 'Склад закрыт, откроется '.$this->when($opening, $at) : 'Склад закрыт',
                'until' => $opening?->toIso8601String(),
                'label' => null,
                'next_break' => null,
            ];
        }

        $windows = $this->closedWindows($at);
        foreach ($windows as $window) {
            if ($at->gte($window['from']) && $at->lt($window['to'])) {
                return [
                    'state' => self::STATE_BREAK,
                    'text' => 'Перерыв до '.$window['to']->format('H:i').($window['label'] !== '' ? ' · '.$window['label'] : ''),
                    'until' => $window['to']->toIso8601String(),
                    'label' => $window['label'] ?: null,
                    'next_break' => null,
                ];
            }
        }

        $next = collect($windows)->first(fn (array $w) => $w['from']->gt($at));

        return [
            'state' => self::STATE_OPEN,
            'text' => 'Сейчас выдают',
            'until' => null,
            'label' => null,
            'next_break' => $next ? $this->windowText($next) : null,
        ];
    }

    /**
     * Сводка для экрана склада: статус, перерывы сегодня, действующие отлучки.
     *
     * @return array<string, mixed>
     */
    public function summary(CarbonInterface $at): array
    {
        $at = $this->local($at);
        $today = $this->closedWindows($at);
        $tz = $this->warehouse->timezone();

        return [
            ...$this->status($at),
            'phone' => (string) config('warehouse.pickup_phone'),
            'closes_at' => $this->warehouse->closesAt($at)?->format('H:i'),
            'today_closed' => array_map(fn (array $w) => [
                'from' => $w['from']->format('H:i'),
                'to' => $w['to']->format('H:i'),
                'label' => $w['label'],
            ], $today),
            'today_text' => $this->todayText($today, $at),
            'week_text' => $this->weekText(),
            'pauses' => PickupDeskPause::query()->active($at)->with('user:id,name')->orderBy('until_at')->get()
                ->map(fn (PickupDeskPause $p) => [
                    'id' => $p->id,
                    'reason' => $p->reason,
                    'started_at' => $p->started_at->toIso8601String(),
                    'until' => $p->until_at->setTimezone($tz)->format('H:i'),
                    'user_id' => $p->user_id,
                    'user_name' => $p->user?->name,
                ])->values()->all(),
        ];
    }

    /**
     * Та же сводка для курьера, клиента и API: без отлучек по именам — наружу уходит только итог.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(CarbonInterface $at): array
    {
        return array_diff_key($this->summary($at), ['pauses' => true]);
    }

    /** «пн–пт 13:00–14:00; сб без перерывов» — по плану, без живых отлучек; null, если перерывов нет вовсе. */
    public function weekText(): ?string
    {
        $names = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
        $monday = CarbonImmutable::now($this->warehouse->timezone())->startOfWeek();
        $groups = [];
        $anyClosed = false;

        for ($iso = 1; $iso <= 7; $iso++) {
            $day = $monday->addDays($iso - 1);
            if ($this->warehouse->hoursFor($day) === null) {
                continue;
            }
            $windows = $this->closedWindows($day, false);
            $anyClosed = $anyClosed || $windows !== [];
            $label = $windows === [] ? 'без перерывов' : implode(', ', array_map(fn (array $w) => $w['from']->format('H:i').'–'.$w['to']->format('H:i'), $windows));
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['label'] === $label && $groups[$last]['to'] === $iso - 1) {
                $groups[$last]['to'] = $iso;
            } else {
                $groups[] = ['from' => $iso, 'to' => $iso, 'label' => $label];
            }
        }

        if (! $anyClosed) {
            return null;
        }

        return implode('; ', array_map(
            fn (array $g) => ($g['from'] === $g['to'] ? $names[$g['from']] : $names[$g['from']].'–'.$names[$g['to']]).' '.$g['label'],
            $groups,
        ));
    }

    /** @return Collection<int, PickupDeskPause> Отлучки, пересекающие дату (действующие и завершённые). */
    private function pausesOn(CarbonImmutable $day): Collection
    {
        return PickupDeskPause::query()
            ->where('started_at', '<', $day->endOfDay())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $day->startOfDay()))
            ->where('until_at', '>', $day->startOfDay())
            ->get();
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function pauseInterval(PickupDeskPause $pause, CarbonImmutable $day): array
    {
        $tz = $this->warehouse->timezone();
        $from = $pause->started_at->setTimezone($tz);
        $to = $pause->endsAt()->setTimezone($tz);

        return [
            $from->lt($day) ? 0 : $this->minutes($from),
            $to->gt($day->endOfDay()) ? 24 * 60 : $this->minutes($to),
            $pause->reason,
        ];
    }

    /** @param list<array{from: CarbonImmutable, to: CarbonImmutable, label: string}> $windows */
    private function todayText(array $windows, CarbonImmutable $at): string
    {
        if ($this->warehouse->hoursFor($at) === null) {
            return 'Сегодня склад не работает';
        }
        if ($windows === []) {
            return 'Сегодня выдают без перерывов';
        }

        return 'Перерывы сегодня: '.implode(', ', array_map(fn (array $w) => $this->windowText($w), $windows));
    }

    /** @param array{from: CarbonImmutable, to: CarbonImmutable, label: string} $w */
    private function windowText(array $w): string
    {
        return $w['from']->format('H:i').'–'.$w['to']->format('H:i').($w['label'] !== '' ? ' ('.$w['label'].')' : '');
    }

    private function when(CarbonImmutable $moment, CarbonImmutable $now): string
    {
        $time = $moment->format('H:i');
        if ($moment->isSameDay($now)) {
            return 'в '.$time;
        }
        if ($moment->isSameDay($now->addDay())) {
            return 'завтра в '.$time;
        }

        return $moment->format('d.m').' в '.$time;
    }

    private function minutes(CarbonInterface $at): int
    {
        return $at->hour * 60 + $at->minute;
    }

    private function hm(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->warehouse->timezone());
    }

    /**
     * Вычитание отрезка из списка отрезков [from, to) в минутах.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @param  array{0: int, 1: int, 2?: string}  $cut
     * @return list<array{0: int, 1: int}>
     */
    private function subtract(array $intervals, array $cut): array
    {
        [$cf, $ct] = [$cut[0], $cut[1]];
        if ($ct <= $cf) {
            return $intervals;
        }
        $result = [];
        foreach ($intervals as [$from, $to]) {
            if ($ct <= $from || $cf >= $to) {
                $result[] = [$from, $to];

                continue;
            }
            if ($cf > $from) {
                $result[] = [$from, $cf];
            }
            if ($ct < $to) {
                $result[] = [$ct, $to];
            }
        }

        return $result;
    }
}
