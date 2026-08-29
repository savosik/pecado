<?php

namespace App\Console\Commands\Debt;

use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Listeners\SendDebtNotification;
use App\Models\CrmEmail;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Support\Debt\DebtControl;
use Illuminate\Console\Command;

/**
 * Разовое письмо тем, кто уже стоит на ступени, но письма об этой ступени
 * не получал — например, ступень посчитана до включения писем.
 *
 * Идемпотентно: письмо ищется по эпизоду (контрагент + ступень + дата),
 * повторный запуск ничего не дублирует. Под разблокировкой молчит.
 */
class NotifyCurrentDebtStates extends Command
{
    protected $signature = 'debt:notify-current {--dry-run : Показать, кому ушло бы}';

    protected $description = 'Дослать письма о текущей ступени тем, кто их не получал';

    public function handle(SendDebtNotification $listener): int
    {
        if (! DebtControl::live(DebtControl::ACTION_MAIL)) {
            $this->info('Письма лестницы долга выключены — досылать нечего.');

            return self::SUCCESS;
        }

        $states = DebtState::query()
            ->with(['user', 'company'])
            ->contractors()
            ->live()
            ->where('level', '<>', DebtLevel::CLEAN->value)
            ->orderBy('user_id')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($states as $state) {
            if ($this->alreadyTold($state) || $this->paused($state)) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '%s / %s — %s%s',
                $state->user?->display_name ?? ('#'.$state->user_id),
                $state->company?->name ?? ('#'.$state->company_id),
                $state->level->label(),
                $this->option('dry-run') ? ' (тень)' : '',
            ));

            if (! $this->option('dry-run')) {
                $listener->handle(new DebtLevelChanged($state, DebtLevel::CLEAN, $state->level));
            }

            $sent++;
        }

        $this->info(sprintf('Писем: %d, пропущено (уже было или разблокировка): %d.', $sent, $skipped));

        return self::SUCCESS;
    }

    private function alreadyTold(DebtState $state): bool
    {
        $key = sprintf(
            'finance.debt_%s:c%d:k%d:lvl%s:since%s',
            $state->level->value,
            $state->user_id,
            $state->company_id,
            $state->level->value,
            $state->since?->toDateString() ?? '',
        );

        return CrmEmail::query()->where('origin_key', $key)->exists();
    }

    private function paused(DebtState $state): bool
    {
        return DebtPause::query()
            ->active()
            ->where('user_id', $state->user_id)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $state->company_id))
            ->exists();
    }
}
