<?php

namespace App\Console\Commands;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\ClientLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Возвращение партнёра в работу: за ушедшим снова пошла активность.
 *
 * Соседняя команда `crm:lifecycle-hints` статусы только предлагает — и это
 * верно для перехода «активен → спящий»: там решение управленческое («договорились,
 * что вернётся в марте»). Здесь случай обратный и спорить не с чем: партнёр,
 * которого записали в ушедшие, оформил заказ. Стадия «Ушёл» в этот момент уже
 * не мнение менеджера, а просто устаревшая запись, и держать её до тех пор, пока
 * кто-то заметит, значит показывать в отчётах неправду.
 *
 * Активностью считается заказ **или** отгрузка: заказа достаточно, ждать отгрузки
 * незачем — партнёр уже вернулся, а до отгрузки может пройти месяц.
 *
 * Два ограничения, без которых команда поднимала бы всех подряд:
 *
 * 1. Активность должна быть **позже** даты, когда партнёра перевели в нынешнюю
 *    стадию. Иначе прошлогодний заказ, лежащий в базе, «возвращал» бы ушедшего
 *    каждую ночь. Там, где дату смены не проставляли (стадия приехала импортом),
 *    работает окно `revive_window_days`.
 * 2. «Банкрот» не поднимается (см. config/crm.php → lifecycle.revive_from):
 *    за ним списанный долг и закрытая карточка в 1С. Такие случаи команда
 *    показывает отдельно и пишет в лог — это разбор, а не автоматика.
 */
class CrmLifecycleRevive extends Command
{
    protected $signature = 'crm:lifecycle-revive
        {--dry-run : Только показать, кого команда вернула бы, ничего не записывая}';

    protected $description = 'Вернуть в «Активен» партнёров, за которыми снова пошли заказы или отгрузки';

    public function __construct(private readonly ClientLifecycleService $lifecycle)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var list<string> $reviveFrom */
        $reviveFrom = (array) config('crm.lifecycle.revive_from', []);
        $stages = array_values(array_filter(array_map(
            static fn (string $value): ?ClientLifecycleStatus => ClientLifecycleStatus::tryFrom($value),
            $reviveFrom,
        )));

        if ($stages === []) {
            $this->warn('В config/crm.php не задано ни одной стадии для возвращения — делать нечего.');

            return self::SUCCESS;
        }

        $window = now()->subDays(max(1, (int) config('crm.lifecycle.revive_window_days', 60)));

        $lastActivity = $this->lastActivityDates();

        $revived = 0;
        $attention = [];

        User::query()
            ->clients()
            ->whereNotNull('personal_manager_id')
            ->whereHas('crmProfile', fn ($q) => $q->whereIn(
                'lifecycle_status',
                array_column(array_merge($stages, [ClientLifecycleStatus::BANKRUPT]), 'value'),
            ))
            ->with('crmProfile')
            ->chunkById(500, function ($clients) use ($stages, $window, $lastActivity, $dryRun, &$revived, &$attention) {
                foreach ($clients as $client) {
                    /** @var CrmClientProfile|null $profile */
                    $profile = $client->crmProfile;

                    if ($profile === null) {
                        continue;
                    }

                    $activity = $lastActivity[(int) $client->getKey()] ?? null;

                    if ($activity === null) {
                        continue;
                    }

                    // «Последовала за»: активность после того, как партнёра
                    // перевели в нынешнюю стадию. Даты нет — судим по окну.
                    $since = $profile->lifecycle_changed_at ?? $window;

                    if ($activity['at']->lte($since)) {
                        continue;
                    }

                    $status = $profile->lifecycle_status;

                    if (! in_array($status, $stages, true)) {
                        // Сюда попадает «Банкрот»: активность есть, а поднимать
                        // его автоматически нельзя.
                        $attention[] = [$client, $status, $activity];

                        continue;
                    }

                    $reason = 'вернулся: '.$activity['what'];

                    $this->line("  {$client->display_name} (#{$client->id}): {$status->label()} → Активен — {$reason}");
                    $revived++;

                    if ($dryRun) {
                        continue;
                    }

                    $this->lifecycle->changeBySystem($client, ClientLifecycleStatus::ACTIVE, $reason);
                }
            });

        $this->report($revived, $attention, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: User, 1: ClientLifecycleStatus, 2: array{at: Carbon, what: string}}>  $attention
     */
    private function report(int $revived, array $attention, bool $dryRun): void
    {
        $this->info("Возвращено в работу: {$revived}".($dryRun ? ' (сухой прогон)' : ''));

        if ($attention === []) {
            return;
        }

        $this->newLine();
        $this->warn('Требуют решения — активность есть, но стадия автоматически не меняется:');

        foreach ($attention as [$client, $status, $activity]) {
            $line = "  {$client->display_name} (#{$client->id}): {$status->label()} — {$activity['what']}";
            $this->line($line);

            // В лог — чтобы случай не потерялся вместе с выводом ночного крона.
            Log::warning('crm:lifecycle-revive: активность у партнёра в стадии «'.$status->label().'»', [
                'client_id' => $client->getKey(),
                'client' => $client->display_name,
                'stage' => $status->value,
                'activity' => $activity['what'],
            ]);
        }
    }

    /**
     * Последняя активность по каждому партнёру: заказ или отгрузка, что свежее.
     *
     * Даты — бизнес-даты 1С (`erp_created_at`); у заказа, оформленного на сайте
     * и ещё не уехавшего в 1С, её нет, поэтому там подставляется `created_at`.
     * Для отгрузок такой подстановки нет намеренно: всю историю импортировали
     * в мае 2026, и `created_at` у неё — дата импорта, а не документа.
     *
     * @return array<int, array{at: Carbon, what: string}>
     */
    private function lastActivityDates(): array
    {
        $orders = Order::query()
            ->selectRaw('user_id, MAX(COALESCE(erp_created_at, created_at)) as last_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $shipments = Shipment::query()
            ->selectRaw('user_id, MAX(erp_created_at) as last_at')
            ->whereNotNull('user_id')
            ->whereNotNull('erp_created_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id');

        $activity = [];

        foreach ($orders as $userId => $date) {
            $activity[(int) $userId] = [
                'at' => Carbon::parse((string) $date),
                'what' => 'заказ от '.Carbon::parse((string) $date)->format('d.m.Y'),
            ];
        }

        foreach ($shipments as $userId => $date) {
            $at = Carbon::parse((string) $date);
            $current = $activity[(int) $userId] ?? null;

            if ($current === null || $at->gt($current['at'])) {
                $activity[(int) $userId] = [
                    'at' => $at,
                    'what' => 'отгрузка от '.$at->format('d.m.Y'),
                ];
            }
        }

        return $activity;
    }
}
