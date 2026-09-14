<?php

namespace App\Services\Crm\TaxRegime;

use App\Enums\Crm\TaskStatus;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Задачи менеджерам «уточнить налоговый режим» — механизм, который не даёт
 * ответам устаревать.
 *
 * Одна задача на партнёра, а не на юрлицо: звонят партнёру и спрашивают про все
 * его юрлица сразу. Ставится персональному менеджеру, только по покупающим
 * юрлицам без актуального ответа ({@see TaxRegimeQuery::pending()}). Задача
 * закрывается сама, когда актуальных ответов хватает: висеть после того, как
 * работа сделана, она не должна, иначе менеджеры привыкнут их не замечать.
 */
class TaxRegimeTaskPlanner
{
    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly TaxRegimeQuery $query,
    ) {}

    public static function tag(): string
    {
        return (string) config('crm_tax_regime.tasks.tag');
    }

    /**
     * @return array{created: int, closed: int}
     */
    public function run(): array
    {
        // Сначала закрываем: партнёр, по которому всё ответили, не должен получить
        // новую задачу из-за старой, которую просто не успели закрыть.
        $closed = $this->closeSettled();

        return ['created' => $this->createMissing(), 'closed' => $closed];
    }

    /**
     * Закрыть открытые задачи партнёров, у которых не осталось юрлиц без ответа.
     *
     * @param  User|null  $actor  кто закрывает; по умолчанию — ответственный задачи
     */
    public function closeSettled(?int $partnerId = null, ?User $actor = null): int
    {
        $tasks = $this->openTasks($partnerId)->with('assignee')->get();
        $closed = 0;

        foreach ($tasks->groupBy('client_user_id') as $clientId => $group) {
            if ($this->query->pending((int) $clientId)->exists()) {
                continue;
            }

            foreach ($group as $task) {
                $this->tasks->close(
                    $task,
                    $actor ?? $task->assignee,
                    'Налоговый режим указан по всем покупающим юрлицам партнёра — задача закрыта автоматически.',
                );
                $closed++;
            }
        }

        return $closed;
    }

    private function createMissing(): int
    {
        /** @var Collection<int|string, Collection<int, Company>> $pending */
        $pending = $this->query->pending()
            ->with('taxRegime')
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.user_id', 'companies.name', 'companies.legal_name', 'companies.tax_id'])
            ->groupBy('user_id');

        if ($pending->isEmpty()) {
            return 0;
        }

        $year = CrmContractorTaxRegime::targetYear();
        $created = 0;

        User::query()
            ->clients()
            ->whereIn('users.id', $pending->keys()->all())
            ->whereNotNull('personal_manager_id')
            ->with('personalManager.user')
            ->chunkById(200, function (Collection $partners) use ($pending, $year, &$created): void {
                foreach ($partners as $partner) {
                    $manager = $partner->personalManager?->user;

                    // Карточка менеджера без аккаунта в CRM — поручение «никому».
                    if (! $manager instanceof User || ! $manager->hasCrmAccess()) {
                        continue;
                    }

                    if ($this->openTasks((int) $partner->getKey())->exists()) {
                        continue;
                    }

                    $this->tasks->create($manager, [
                        'title' => "Налоговый режим на {$year} год: уточнить у партнёра",
                        'description' => $this->description($pending->get($partner->getKey()), $year),
                        'assignee_id' => $manager->getKey(),
                        'due_at' => now()->addWeekdays((int) config('crm_tax_regime.tasks.due_weekdays'))
                            ->setTime(18, 0)
                            ->toDateTimeString(),
                        'tags' => [self::tag()],
                    ], $partner);

                    $created++;
                }
            });

        return $created;
    }

    /**
     * @return Builder<CrmTask>
     */
    private function openTasks(?int $partnerId): Builder
    {
        return CrmTask::query()
            ->whereNotNull('client_user_id')
            ->when($partnerId !== null, fn (Builder $q) => $q->where('client_user_id', $partnerId))
            ->whereIn('status', TaskStatus::activeValues())
            ->withAnyTags([self::tag()], CrmTask::TAG_TYPE);
    }

    /**
     * @param  Collection<int, Company>  $contractors
     */
    private function description(Collection $contractors, int $year): string
    {
        $lines = $contractors->map(function (Company $company): string {
            $name = $company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey();
            $inn = $company->tax_id ? ', ИНН '.$company->tax_id : '';
            $regime = $company->taxRegime;

            $state = match (true) {
                $regime === null || ! $regime->isFilled() => 'не заполнено',
                default => 'ответ от '.$regime->confirmed_at?->format('d.m.Y').' устарел — подтвердите или обновите',
            };

            return "• {$name}{$inn} — {$state}";
        })->implode("\n");

        return "Готовим оценку рисков потери объёма из-за перехода клиентов на НДС.\n\n"
            .'Узнайте у партнёра по каждому юрлицу, на какой системе налогообложения оно работает сейчас '
            ."и на какой будет работать в {$year} году. Отметьте ответ в карточке партнёра, вкладка «Контрагенты», "
            ."блок «Налоговый режим».\n\n"
            ."Юрлица:\n{$lines}\n\n"
            .'Задача закроется сама, когда ответы по всем покупающим юрлицам станут актуальными.';
    }
}
