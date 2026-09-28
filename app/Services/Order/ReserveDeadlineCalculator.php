<?php

namespace App\Services\Order;

use App\Services\Crm\TimesheetService;
use Carbon\CarbonImmutable;

/**
 * Срок резерва в рабочих днях клиента (res-12, протокол v16.12.1, топик №11 Agent Hub).
 *
 * Клиенты-интернетчики работают пн–пт: резерв, оставленный в пятницу вечером, при
 * календарном окне сгорал в субботу, когда подтвердить его некому. Правило владельца
 * сайта: нерабочие дни клиента окно не расходуют — за каждый такой день в интервале
 * (дата создания; дедлайн] дедлайн сдвигается на целые сутки, час сохраняется, дедлайн
 * всегда в рабочий день. Нерабочий день — сб, вс и праздник производственного календаря
 * ({@see TimesheetService::isWorkingDay()}, рабочие субботы по переносу учтены там же).
 *
 * Два ограничения поверх формулы:
 * - потолок `reserved_until − date <= предел 1С`: расчёт дальше потолка (бывает только
 *   при индивидуальном окне РОПа) срезается назад на последний рабочий день тем же часом —
 *   простой min уронил бы дедлайн в выходной;
 * - через новогодние каникулы резерв не берём (решение владельца 27.09.2026): «встретимся
 *   после праздников», товар неделями не держим.
 *
 * Null — резерв сейчас не предлагается; причину даёт {@see blockReason()}.
 */
class ReserveDeadlineCalculator
{
    public const REASON_NEW_YEAR = 'new_year';

    public const REASON_NO_WORKING_DAY = 'no_working_day';

    public function __construct(private readonly TimesheetService $calendar) {}

    public function deadline(CarbonImmutable $createdAt, int $windowHours, int $limitHours): ?CarbonImmutable
    {
        return $this->evaluate($createdAt, $windowHours, $limitHours)['deadline'];
    }

    /** Почему резерв от этого момента не предлагается; null — предлагается. */
    public function blockReason(CarbonImmutable $createdAt, int $windowHours, int $limitHours): ?string
    {
        return $this->evaluate($createdAt, $windowHours, $limitHours)['reason'];
    }

    /**
     * @return array{deadline: ?CarbonImmutable, reason: ?string}
     */
    private function evaluate(CarbonImmutable $createdAt, int $windowHours, int $limitHours): array
    {
        $deadline = $this->shiftPastNonWorkingDays($createdAt, $createdAt->addHours($windowHours));
        // Каникулы проверяются по полному расчёту: срез по потолку не должен превращать
        // «через Новый год не берём» в укороченный резерв до 30 декабря.
        if ($this->touchesNewYearHolidays($createdAt, $deadline)) {
            return ['deadline' => null, 'reason' => self::REASON_NEW_YEAR];
        }

        $ceiling = $createdAt->addHours($limitHours);

        if ($deadline->gt($ceiling)) {
            $deadline = $this->latestWorkingDayUnder($createdAt, $ceiling);

            if ($deadline === null) {
                return ['deadline' => null, 'reason' => self::REASON_NO_WORKING_DAY];
            }
        }

        return ['deadline' => $deadline, 'reason' => null];
    }

    /**
     * Сдвиг на сутки за каждый нерабочий день в (дата создания; дедлайн]; сдвиг может
     * открыть новые нерабочие дни, поэтому повторяем, пока учитывать нечего.
     */
    private function shiftPastNonWorkingDays(CarbonImmutable $createdAt, CarbonImmutable $deadline): CarbonImmutable
    {
        $counted = [];

        while (true) {
            $fresh = 0;

            foreach ($this->daysAfter($createdAt, $deadline) as $day) {
                $key = $day->toDateString();

                if (! isset($counted[$key]) && ! $this->calendar->isWorkingDay($day)) {
                    $counted[$key] = true;
                    $fresh++;
                }
            }

            if ($fresh === 0) {
                return $deadline;
            }

            $deadline = $deadline->addDays($fresh);
        }
    }

    /** Последний рабочий день клиента, в который час создания ещё не выходит за потолок. */
    private function latestWorkingDayUnder(CarbonImmutable $createdAt, CarbonImmutable $ceiling): ?CarbonImmutable
    {
        $candidate = $createdAt->addDays($createdAt->startOfDay()->diffInDays($ceiling->startOfDay()));

        for (; $candidate->toDateString() > $createdAt->toDateString(); $candidate = $candidate->subDay()) {
            if ($candidate->lte($ceiling) && $this->calendar->isWorkingDay($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function touchesNewYearHolidays(CarbonImmutable $createdAt, CarbonImmutable $deadline): bool
    {
        foreach ($this->daysAfter($createdAt, $deadline) as $day) {
            if ($this->isNewYearHoliday($day)) {
                return true;
            }
        }

        return false;
    }

    /**
     * День новогодних каникул — нерабочий день, от которого до ближайшего 1 января
     * тянется непрерывная цепочка нерабочих дней (31.12 перенесённый, 1–8 января,
     * примыкающие выходные).
     */
    private function isNewYearHoliday(CarbonImmutable $day): bool
    {
        $day = $day->startOfDay();
        $newYear = $day->month === 12 ? $day->addYear()->startOfYear() : $day->startOfYear();

        if ($day->month !== 12 && $day->month !== 1) {
            return false;
        }

        $step = $day->lt($newYear) ? 1 : -1;

        for ($cursor = $day; ; $cursor = $cursor->addDays($step)) {
            if ($this->calendar->isWorkingDay($cursor)) {
                return false;
            }

            if ($cursor->equalTo($newYear)) {
                return true;
            }
        }
    }

    /**
     * Календарные дни интервала (дата создания; дата дедлайна].
     *
     * @return iterable<CarbonImmutable>
     */
    private function daysAfter(CarbonImmutable $createdAt, CarbonImmutable $deadline): iterable
    {
        $last = $deadline->toDateString();

        for ($day = $createdAt->startOfDay()->addDay(); $day->toDateString() <= $last; $day = $day->addDay()) {
            yield $day;
        }
    }
}
