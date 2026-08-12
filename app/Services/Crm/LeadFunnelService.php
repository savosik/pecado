<?php

namespace App\Services\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\CrmLeadStageTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Метрики воронки лидов (crm-28).
 *
 * Считает из журнала переходов и текущих стадий. В аналитику отгрузок
 * не лезет: лиды с отгрузками не связаны, это самостоятельная витрина.
 *
 * Четыре ловушки метрики, заложенные в расчёт:
 *
 * 1. **Средняя длительность — только по завершённым переходам.** Если считать
 *    и по тем, кто стоит на этапе сейчас, застрявшие занижают цифру: лид,
 *    который войдёт в статистику через полгода, сегодня в неё не попадает.
 * 2. **Медиана рядом со средним.** Один лид, зависший на полгода, перекашивает
 *    среднее так, что цифрой нельзя пользоваться.
 * 3. **Число наблюдений рядом с метрикой.** При двух-трёх переходах средняя
 *    длительность — шум, и по ней нельзя принимать решения.
 * 4. **Конверсия по флагам стадии, а не по последней колонке.** Иначе
 *    добавление стадии в конец молча сломало бы цифру.
 */
class LeadFunnelService
{
    /**
     * Ниже скольких наблюдений метрика считается ненадёжной.
     */
    public const MIN_OBSERVATIONS = 5;

    /**
     * Сводка воронки: сколько лидов и денег на каждой стадии + время этапа.
     *
     * @return array<string, mixed>
     */
    public function summary(User $actor, CrmScope $scope = CrmScope::DEPARTMENT, ?int $managerId = null): array
    {
        $leadIds = CrmLead::query()
            ->visibleTo($actor, $scope)
            ->when($managerId !== null, fn ($query) => $query->where('manager_id', $managerId))
            ->pluck('id');

        $stages = CrmLeadStage::query()->onBoard()->get();
        $durations = $this->stageDurations($leadIds->all());

        $counts = CrmLead::query()
            ->whereIn('id', $leadIds)
            ->groupBy('stage_id')
            ->get([
                'stage_id',
                DB::raw('COUNT(*) as leads'),
                DB::raw('COALESCE(SUM(qualified_amount), 0) as amount'),
            ])
            ->keyBy('stage_id');

        $rows = $stages->map(function (CrmLeadStage $stage) use ($counts, $durations): array {
            $row = $counts->get($stage->getKey());
            // Стадия, с которой ещё никто не уходил, длительности не имеет —
            // это не ноль часов, а «нечего считать».
            $duration = $durations[$stage->getKey()] ?? ['avg' => null, 'median' => null, 'count' => 0, 'reliable' => false];

            return [
                'stage_id' => (int) $stage->getKey(),
                'name' => $stage->name,
                'color' => $stage->color,
                'is_won' => $stage->is_won,
                'is_lost' => $stage->is_lost,
                'leads' => (int) ($row->leads ?? 0),
                'amount' => (float) ($row->amount ?? 0),
                // Метрика ниже порога наблюдений не показывается: по двум
                // переходам «средняя длительность этапа» — это шум, а решения
                // по ней принимают всерьёз.
                'avg_hours' => $duration['reliable'] ? $duration['avg'] : null,
                'median_hours' => $duration['reliable'] ? $duration['median'] : null,
                'observations' => $duration['count'],
                'reliable' => $duration['reliable'],
            ];
        })->all();

        return [
            'stages' => $rows,
            'total' => $leadIds->count(),
            'conversion' => $this->conversion($leadIds->all(), $stages),
            'min_observations' => self::MIN_OBSERVATIONS,
        ];
    }

    /**
     * Длительность этапов по завершённым переходам.
     *
     * Возврат лида на предыдущую стадию считается новым заходом, и время
     * по обоим заходам суммируется естественным образом: каждый выход
     * с этапа даёт свою запись в журнале.
     *
     * @param  list<int>  $leadIds
     * @return array<int, array{avg: int|null, median: int|null, count: int, reliable: bool}>
     */
    public function stageDurations(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        // Берём переходы, у которых известна предыдущая стадия и время на ней:
        // это и есть «завершённые» этапы. Лиды, стоящие на этапе сейчас,
        // в метрику не попадают — иначе застрявшие занижали бы её.
        $rows = CrmLeadStageTransition::query()
            ->whereIn('lead_id', $leadIds)
            ->whereNotNull('from_stage_id')
            ->whereNotNull('previous_stage_hours')
            ->get(['from_stage_id', 'previous_stage_hours']);

        $byStage = [];

        foreach ($rows as $row) {
            $byStage[(int) $row->from_stage_id][] = (int) $row->previous_stage_hours;
        }

        $result = [];

        foreach ($byStage as $stageId => $hours) {
            sort($hours);
            $count = count($hours);

            $result[$stageId] = [
                'avg' => (int) round(array_sum($hours) / $count),
                'median' => $this->median($hours),
                'count' => $count,
                'reliable' => $count >= self::MIN_OBSERVATIONS,
            ];
        }

        return $result;
    }

    /**
     * Конверсия: доля выигранных среди дошедших до конца воронки.
     *
     * Считается по флагам стадии, а не по позиции: добавление стадии в конец
     * не должно менять цифру.
     *
     * @param  list<int>  $leadIds
     * @param  \Illuminate\Support\Collection<int, CrmLeadStage>  $stages
     * @return array{won: int, lost: int, open: int, percent: float|null}
     */
    private function conversion(array $leadIds, $stages): array
    {
        $wonIds = $stages->where('is_won', true)->pluck('id')->all();
        $lostIds = $stages->where('is_lost', true)->pluck('id')->all();

        $won = $wonIds === [] ? 0 : CrmLead::query()->whereIn('id', $leadIds)->whereIn('stage_id', $wonIds)->count();
        $lost = $lostIds === [] ? 0 : CrmLead::query()->whereIn('id', $leadIds)->whereIn('stage_id', $lostIds)->count();
        $closed = $won + $lost;

        return [
            'won' => $won,
            'lost' => $lost,
            'open' => count($leadIds) - $closed,
            // Пока воронку никто не прошёл до конца, процент не считается:
            // «0 % конверсии» на пустой выборке читается как провал отдела.
            'percent' => $closed === 0 ? null : round($won / $closed * 100, 1),
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private function median(array $sorted): int
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }
}
