<?php

namespace App\Services\Warehouse;

use App\Models\Pickup\PickupDeskDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * График склада и обещание времени сборки (pick-02).
 *
 * «Сейчас» приходит аргументом, все расчёты — в часовом поясе склада. Недельные часы берутся из
 * config/warehouse.php, а начальник склада может переопределить их по дням в таблице
 * pickup_desk_days (pick-18) — там же, где правит перерывы выдачи. Переопределение читается один раз
 * на запрос и кешируется; без таблицы (ранние миграции, консоль) действует конфиг.
 * Правило заказчика: заказ, отправленный в отгрузку до отсечки (за час до закрытия), собирается
 * сегодня; позже — со следующего открытия. Обещание = момент начала сборки + SLA.
 */
class WarehouseSchedule
{
    public const WEEK_CACHE_KEY = 'warehouse.week.override';

    /** Дальше вперёд открытие не ищем: защита от бесконечного цикла при пустом графике. */
    private const LOOKAHEAD_DAYS = 60;

    /** @var array<int, array{0: string, 1: string}|null>|null */
    private ?array $week = null;

    /**
     * Часы по дням недели: конфиг, поверх — строки pickup_desk_days.
     *
     * @return array<int, array{0: string, 1: string}|null>
     */
    public function week(): array
    {
        if ($this->week !== null) {
            return $this->week;
        }

        $week = [];
        for ($iso = 1; $iso <= 7; $iso++) {
            $hours = config("warehouse.week.$iso");
            $week[$iso] = is_array($hours) && count($hours) === 2 ? [(string) $hours[0], (string) $hours[1]] : null;
        }

        foreach ($this->overrides() as $iso => $row) {
            if (! $row['works']) {
                $week[$iso] = null;

                continue;
            }
            $week[$iso] = [$row['opens_at'] ?? $week[$iso][0] ?? '09:00', $row['closes_at'] ?? $week[$iso][1] ?? '21:00'];
        }

        return $this->week = $week;
    }

    /** Сброс после правки таблицы дней. */
    public static function forgetWeek(): void
    {
        Cache::forget(self::WEEK_CACHE_KEY);
    }

    /** @return array<int, array{works: bool, opens_at: ?string, closes_at: ?string}> */
    private function overrides(): array
    {
        try {
            return Cache::remember(self::WEEK_CACHE_KEY, 300, fn () => PickupDeskDay::query()->get()
                ->mapWithKeys(fn (PickupDeskDay $d) => [(int) $d->iso_weekday => [
                    'works' => (bool) $d->works,
                    'opens_at' => PickupDeskDay::hm($d->opens_at),
                    'closes_at' => PickupDeskDay::hm($d->closes_at),
                ]])->all());
        } catch (\Throwable) {
            return []; // таблицы ещё нет или БД недоступна — работаем по конфигу
        }
    }

    public function timezone(): string
    {
        return (string) config('warehouse.timezone', 'Europe/Moscow');
    }

