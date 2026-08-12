<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\CrmLeadStageTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Лиды: заведение, перемещение по воронке, конверсия.
 *
 * Всякое перемещение проходит через {@see moveToStage()} и пишет журнал —
 * включая первое попадание в воронку. Без журнала метрики этапов (crm-28)
 * считать нечего: «сколько дней лид на этапе» задним числом
 * не восстанавливается.
 */
class CrmLeadService
{
    /**
     * Завести лида и поставить его на первую стадию воронки.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CrmLead
    {
        return DB::transaction(function () use ($data, $actor): CrmLead {
            $stage = isset($data['stage_id'])
                ? CrmLeadStage::query()->find($data['stage_id'])
                : CrmLeadStage::first();

            $lead = CrmLead::create([
                ...$data,
                // Лид без ответственного — не ошибка: его заводят с выставки
                // пачкой, а разбирают потом. Но если менеджер завёл его себе,
                // подставляем его карточку.
                'manager_id' => $data['manager_id'] ?? $actor->managerProfile?->id,
                'stage_id' => $stage?->getKey(),
                'stage_changed_at' => Carbon::now(),
            ]);

            if ($stage !== null) {
                $this->recordTransition($lead, null, $stage, $actor, null);
            }

            return $lead;
        });
    }

    /**
     * Передвинуть лида на другую стадию.
     *
     * Возвращает false, если стадия не изменилась: перетаскивание карточки
     * обратно в ту же колонку не должно засорять журнал и обнулять счётчик
     * дней на этапе.
     */
    public function moveToStage(CrmLead $lead, CrmLeadStage $stage, User $actor): bool
    {
        if ((int) $lead->stage_id === (int) $stage->getKey()) {
            return false;
        }

        return DB::transaction(function () use ($lead, $stage, $actor): bool {
            $from = $lead->stage;
            $hours = $lead->stage_changed_at === null
                ? null
                : (int) $lead->stage_changed_at->diffInHours(Carbon::now());

            $lead->forceFill([
                'stage_id' => $stage->getKey(),
                'stage_changed_at' => Carbon::now(),
            ])->save();

            $this->recordTransition($lead, $from, $stage, $actor, $hours);

            return true;
        });
    }

    /**
     * Привязать лида к появившемуся партнёру.
     *
     * Партнёра создаёт 1С, поэтому «квалифицировать в клиента» — это указать,
     * какой именно партнёр из шины и есть этот лид, а не создать запись
     * в `users` из CRM.
     */
    public function convert(CrmLead $lead, User $client, User $actor): CrmLead
    {
        $wonStage = CrmLeadStage::query()->where('is_won', true)->orderBy('position')->first();

        DB::transaction(function () use ($lead, $client, $actor, $wonStage): void {
            $lead->forceFill(['converted_user_id' => $client->getKey()])->save();

            if ($wonStage !== null) {
                $this->moveToStage($lead, $wonStage, $actor);
            }
        });

        return $lead->fresh();
    }

    /**
     * Записать переход.
     *
     * Длительность предыдущего этапа считается здесь, а не при чтении: искать
     * парную запись в истории на каждый показ дороже, чем записать один раз.
     */
    private function recordTransition(
        CrmLead $lead,
        ?CrmLeadStage $from,
        ?CrmLeadStage $to,
        User $actor,
        ?int $previousStageHours,
    ): CrmLeadStageTransition {
        return CrmLeadStageTransition::create([
            'lead_id' => $lead->getKey(),
            'from_stage_id' => $from?->getKey(),
            'to_stage_id' => $to?->getKey(),
            'user_id' => $actor->getKey(),
            'moved_at' => Carbon::now(),
            'previous_stage_hours' => $previousStageHours,
        ]);
    }
}
