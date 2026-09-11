<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Первичное заполнение реестра закрепления (эпик mot-00, карточка mot-23).
 *
 * Реестр — единственное основание отнесения отгрузок к показателям работника
 * (п. 8.1). Истории закреплений в системе не было, поэтому бэкфилл переносит
 * в реестр сегодняшнюю картину: у кого партнёр числится сейчас, тот и вёл его
 * всю доступную историю.
 *
 * Это не знание, а его отсутствие, записанное явно. Клиенты перераспределялись
 * между менеджерами в августе 2026, и для месяцев до перераспределения реестр
 * покажет сегодняшнего менеджера — ровно то же, что показывала система до сих пор.
 * Разница в том, что дальше история начнёт накапливаться, а каждая запись
 * бэкфилла помечена основанием `initial` и отличима от настоящей.
 */
class PartnerAssignmentBackfiller
{
    /**
     * @return array{created: int, skipped: int}
     */
    public function run(?CarbonInterface $startsOn = null): array
    {
        $start = CarbonImmutable::instance($startsOn ?? CarbonImmutable::parse('2026-01-01'))->startOfDay();

        $existing = MotivationPartnerAssignment::query()
            ->whereNull('ends_on')
            ->pluck('user_id')
            ->map('intval')
            ->all();

        $created = 0;
        $skipped = 0;
        $rows = [];
        $now = now();

        User::query()
            ->clients()
            ->select(['id', 'personal_manager_id'])
            ->orderBy('id')
            ->chunk(500, function ($clients) use (&$rows, &$created, &$skipped, $existing, $start, $now): void {
                foreach ($clients as $client) {
                    if (in_array((int) $client->getKey(), $existing, true)) {
                        $skipped++;

                        continue;
                    }

                    $rows[] = [
                        'user_id' => (int) $client->getKey(),
                        'personal_manager_id' => $client->personal_manager_id === null
                            ? null
                            : (int) $client->personal_manager_id,
                        'starts_on' => $start->toDateString(),
                        'ends_on' => null,
                        'reason' => MotivationPartnerAssignment::REASON_INITIAL,
                        'plan_delta' => null,
                        'comment' => 'Бэкфилл текущего состояния при вводе реестра',
                        'author_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $created++;
                }
            });

        foreach (array_chunk($rows, 500) as $chunk) {
            MotivationPartnerAssignment::query()->insert($chunk);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