    public function slaMinutes(): int
    {
        return max(1, (int) config('warehouse.sla_minutes', 40));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null Часы работы на дату или null, если выходной. */
    public function hoursFor(CarbonInterface $date): ?array
    {
        $day = $this->local($date)->startOfDay();
        $key = $day->format('Y-m-d');

        if (in_array($key, (array) config('warehouse.closed_dates', []), true)) {
            return null;
        }

        $hours = config("warehouse.special_hours.$key") ?? $this->week()[$day->isoWeekday()];
        if (! is_array($hours) || count($hours) !== 2) {
            return null;
        }

        $isHoliday = in_array($key, (array) config('production_calendar.holidays.'.$day->year, []), true);
        $forcedOpen = in_array($key, (array) config('warehouse.open_dates', []), true)
            || config("warehouse.special_hours.$key") !== null;
        if ($isHoliday && ! $forcedOpen) {
            return null;
        }

        return [$day->setTimeFromTimeString($hours[0]), $day->setTimeFromTimeString($hours[1])];
    }

    public function isOpen(CarbonInterface $at): bool
    {
        $at = $this->local($at);
        $hours = $this->hoursFor($at);

        return $hours !== null && $at->gte($hours[0]) && $at->lt($hours[1]);
    }

    public function closesAt(CarbonInterface $date): ?CarbonImmutable
    {
        return $this->hoursFor($date)[1] ?? null;
    }

    /** Отсечка приёма к сборке «на сегодня». */
    public function cutoffAt(CarbonInterface $date): ?CarbonImmutable
    {
        $hours = $this->hoursFor($date);
        if ($hours === null) {
            return null;
        }

        $cutoff = $hours[1]->subMinutes(max(0, (int) config('warehouse.cutoff_minutes_before_close', 60)));

        return $cutoff->lt($hours[0]) ? $hours[0] : $cutoff;
    }

    /** Ближайшее открытие строго после дня $at (или сегодня, если склад ещё не открылся). */
    public function nextOpening(CarbonInterface $at): ?CarbonImmutable
    {
        $at = $this->local($at);

        for ($i = 0; $i <= self::LOOKAHEAD_DAYS; $i++) {
            $hours = $this->hoursFor($at->addDays($i));
            if ($hours !== null && $hours[0]->gt($at)) {
                return $hours[0];
            }
        }

        return null;
    }

    /** Когда склад возьмёт в сборку заказ, отправленный в $at. */
    public function pickingStartsAt(CarbonInterface $at): ?CarbonImmutable
    {
        $at = $this->local($at);
        $hours = $this->hoursFor($at);
        $cutoff = $this->cutoffAt($at);

        if ($hours !== null && $cutoff !== null && $at->lt($cutoff)) {
            return $at->lt($hours[0]) ? $hours[0] : $at;
        }

        return $this->nextOpening($hours !== null ? $hours[1] : $at);
    }

    /** Обещание клиенту: к какому времени заказ будет собран. */
    public function promisedReadyAt(CarbonInterface $at): ?CarbonImmutable
    {
        return $this->pickingStartsAt($at)?->addMinutes($this->slaMinutes());
    }

    /** До скольки курьер может забрать заказ, собранный к $readyAt. */
    public function pickupDeadline(CarbonInterface $readyAt): ?CarbonImmutable
    {
        return $this->closesAt($readyAt);
    }

    /** Конец N-го рабочего дня склада, считая с сегодняшнего, если склад сегодня работает. */
    public function endOfWorkingDay(CarbonInterface $from, int $workingDays): CarbonImmutable
    {
        $cursor = $this->local($from)->startOfDay();
        $left = max(1, $workingDays);
        $last = null;

        for ($i = 0; $i <= self::LOOKAHEAD_DAYS && $left > 0; $i++) {
            $hours = $this->hoursFor($cursor->addDays($i));
            if ($hours !== null && $hours[1]->gt($this->local($from))) {
                $last = $hours[1];
                $left--;
            }
        }

        return $last ?? $this->local($from)->addDays($workingDays)->endOfDay();
    }

    /**
     * Готовая фраза для клиента.
     *
     * @return array{promised_at: ?string, same_day: bool, text: string, deadline_text: ?string}
     */
    public function describe(CarbonInterface $at): array
    {
        $at = $this->local($at);
        $promised = $this->promisedReadyAt($at);

        if ($promised === null) {
            return ['promised_at' => null, 'same_day' => false, 'text' => 'Время сборки уточняется', 'deadline_text' => null];
        }

        $sameDay = $promised->isSameDay($at);
        $closes = $this->closesAt($promised);
        $time = $promised->format('H:i');

        $text = $sameDay
            ? "Соберём к ~{$time}"
            : 'Склад сегодня уже не успеет: соберём '.$this->dayLabel($promised, $at)." к ~{$time}";

        return [
            'promised_at' => $promised->toIso8601String(),
            'same_day' => $sameDay,
            'text' => $text,
            'deadline_text' => $closes ? ($sameDay ? 'выдача сегодня до ' : 'выдача до ').$closes->format('H:i') : null,
        ];
    }

    /** Сводка для интерфейса: часы на сегодня, открыт ли склад, отсечка. */
    public function today(CarbonInterface $at): array
    {
        $at = $this->local($at);
        $hours = $this->hoursFor($at);

        return [
            'is_open' => $this->isOpen($at),
            'opens_at' => $hours ? $hours[0]->format('H:i') : null,
            'closes_at' => $hours ? $hours[1]->format('H:i') : null,
            'cutoff_at' => $this->cutoffAt($at)?->format('H:i'),
            'sla_minutes' => $this->slaMinutes(),
            'week_text' => $this->weekText(),
            'address' => config('warehouse.pickup_address'),
            'how_to_find' => config('warehouse.pickup_how_to_find'),
            'phone' => config('warehouse.pickup_phone'),
            'promise' => $this->describe($at),
        ];
    }

    /** «пн–сб, 9:00–21:00» — для страниц и пропуска. */
    public function weekText(): string
    {
        $names = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
        $groups = [];

        foreach ($names as $iso => $name) {
            $hours = $this->week()[$iso];
            if (! is_array($hours)) {
                continue;
            }
            $label = ltrim($hours[0], '0').'–'.ltrim($hours[1], '0');
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['label'] === $label && $groups[$last]['to'] === $iso - 1) {
                $groups[$last]['to'] = $iso;
            } else {
                $groups[] = ['from' => $iso, 'to' => $iso, 'label' => $label];
            }
        }

        return implode('; ', array_map(
            fn (array $g) => ($g['from'] === $g['to'] ? $names[$g['from']] : $names[$g['from']].'–'.$names[$g['to']]).', '.$g['label'],
            $groups,
        ));
    }

    private function dayLabel(CarbonImmutable $day, CarbonImmutable $now): string
    {
        if ($day->isSameDay($now->addDay())) {
            return 'завтра';
        }

        $names = [1 => 'в понедельник', 2 => 'во вторник', 3 => 'в среду', 4 => 'в четверг', 5 => 'в пятницу', 6 => 'в субботу', 7 => 'в воскресенье'];

        return $day->diffInDays($now->startOfDay(), true) < 7
            ? $names[$day->isoWeekday()]
            : $day->format('d.m');
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->timezone());
    }
}
