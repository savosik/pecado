<?php

namespace App\Services\Pickup;

use App\Models\Pickup\PickupDeskBreak;
use App\Models\Pickup\PickupDeskPause;
use App\Models\Pickup\PickupDeskStaff;
use App\Services\Warehouse\WarehouseSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Стойка выдачи самовывоза (pick-18): выдают ли сейчас и когда перерывы.
 *
 * Правило одно: выдача закрыта, только когда на стойке никого. Смена — сотрудники, у которых
 * этот день недели рабочий; у каждого из часов склада вычитаются его плановые перерывы и живые
 * отлучки («Отойти» на экране выдачи), затем доступности складываются. Два человека, обедающие
 * по очереди, выдачу не останавливают; один — останавливает на время обеда. Если смена не
 * заведена, стойка считается открытой все часы склада, и закрыть её могут только отлучки
 * «стойка целиком» (без сотрудника).
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

    /** @return Collection<int, PickupDeskStaff> Смена на дату: активные сотрудники с этим днём недели. */
    public function roster(CarbonInterface $date): Collection
    {
        $iso = $this->local($date)->isoWeekday();

        return PickupDeskStaff::query()->where('active', true)->with('breaks')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (PickupDeskStaff $staff) => $staff->worksOn($iso))
            ->values();
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
        $roster = $this->roster($day);
        $pauses = $withPauses ? $this->pausesOn($day) : collect();
        $unavailable = []; // [from, to, label] — всё, что кого-то снимает со стойки; для подписей окон

        // Доступность стойки = объединение доступностей людей смены (или все часы, если смены нет).
        if ($roster->isEmpty()) {
            $available = [[$open, $close]];
        } else {
            $available = [];
            foreach ($roster as $staff) {
                $own = [[$open, $close]];
                foreach ($staff->breaks as $break) {
                    if (! $break->appliesOn($day->isoWeekday())) {
                        continue;
                    }
                    $cut = [$this->hm($break->starts_at), $this->hm($break->ends_at), $break->label];
                    $own = $this->subtract($own, $cut);
                    $unavailable[] = $cut;
                }
                foreach ($pauses->where('staff_id', $staff->id) as $pause) {
                    $cut = $this->pauseInterval($pause, $day);
                    $own = $this->subtract($own, $cut);
                    $unavailable[] = $cut;
                }
                $available = $this->union(array_merge($available, $own));
            }
        }

        // Отлучки «стойка целиком» закрывают выдачу независимо от смены.
        foreach ($pauses->whereNull('staff_id') as $pause) {
            $cut = $this->pauseInterval($pause, $day);
            $available = $this->subtract($available, $cut);
            $unavailable[] = $cut;
        }

        $closed = $this->subtractMany([[$open, $close]], $available);

        return array_map(function (array $window) use ($day, $unavailable) {
            $labels = [];
            foreach ($unavailable as [$from, $to, $label]) {
                if ($from < $window[1] && $to > $window[0]) {
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
     * Сводка для экранов: курьеру, клиенту и складу.
     *
     * @return array<string, mixed>
     */
    public function summary(CarbonInterface $at): array
    {
        $at = $this->local($at);
        $today = $this->closedWindows($at);
        $roster = $this->roster($at);
        $pauses = $this->pausesOn($at)->filter(fn (PickupDeskPause $p) => $p->until_at->gt($at));

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
            'roster' => $roster->map(fn (PickupDeskStaff $s) => ['id' => $s->id, 'name' => $s->name, 'user_id' => $s->user_id])->values()->all(),
            'pauses' => $pauses->map(fn (PickupDeskPause $p) => [
                'id' => $p->id,
                'staff_id' => $p->staff_id,
                'staff_name' => $p->staff?->name,
                'reason' => $p->reason,
                'until' => $p->until_at->setTimezone($this->warehouse->timezone())->format('H:i'),
                'user_id' => $p->user_id,
            ])->values()->all(),
        ];
    }

    /**
     * Та же сводка для курьера, клиента и API: без имён смены и чьих-то отлучек — наружу уходит только итог.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(CarbonInterface $at): array
    {
        return array_diff_key($this->summary($at), ['roster' => true, 'pauses' => true]);
    }

    /** «Перерывы: пн–пт 13:00–14:00; сб без перерывов» — по плану, без живых отлучек. */
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

    /** @return Collection<int, PickupDeskPause> Отлучки, пересекающие дату (действующие или завершённые). */
    private function pausesOn(CarbonImmutable $day): Collection
    {
        return PickupDeskPause::query()->with('staff')
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
        $to = ($pause->ended_at ?? $pause->until_at)->setTimezone($tz);
        if ($pause->ended_at !== null && $pause->ended_at->gt($pause->until_at)) {
            $to = $pause->until_at->setTimezone($tz);
        }

        return [
            max(0, $from->lt($day) ? 0 : $this->minutes($from)),
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
        [$h, $m] = array_map('intval', explode(':', PickupDeskBreak::hm($time)));

        return $h * 60 + $m;
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->warehouse->timezone());
    }

    // ---- интервальная арифметика: списки [from, to) в минутах, отсортированные и без пересечений

    /**
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

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @param  list<array{0: int, 1: int}>  $cuts
     * @return list<array{0: int, 1: int}>
     */
    private function subtractMany(array $intervals, array $cuts): array
    {
        foreach ($cuts as $cut) {
            $intervals = $this->subtract($intervals, $cut);
        }

        return $intervals;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private function union(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            $last = array_key_last($merged);
            if ($last !== null && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }
}
