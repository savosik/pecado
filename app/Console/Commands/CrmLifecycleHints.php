<?php

namespace App\Console\Commands;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Подсказки по жизненному статусу клиентов.
 *
 * Команда НИЧЕГО не меняет в статусах — только предлагает переход. Статус клиента это
 * управленческое решение менеджера («договорились, что вернётся в марте»), и молчаливая
 * ночная перезапись такого решения хуже, чем его отсутствие: менеджер перестанет доверять
 * полю, которое кто-то правит за него.
 *
 * Давность отгрузок считается по бизнес-дате erp_created_at: по created_at вся
 * историческая база выглядит купленной в мае 2026 (импорт истории 1С при запуске прода).
 */
class CrmLifecycleHints extends Command
{
    protected $signature = 'crm:lifecycle-hints
        {--dry-run : Только показать, что предложила бы команда, ничего не записывая}';

    protected $description = 'Предложить смену жизненного статуса клиентам (сами статусы не меняет)';

    public function handle(): int
    {
        $sleepingAfter = (int) config('crm.lifecycle.sleeping_after_days');
        $revivedWithin = (int) config('crm.lifecycle.revived_within_days');
        $dryRun = (bool) $this->option('dry-run');

        $sleepingBefore = now()->subDays($sleepingAfter);
        $revivedAfter = now()->subDays($revivedWithin);

        $lastShipments = $this->lastShipmentDates();

        $added = 0;
        $cleared = 0;

        User::query()
            ->clients()
            ->whereNotNull('personal_manager_id')
            ->with('crmProfile')
            ->chunkById(500, function ($clients) use (
                $lastShipments, $sleepingBefore, $revivedAfter, $sleepingAfter, $revivedWithin, $dryRun, &$added, &$cleared
            ) {
                foreach ($clients as $client) {
                    $profile = $client->crmProfile;
                    // Профиля может не быть вовсе — тогда клиент читается как активный,
                    // так же, как задаёт дефолт колонки (связь HasOne Larastan видит
                    // как обязательную, отсюда игнор).
                    // @phpstan-ignore-next-line nullsafe.neverNull
                    $status = $profile?->lifecycle_status ?? ClientLifecycleStatus::ACTIVE;
                    $lastShipment = $lastShipments[$client->id] ?? null;

                    [$hint, $reason] = $this->suggest(
                        $status,
                        $lastShipment,
                        $client->created_at,
                        $sleepingBefore,
                        $revivedAfter,
                        $sleepingAfter,
                        $revivedWithin,
                    );

                    // Не трогаем профиль, если предложение не изменилось: иначе каждая
                    // ночь переписывала бы updated_at всей базе клиентов.
                    if (($profile?->lifecycle_hint?->value) === $hint?->value) {
                        continue;
                    }

                    if ($hint !== null) {
                        $added++;
                        $this->line("  {$client->name} (#{$client->id}): {$status->label()} → {$hint->label()} — {$reason}");
                    } else {
                        $cleared++;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    $profile ??= new CrmClientProfile(['user_id' => $client->id]);
                    $profile->lifecycle_hint = $hint;
                    $profile->lifecycle_hint_reason = $reason;
                    $profile->lifecycle_hint_at = $hint === null ? null : now();
                    $profile->save();
                }
            });

        $this->info("Новых подсказок: {$added}, снято устаревших: {$cleared}".($dryRun ? ' (сухой прогон)' : ''));

        return self::SUCCESS;
    }

    /**
     * Что предложить по этому клиенту.
     *
     * @return array{0: ClientLifecycleStatus|null, 1: string|null}
     */
    private function suggest(
        ClientLifecycleStatus $status,
        ?Carbon $lastShipment,
        ?Carbon $registeredAt,
        Carbon $sleepingBefore,
        Carbon $revivedAfter,
        int $sleepingAfter,
        int $revivedWithin,
    ): array {
        if (in_array($status, ClientLifecycleStatus::workingStates(), true)) {
            if ($lastShipment === null) {
                // Никогда не отгружался. Свежая регистрация это не «уснул»,
                // а «ещё не начал» — таких не трогаем.
                return $registeredAt !== null && $registeredAt->lt($sleepingBefore)
                    ? [ClientLifecycleStatus::SLEEPING, 'отгрузок не было ни разу']
                    : [null, null];
            }

            return $lastShipment->lt($sleepingBefore)
                ? [ClientLifecycleStatus::SLEEPING, "нет отгрузок {$sleepingAfter} дней"]
                : [null, null];
        }

        if ($status === ClientLifecycleStatus::SLEEPING
            && $lastShipment !== null
            && $lastShipment->gte($revivedAfter)
        ) {
            return [ClientLifecycleStatus::ACTIVE, "отгрузка за последние {$revivedWithin} дней"];
        }

        return [null, null];
    }

    /**
     * Дата последней отгрузки по каждому клиенту — одним запросом.
     *
     * @return array<int, Carbon>
     */
    private function lastShipmentDates(): array
    {
        return Shipment::query()
            ->selectRaw('user_id, MAX(erp_created_at) as last_shipment_at')
            ->whereNotNull('user_id')
            ->whereNotNull('erp_created_at')
            ->groupBy('user_id')
            ->pluck('last_shipment_at', 'user_id')
            ->map(fn ($date): Carbon => Carbon::parse((string) $date))
            ->all();
    }
}
