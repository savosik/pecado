<?php

namespace App\Console\Commands;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\PlanProgressService;
use App\Services\Crm\PlanScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Прогрев кэша выполнения планов продаж (/crm/plans, вкладка «Выполнение»).
 *
 * Факт, burndown и разрез по менеджерам считаются тяжёлыми агрегатами по всем
 * отгрузкам месяца; кэш живёт пять минут свежим и час в режиме
 * stale-while-revalidate. Без прогрева самый первый посетитель утром всё равно
 * ловил бы холодный пересчёт синхронно — команда снимает и этот случай:
 * по расписанию она пересчитывает те же ключи, что строит страница.
 *
 * Актор — первый пользователь с правом видеть весь отдел: ключи кэша зависят
 * только от состава партнёров скоупа, а не от личности актора, поэтому прогрев
 * под одним руководителем греет страницу всем, кто видит те же скоупы.
 */
class WarmCrmPlansCache extends Command
{
    protected $signature = 'crm:plans-warm';

    protected $description = 'Прогрев кэша выполнения планов продаж: факт, burndown и разрез по менеджерам для отдела и каждого активного менеджера';

    public function handle(PlanProgressService $progress, PlanScopeResolver $scopes): int
    {
        /** @var User|null $actor */
        $actor = User::permission('crm-clients-all.view')->first();

        if ($actor === null) {
            $this->info('Нет пользователя с правом crm-clients-all.view — прогревать не для кого.');

            return self::SUCCESS;
        }

        $month = CarbonImmutable::now()->startOfMonth();

        $department = $scopes->department($actor);
        $progress->progress($month, $department);
        $progress->burndown($month, $department);
        $progress->clients($month, $department, $actor);
        $progress->byManager($month, $actor);

        $warmed = 1;

        foreach (PersonalManager::query()->active()->pluck('id') as $managerId) {
            $scope = $scopes->resolve($actor, 'manager', (int) $managerId);

            if ($scope->isEmpty()) {
                continue;
            }

            $progress->progress($month, $scope);
            $progress->burndown($month, $scope);
            $progress->clients($month, $scope, $actor);
            $warmed++;
        }

        $this->info("Прогрето скоупов: {$warmed} (месяц {$month->format('Y-m')}).");

        return self::SUCCESS;
    }
}
