<?php

namespace App\Support\Crm;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Последний визит на сайт» в форме, готовой к показу.
 *
 * Считает бэкенд, а не JSX: иначе разбор дат и часовой пояс сервера разъехались
 * бы по трём страницам CRM и агентскому API, где формат должен совпадать.
 *
 * Состояние (`state`) отделено от подписи: цвет и иконку выбирает фронт по нему,
 * а не по разбору русского текста вроде «12 дней назад».
 */
final class LastVisit
{
    /** После скольких дней молчания визит считается давним. */
    private const STALE_DAYS = 30;

    /**
     * @return array{state: string, label: string, at: string|null, days: int|null}
     */
    public static function payload(?CarbonInterface $seenAt): array
    {
        if ($seenAt === null) {
            return [
                // Партнёр, не заходивший ни разу, — не «давно не был», а отдельный
                // случай: ему, возможно, просто не выдали доступ.
                'state' => 'never',
                'label' => 'ни разу не заходил',
                'at' => null,
                'days' => null,
            ];
        }

        $now = CarbonImmutable::now();
        $days = (int) $seenAt->copy()->startOfDay()->diffInDays($now->startOfDay());

        $label = match (true) {
            $seenAt->isToday() => 'сегодня',
            $seenAt->isYesterday() => 'вчера',
            $days < 7 => self::plural($days, 'день', 'дня', 'дней').' назад',
            $seenAt->isCurrentYear() => $seenAt->format('d.m'),
            default => $seenAt->format('d.m.Y'),
        };

        return [
            'state' => $days >= self::STALE_DAYS ? 'stale' : 'recent',
            'label' => $label,
            'at' => $seenAt->format('d.m.Y H:i'),
            'days' => $days,
        ];
    }

    /**
     * Русское склонение с числом: «1 день», «3 дня», «5 дней».
     */
    private static function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        $form = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return $count.' '.$form;
    }
}
