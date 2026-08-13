<?php

namespace App\Console\Commands;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmLead;
use App\Models\CrmTask;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Задачи по залежавшимся лидам.
 *
 * Красный бейдж на карточке видит только тот, кто открыл доску, а лид тем и
 * плох, что про него забывают. Команда превращает простой в задачу — то есть
 * в то, что уже само попадёт менеджеру в список дел и в утреннее напоминание.
 *
 * Адресуется по данным, а не по ролям: исполнитель — учётка менеджера, за
 * которым лид закреплён. Ничей лид пропускается намеренно, назначать его
 * «кому-нибудь из руководителей» означало бы гадать — он и так виден всему
 * отделу и отбирается фильтром «залежались» в таблице.
 */
class CrmLeadsRemindStale extends Command
{
    protected $signature = 'crm:leads-remind-stale
        {--days= : Со скольких дней простоя напоминать (по умолчанию порог раздела)}
        {--dry-run : Показать, что было бы создано, ничего не записывая}';

    protected $description = 'Поставить задачи по лидам, застрявшим на стадии';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: CrmLead::STALE_DAYS);
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $orphans = 0;
        $withoutAccount = 0;

        CrmLead::query()
            ->stagnant($days)
            // Напоминаем заново, если после прошлого напоминания лид двигался
            // и снова встал: stage_changed_at обновляется переносом, отметка — нет.
            ->where(fn ($query) => $query
                ->whereNull('stale_reminded_at')
                ->orWhereColumn('stale_reminded_at', '<=', 'stage_changed_at'))
            ->with(['manager.user:id,name', 'stage:id,name'])
            ->chunkById(200, function ($leads) use ($dryRun, &$created, &$orphans, &$withoutAccount): void {
                foreach ($leads as $lead) {
                    if ($lead->manager === null) {
                        $orphans++;

                        continue;
                    }

                    $assignee = $lead->manager->user;

                    // У карточки менеджера может не быть учётки — задача повисла бы
                    // без исполнителя, а такой в списке дел никто не увидит.
                    if ($assignee === null) {
                        $withoutAccount++;

                        continue;
                    }

                    $created++;

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  %s — %s дн. на стадии «%s» → %s',
                            $lead->name,
                            $lead->daysOnStage(),
                            // stage_id nullable, поэтому связь бывает пустой —
                            // Larastan считает BelongsTo обязательной, отсюда игнор.
                            // @phpstan-ignore-next-line nullsafe.neverNull
                            $lead->stage?->name ?? 'без стадии',
                            $assignee->name,
                        ));

                        continue;
                    }

                    CrmTask::create([
                        'title' => 'Лид без движения: '.$lead->name,
                        'description' => sprintf(
                            "Лид стоит на стадии «%s» уже %s дн. Свяжитесь и передвиньте его по воронке или закройте как проигранный.\n\nКонтакт: %s",
                            // @phpstan-ignore-next-line nullsafe.neverNull
                            $lead->stage?->name ?? 'без стадии',
                            $lead->daysOnStage(),
                            $lead->primaryContact() ?: 'не указан',
                        ),
                        'author_id' => $assignee->getKey(),
                        'assignee_id' => $assignee->getKey(),
                        'related_type' => CrmLead::class,
                        'related_id' => $lead->getKey(),
                        'status' => TaskStatus::OPEN,
                        'priority' => TaskPriority::NORMAL,
                        'due_at' => Carbon::now()->addDay()->setTime(12, 0),
                    ]);

                    $lead->forceFill(['stale_reminded_at' => Carbon::now()])->save();
                }
            });

        $this->info(sprintf(
            '%s: %d — лиды без движения от %d дн.',
            $dryRun ? 'Создалось бы задач' : 'Создано задач',
            $created,
            $days,
        ));

        if ($orphans > 0) {
            $this->warn("Ничьих залежавшихся лидов: {$orphans} — их разбирают с доски, задачу ставить некому.");
        }

        if ($withoutAccount > 0) {
            $this->warn("Лидов у менеджеров без учётной записи: {$withoutAccount}.");
        }

        return self::SUCCESS;
    }
}
